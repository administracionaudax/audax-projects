<?php

namespace App\Http\Controllers\People;

use App\Domain\People\OvertimeService;
use App\Domain\People\PeopleAccess;
use App\Domain\People\TimeBalanceLedger;
use App\Enums\BalanceMovementKind;
use App\Enums\OvertimeDestination;
use App\Http\Controllers\Controller;
use App\Http\Controllers\People\Concerns\HandlesRegisterFiles;
use App\Models\OvertimeDecision;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Horas extra» (`/personas/horas-extra`, PLAN-FASE-11 §7.2; D-349 y D-350; W-047, W-052 y W-053),
 * para los responsables (su departamento) y RR. HH. (todos):
 *
 * - los días con exceso por clasificar del mes elegido y del anterior (o con una clasificación por
 *   revisar), para decidir cuánto es hora extra (y si se compensa o se paga) y cuánto
 *   flexibilidad,
 * - lo ya clasificado del mes,
 * - por persona, sus horas extra del año frente al tope de 80 h y su saldo de horas, donde se
 *   anotan los descansos disfrutados, los pagos y (solo RR. HH.) los ajustes y el saldo inicial.
 *
 * Nadie decide lo suyo: un responsable no ve aquí sus propios días (los decide RR. HH.).
 */
class OvertimeController extends Controller
{
    use HandlesRegisterFiles;

    public function __construct(
        private readonly OvertimeService $overtime,
        private readonly TimeBalanceLedger $ledger,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $month = $this->monthFrom($request);
        $from = $month->subMonth()->startOfMonth()->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $year = (int) $month->year;

        $people = PeopleAccess::teamQuery($viewer)->whereKeyNot($viewer->id)->with('employmentProfile')->orderBy('name')->get();
        $ids = array_values($people->map(fn (User $person): int => $person->id)->all());

        $pending = array_map(fn (array $item): array => [
            'user' => ['id' => $item['user']->id, 'name' => $item['user']->name],
            'date' => $item['date'],
            'excess_minutes' => $item['excess_minutes'],
            'worked_minutes' => $item['worked_minutes'],
            'expected_minutes' => $item['expected_minutes'],
            'stale' => $item['stale'],
            'previous' => $item['decision'] === null ? null : RegisterController::decision($item['decision']),
            'part_time' => $item['part_time'],
            'month_confirmed' => $item['month_confirmed'],
            'rest_preview' => OvertimeService::restMinutes($item['excess_minutes']),
        ], $this->overtime->pending($viewer, $from, $to));

        $decided = array_map(fn (OvertimeDecision $decision): array => [
            ...RegisterController::decision($decision),
            'user' => ['id' => $decision->user_id, 'name' => $decision->user->name],
        ], $this->overtime->decided($ids, $month->startOfMonth()->toDateString(), $to));

        $summaries = $people->map(fn (User $person): array => [
            'user' => ['id' => $person->id, 'name' => $person->name],
            'part_time' => $person->employmentProfile?->part_time === true,
            'year' => $this->overtime->yearSummary($person, $year),
            'balance_minutes' => $this->ledger->balance($person->id),
            'expiring' => count(array_filter($this->ledger->pendingCompensation($person->id), fn (array $credit): bool => $credit['expired'] || $credit['deadline'] <= LocalTime::today()->addDays(30)->toDateString())),
        ])->values()->all();

        $selected = $request->integer('persona');
        $person = $selected > 0 ? $people->firstWhere('id', $selected) : null;

        return Inertia::render('people/overtime', [
            'month' => $month->format('Y-m'),
            'current_month' => LocalTime::today()->format('Y-m'),
            'pending' => $pending,
            'decided' => $decided,
            'people' => $summaries,
            'person' => $person === null ? null : [
                'user' => ['id' => $person->id, 'name' => $person->name],
                'balance_minutes' => $this->ledger->balance($person->id),
                'pending' => $this->ledger->pendingCompensation($person->id),
                'movements' => array_map(fn ($movement): array => RegisterController::movement($movement), $this->ledger->movements($person->id, 50)),
            ],
            'manages_all' => PeopleAccess::managesAll($viewer),
            'rest_minutes_per_hour' => OvertimeService::REST_MINUTES_PER_HOUR,
            'cap_minutes' => OvertimeService::YEAR_CAP_MINUTES,
        ]);
    }

    /** POST /personas/horas-extra: clasifica el exceso de un día. */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'overtime_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'destination' => ['nullable', Rule::enum(OvertimeDestination::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $subject = User::query()->findOrFail((int) $data['user_id']);
        $this->overtime->decide(
            $actor,
            $subject,
            $data['date'],
            (int) $data['overtime_minutes'],
            isset($data['destination']) ? OvertimeDestination::from($data['destination']) : null,
            $data['note'] ?? null,
        );
        $this->toast(__('people.flash.overtime_decided'));

        return back();
    }

    /** POST /personas/saldo: un movimiento del saldo de horas (descanso, pago, ajuste o saldo inicial). */
    public function balance(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(array_map(fn (BalanceMovementKind $kind): string => $kind->value, BalanceMovementKind::manual()))],
            'minutes' => ['required', 'integer', 'between:-100000,100000', 'not_in:0'],
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.min' => __('people.errors.balance_reason'),
            'reason.required' => __('people.errors.balance_reason'),
            'minutes.not_in' => __('people.errors.balance_zero'),
        ]);

        $subject = User::query()->findOrFail((int) $data['user_id']);
        $this->ledger->record($actor, $subject, BalanceMovementKind::from($data['kind']), (int) $data['minutes'], $data['date'], $data['reason']);
        $this->toast(__('people.flash.balance_recorded'));

        return back();
    }
}
