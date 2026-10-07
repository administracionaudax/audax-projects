<?php

namespace App\Http\Controllers\Leave;

use App\Domain\Absences\AbsenceScope;
use App\Domain\Absences\LeaveBalances;
use App\Domain\Absences\LeaveFormat;
use App\Domain\Absences\LeaveLedger;
use App\Domain\Absences\LeavePresenter;
use App\Domain\People\PeopleAccess;
use App\Enums\LeaveMovementKind;
use App\Http\Controllers\Absences\AbsencesController;
use App\Models\Absence;
use App\Models\Department;
use App\Models\LeaveMovement;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Saldos» (`/ausencias/saldos`, Fase 11, R3; W-060 a W-066 y W-096; D-362 y D-363): los saldos del
 * año de cada persona de su ámbito (responsables, su departamento; RR. HH. y admins, todos), con el
 * libro de movimientos de una persona (?persona=) y lo que queda de cada asignación con su
 * caducidad. Solo RR. HH. (`manage-people`) anota ajustes, saldos iniciales y arrastres, y puede
 * recalcular las asignaciones (lo hace también la tarea diaria).
 */
class LeaveBalanceController extends AbsencesController
{
    public const int MOVEMENTS_LIMIT = 200;

    public function __construct(
        private readonly LeaveBalances $balances,
        private readonly LeaveLedger $ledger,
        private readonly AbsenceScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewTeam', Absence::class);

        /** @var User $viewer */
        $viewer = $request->user();
        $current = (int) LocalTime::today()->format('Y');
        $year = $request->integer('anio', $current);
        $year = $year >= $current - 5 && $year <= $current + 1 ? $year : $current;

        $departments = $this->scope->departments($viewer);
        $departmentId = $request->integer('departamento') ?: null;
        if ($departmentId !== null && ! $departments->contains('id', $departmentId)) {
            $departmentId = null;
        }

        $people = $this->scope->people($viewer, $departmentId)
            ->with(['department:id,name,color', 'roles'])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => PeopleAccess::internalStaff($user))
            ->values();

        $summaries = $this->balances->forUsers(array_values($people->all()), $year);
        $personId = $request->integer('persona') ?: null;
        $person = $personId !== null ? $people->firstWhere('id', $personId) : null;

        return Inertia::render('leave/balances', [
            'year' => $year,
            'current_year' => $current,
            'types' => LeaveBalances::allowanceTypes()->map(fn (LeaveType $type): array => LeavePresenter::type($type))->values()->all(),
            'people' => $people->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'department' => $user->department === null ? null : ['id' => $user->department->id, 'name' => $user->department->name, 'color' => $user->department->color],
                'balances' => LeavePresenter::balances($summaries[$user->id] ?? []),
            ])->values()->all(),
            'departments' => $departments->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color])->values()->all(),
            'filters' => ['department' => $departmentId, 'person' => $person?->id],
            'detail' => $person === null ? null : $this->detail($person, $year, $summaries[$person->id] ?? []),
            'can' => ['manage' => PeopleAccess::managesRegister($viewer)],
            'starts_on' => LeaveBalances::startsOn(),
            'today' => LocalTime::todayString(),
        ]);
    }

    /** POST /ausencias/saldos/movimientos: ajuste o saldo inicial (RR. HH.). */
    public function adjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'kind' => ['required', Rule::in(array_map(fn (LeaveMovementKind $kind): string => $kind->value, LeaveMovementKind::manual()))],
            'amount' => ['required', 'string', 'max:20'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ], [], (array) __('leave.attributes'));

        /** @var User $actor */
        $actor = $request->user();
        $subject = User::query()->findOrFail((int) $data['user_id']);
        $type = LeaveType::query()->findOrFail((int) $data['leave_type_id']);
        $amount = LeaveFormat::parse((string) $data['amount'], $type->unit);

        if ($amount === null) {
            return back()->withErrors(['amount' => __('leave.import.bad_amount')]);
        }

        $this->ledger->adjust($actor, $subject, $type, LeaveMovementKind::from((string) $data['kind']), $amount, (int) $data['year'], (string) $data['reason'], $data['valid_from'] ?? null, $data['expires_on'] ?? null);
        $this->toast((string) __('leave.flash.adjusted', ['name' => $subject->name]));

        return back();
    }

    /** POST /ausencias/saldos/arrastres: lleva una cantidad a otra caducidad (IT, nacimiento; RR. HH.). */
    public function carryOver(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'amount' => ['required', 'string', 'max:20'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'expires_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ], [], (array) __('leave.attributes'));

        /** @var User $actor */
        $actor = $request->user();
        $subject = User::query()->findOrFail((int) $data['user_id']);
        $type = LeaveType::query()->findOrFail((int) $data['leave_type_id']);
        $amount = LeaveFormat::parse((string) $data['amount'], $type->unit);

        if ($amount === null) {
            return back()->withErrors(['amount' => __('leave.import.bad_amount')]);
        }

        $this->ledger->carryOver($actor, $subject, $type, (int) $data['year'], $amount, (string) $data['expires_on'], (string) $data['reason']);
        $this->toast((string) __('leave.flash.carried_over', ['name' => $subject->name]));

        return back();
    }

    /** POST /ausencias/saldos/recalcular: asigna o recalcula el año (RR. HH.). */
    public function sync(Request $request): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        /** @var User $actor */
        $actor = $request->user();
        $count = $this->ledger->syncYear((int) $data['year'], actor: $actor);
        $this->toast(trans_choice('leave.flash.synced', $count, ['count' => $count]));

        return back();
    }

    /**
     * @param  list<array<string, mixed>>  $summaries
     * @return array<string, mixed>
     */
    private function detail(User $person, int $year, array $summaries): array
    {
        $movements = LeaveMovement::query()
            ->with(['author:id,name', 'leaveType:id,name,unit'])
            ->where('user_id', $person->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MOVEMENTS_LIMIT)
            ->get();

        return [
            'person' => ['id' => $person->id, 'name' => $person->name],
            'lots' => array_map(fn (array $summary): array => [
                'type_id' => $summary['type']->id,
                'lots' => array_map(fn (array $lot): array => [
                    'id' => $lot['id'],
                    'year' => $lot['year'],
                    'kind' => $lot['kind'],
                    'amount' => $lot['amount'],
                    'used' => $lot['used'],
                    'reserved' => $lot['reserved'],
                    'remaining' => $lot['remaining'],
                    'valid_from' => $lot['valid_from'],
                    'expires_on' => $lot['expires_on'],
                ], $summary['lots']),
            ], $summaries),
            'movements' => $movements->map(fn (LeaveMovement $movement): array => [
                'id' => $movement->id,
                'type' => ['id' => $movement->leave_type_id, 'name' => $movement->leaveType->name, 'unit' => $movement->leaveType->unit->value],
                'year' => $movement->year,
                'kind' => $movement->kind->value,
                'amount' => $movement->amount,
                'valid_from' => $movement->valid_from->toDateString(),
                'expires_on' => $movement->expires_on?->toDateString(),
                'reason' => $movement->reason,
                'author' => $movement->author?->name,
                'created_at' => $movement->created_at?->toIso8601ZuluString(),
            ])->values()->all(),
        ];
    }
}
