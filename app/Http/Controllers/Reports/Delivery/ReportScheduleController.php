<?php

namespace App\Http\Controllers\Reports\Delivery;

use App\Domain\Reports\Delivery\DeliveryAudit;
use App\Domain\Reports\Delivery\PauseReason;
use App\Domain\Reports\Delivery\ReportAccess;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ScheduleClock;
use App\Domain\Reports\Delivery\SchedulePauser;
use App\Domain\Reports\Delivery\ScheduleRunner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportScheduleRequest;
use App\Http\Resources\Reports\ReportScheduleData;
use App\Models\Department;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Envíos programados de informes (/informes/envios, D-141). Cada persona ve y gestiona los suyos;
 * el admin, todos (ReportSchedulePolicy). Al crear, editar o reanudar se comprueba que el
 * propietario puede ver el informe ahora (403 al crear o editar; al reanudar, un aviso). Cada
 * envío se genera después con sus permisos de ese momento (ScheduleRunner).
 */
class ReportScheduleController extends Controller
{
    use AuthorizesRequests;

    /** Programaciones que lista la página (las más próximas primero). */
    public const int LIST_LIMIT = 200;

    /** Envíos del historial en el detalle. */
    public const int HISTORY_LIMIT = 50;

    public function __construct(
        private readonly ReportAccess $access,
        private readonly ScheduleClock $clock,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReportSchedule::class);

        /** @var User $user */
        $user = $request->user();
        $all = $user->can('viewAll', ReportSchedule::class);

        $schedules = ReportSchedule::query()
            ->when(! $all, fn (Builder $query) => $query->where('owner_user_id', $user->id))
            ->with('owner:id,name')
            ->orderByDesc('is_active')
            ->orderByRaw('next_run_at IS NULL')
            ->orderBy('next_run_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $last = ReportDelivery::query()
            ->whereIn('id', ReportDelivery::query()->selectRaw('MAX(id)')->whereIn('schedule_id', $schedules->modelKeys())->groupBy('schedule_id'))
            ->get(['id', 'schedule_id', 'status'])
            ->keyBy('schedule_id');

        return Inertia::render('reports/schedules/index', [
            'schedules' => $schedules->map(fn (ReportSchedule $schedule): array => ReportScheduleData::row($schedule, $last->get($schedule->id)))->values()->all(),
            'sees_all' => $all,
            'new_reports' => $this->newReports($user),
        ]);
    }

    public function show(ReportSchedule $schedule): Response
    {
        $this->authorize('view', $schedule);

        $schedule->load('owner:id,name');
        $deliveries = $schedule->deliveries()->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get()->all();

        return Inertia::render('reports/schedules/show', [
            'schedule' => ReportScheduleData::detail($schedule, array_values($deliveries)),
        ]);
    }

    public function store(ReportScheduleRequest $request): RedirectResponse
    {
        $this->authorize('create', ReportSchedule::class);

        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->allows($request->reportRequest(), $user), 403);

        $schedule = new ReportSchedule($request->scheduleAttributes());
        $schedule->owner_user_id = $user->id;
        $schedule->is_active = true;
        $schedule->next_run_at = $this->clock->nextFor($schedule, CarbonImmutable::now());
        $schedule->save();

        DeliveryAudit::record('schedule_created', $schedule, $user, $this->auditProperties($schedule));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('report_deliveries.flash.scheduled')]);

        return back();
    }

    public function update(ReportScheduleRequest $request, ReportSchedule $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule);

        // Se envía con los permisos del propietario: tiene que poder ver el informe.
        abort_unless($this->access->allows($request->reportRequest(), $schedule->owner), 403);

        $schedule->fill($request->scheduleAttributes());

        if ($schedule->is_active) {
            $schedule->next_run_at = $this->clock->nextFor($schedule, CarbonImmutable::now());
            $schedule->is_active = $schedule->next_run_at !== null;
        }

        $schedule->save();

        /** @var User $user */
        $user = $request->user();
        DeliveryAudit::record('schedule_updated', $schedule, $user, $this->auditProperties($schedule));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('report_deliveries.flash.updated')]);

        return back();
    }

    public function destroy(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        $this->authorize('delete', $schedule);

        /** @var User $user */
        $user = $request->user();
        DeliveryAudit::record('schedule_deleted', $schedule, $user, $this->auditProperties($schedule));
        $schedule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('report_deliveries.flash.deleted')]);

        return to_route('reports.schedules.index');
    }

    public function pause(Request $request, ReportSchedule $schedule, SchedulePauser $pauser): RedirectResponse
    {
        $this->authorize('update', $schedule);

        if ($schedule->is_active) {
            /** @var User $user */
            $user = $request->user();
            $pauser->pause($schedule, PauseReason::Manual, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('report_deliveries.flash.paused')]);

        return back();
    }

    public function resume(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule);

        $owner = $schedule->owner;
        $error = match (true) {
            ! $owner->isActive() => PauseReason::OwnerInactive->label(),
            ! $this->access->allows($schedule->reportRequest(), $owner) => PauseReason::NoAccess->label(),
            default => null,
        };

        $next = $error === null ? $this->clock->nextFor($schedule, CarbonImmutable::now()) : null;

        if ($error === null && $next === null) {
            $error = self::text('report_deliveries.errors.run_at_past');
        }

        if ($error !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) __('report_deliveries.flash.cannot_resume', ['reason' => $error])]);

            return back();
        }

        $schedule->forceFill(['is_active' => true, 'paused_reason' => null, 'next_run_at' => $next])->save();

        /** @var User $user */
        $user = $request->user();
        DeliveryAudit::record('schedule_resumed', $schedule, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('report_deliveries.flash.resumed')]);

        return back();
    }

    public function sendNow(Request $request, ReportSchedule $schedule, ScheduleRunner $runner): RedirectResponse
    {
        $this->authorize('update', $schedule);

        /** @var User $user */
        $user = $request->user();
        $delivery = $runner->run($schedule, CarbonImmutable::now(), advance: false, by: $user);

        Inertia::flash('toast', $delivery !== null
            ? ['type' => 'success', 'message' => trans_choice('report_deliveries.flash.sent', $delivery->recipientCount(), ['count' => $delivery->recipientCount()])]
            : ['type' => 'error', 'message' => (string) __('report_deliveries.flash.cannot_send', ['reason' => $schedule->refresh()->paused_reason?->label() ?? ''])]);

        return back();
    }

    /**
     * Informes que se pueden programar desde la lista (el resto, desde «Exportar ▾» de cada
     * informe): el personal, el detallado y, si lo ve, el de dirección. Del mes, con su periodo
     * relativo a elegir.
     *
     * @return list<array{kind: string, route_params: array<string, int>, query: array<string, string>, title: string}>
     */
    private function newReports(User $user): array
    {
        $month = ['periodo' => 'mes'];
        $reports = [
            ['kind' => ReportKind::Person->value, 'route_params' => ['user' => $user->id], 'query' => $month, 'title' => (string) __('report_deliveries.new_reports.person', ['name' => $user->name])],
            ['kind' => ReportKind::Detail->value, 'route_params' => [], 'query' => $month, 'title' => (string) __('report_deliveries.new_reports.detail')],
        ];

        if (Gate::forUser($user)->allows('viewDirectionReport', Department::class)) {
            $reports[] = ['kind' => ReportKind::Direction->value, 'route_params' => [], 'query' => $month, 'title' => (string) __('report_deliveries.new_reports.direction')];
        }

        return $reports;
    }

    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditProperties(ReportSchedule $schedule): array
    {
        return [
            'kind' => $schedule->request['kind'],
            'frequency' => $schedule->frequency->value,
            'formats' => $schedule->formats,
            'recipient_user_ids' => $schedule->recipient_user_ids,
            'recipient_emails' => $schedule->recipient_emails,
        ];
    }
}
