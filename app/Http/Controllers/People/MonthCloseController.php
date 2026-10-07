<?php

namespace App\Http\Controllers\People;

use App\Domain\People\MonthCloser;
use App\Domain\People\PeopleAccess;
use App\Domain\People\PeopleNotifier;
use App\Domain\People\Reports\RegisterFiles;
use App\Enums\MonthCloseStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\People\Concerns\HandlesRegisterFiles;
use App\Models\MonthClose;
use App\Models\User;
use App\Notifications\People\MonthCloseReminder;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Cierres mensuales (PLAN-FASE-11 §6.2.4, §6.3.6 y §7.5; D-347; W-084, W-085 y W-114):
 *
 * - `/personas/cierres?mes=AAAA-MM` (responsables, su departamento; RR. HH., todos): el estado del
 *   cierre de cada persona (confirmado, pendiente, en desacuerdo, desconfirmado o sin generar),
 *   generar los que falten, desconfirmar con motivo y recordar,
 * - confirmar o no estar de acuerdo: solo la persona (desde «Mi registro»),
 * - el PDF guardado de un cierre: la persona, su responsable y RR. HH.; cada descarga queda anotada.
 */
class MonthCloseController extends Controller
{
    use HandlesRegisterFiles;

    public function __construct(private readonly MonthCloser $closer) {}

    public function index(Request $request): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $month = $this->monthFrom($request, LocalTime::today()->startOfMonth()->subMonth());
        $department = $request->query('departamento');
        $departmentId = is_string($department) && ctype_digit($department) && PeopleAccess::departments($viewer)->contains('id', (int) $department) ? (int) $department : null;

        $people = PeopleAccess::teamQuery($viewer, $departmentId)->with('department:id,name')->orderBy('name')->get();
        $closes = MonthClose::query()
            ->with('reopener:id,name')
            ->whereIn('user_id', $people->modelKeys())
            ->where('month', $month->toDateString())
            ->orderByDesc('version')
            ->get()
            ->groupBy('user_id');

        $isPast = $month->format('Y-m') < LocalTime::today()->format('Y-m');
        $counts = ['confirmed' => 0, 'pending' => 0, 'disagreed' => 0, 'reopened' => 0, 'missing' => 0];
        $rows = [];

        foreach ($people as $person) {
            /** @var MonthClose|null $latest */
            $latest = ($closes->get($person->id) ?? collect())->first();
            $state = $latest === null ? 'missing' : ($latest->status === MonthCloseStatus::Superseded ? 'pending' : $latest->status->value);
            $counts[$state]++;
            $decides = PeopleAccess::decidesFor($viewer, $person);

            $rows[] = [
                'user' => ['id' => $person->id, 'name' => $person->name, 'department' => $person->department?->name],
                'state' => $state,
                'close' => $latest === null ? null : RegisterController::close($latest, $viewer),
                'versions' => ($closes->get($person->id) ?? collect())->count(),
                'can' => [
                    'generate' => $isPast && $decides && ($latest === null || in_array($latest->status, [MonthCloseStatus::Reopened, MonthCloseStatus::Pending, MonthCloseStatus::Disagreed], true)),
                    'reopen' => $decides && $latest?->status === MonthCloseStatus::Confirmed,
                    'remind' => $decides && $latest?->status === MonthCloseStatus::Pending,
                ],
            ];
        }

        return Inertia::render('people/closes', [
            'month' => $month->format('Y-m'),
            'current_month' => LocalTime::today()->format('Y-m'),
            'rows' => $rows,
            'counts' => $counts,
            'departments' => PeopleAccess::departments($viewer)->map(fn ($department): array => ['id' => $department->id, 'name' => $department->name])->values()->all(),
            'department_id' => $departmentId,
            'manages_all' => PeopleAccess::managesAll($viewer),
        ]);
    }

    /** POST /personas/cierres/generar {mes, user_ids?}: genera (o regenera) los cierres del mes. */
    public function generate(Request $request): RedirectResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'user_ids' => ['nullable', 'array', 'max:200'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        abort_if($month === null, 422);

        $query = PeopleAccess::teamQuery($viewer)->whereKeyNot($viewer->id);

        if (! empty($data['user_ids'])) {
            $query->whereKey($data['user_ids']);
        }

        $count = 0;

        foreach ($query->get() as $person) {
            $current = MonthClose::query()->current()->where('user_id', $person->id)->where('month', $month->toDateString())->first();

            if ($current?->status === MonthCloseStatus::Confirmed) {
                continue;
            }

            $this->closer->generate($person, $month, $viewer);
            $count++;
        }

        $this->toast(trans_choice('people.flash.close_generated', max($count, 1), ['count' => $count]));

        return back();
    }

    public function confirm(Request $request, MonthClose $close): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->closer->confirm($actor, $close);
        $this->toast(__('people.flash.close_confirmed'));

        return back();
    }

    public function disagree(Request $request, MonthClose $close): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']], [
            'note.required' => __('people.errors.close_note_required'),
            'note.min' => __('people.errors.close_note_required'),
        ])['note'];

        $this->closer->disagree($actor, $close, $note);
        $this->toast(__('people.flash.close_disagreed'));

        return back();
    }

    public function reopen(Request $request, MonthClose $close): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], [
            'reason.required' => __('people.errors.reopen_reason_required'),
            'reason.min' => __('people.errors.reopen_reason_required'),
        ])['reason'];

        $this->closer->reopen($actor, $close, $reason);
        $this->toast(__('people.flash.close_reopened'));

        return back();
    }

    public function remind(Request $request, MonthClose $close): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $subject = User::query()->findOrFail($close->user_id);
        abort_unless(PeopleAccess::decidesFor($actor, $subject) && $close->status === MonthCloseStatus::Pending, 403);

        PeopleNotifier::send([$subject], new MonthCloseReminder($close));
        activity(MonthCloser::LOG)->causedBy($actor)->performedOn($close)->event('reminded')->log('month_close.reminded');
        $this->toast(__('people.flash.close_reminded'));

        return back();
    }

    /** GET /personas/cierres/{cierre}/pdf: el PDF guardado, tal cual (su huella no cambia). */
    public function pdf(Request $request, MonthClose $close, RegisterFiles $files): HttpResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $subject = User::query()->findOrFail($close->user_id);
        abort_unless(PeopleAccess::seesRegisterOf($viewer, $subject), 403);

        $contents = $this->closer->pdfContents($close);
        abort_if($contents === null, 404);

        $extension = pathinfo($close->pdf_path, PATHINFO_EXTENSION);
        $filename = sprintf('resumen-registro-jornada-%s-v%d.%s', $close->monthKey(), $close->version, $extension);
        $files->record('month_close', $extension, ['users' => [$subject->id], 'month' => $close->monthKey(), 'version' => $close->version], $filename, $close->pdf_sha256, $close->content_hash, strlen($contents), $viewer);

        return response($contents, 200, [
            'Content-Type' => $extension === 'pdf' ? 'application/pdf' : 'text/html; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => $close->pdf_sha256,
        ]);
    }
}
