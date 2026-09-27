<?php

namespace App\Http\Controllers\Absences;

use App\Domain\Absences\AbsenceDays;
use App\Domain\Absences\AbsencePresenter;
use App\Domain\Absences\AbsenceRules;
use App\Domain\Absences\AbsenceScope;
use App\Domain\Absences\AbsenceService;
use App\Domain\Absences\AbsenceText;
use App\Domain\Absences\SpanishNationalHolidays;
use App\Enums\AbsenceStatus;
use App\Http\Requests\Absences\RegisterAbsenceRequest;
use App\Http\Requests\Absences\RejectAbsenceRequest;
use App\Http\Requests\Absences\UpdateAbsenceRequest;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Ausencias del equipo» (/ausencias/equipo, D-049, D-021, D-024): para responsables (su
 * departamento) y admins (todas las personas).
 * - Pendientes de aprobar, con las ausencias del mismo departamento que coinciden en fechas;
 *   aprobar y rechazar con comentario.
 * - Calendario mensual (?mes=2026-10) con las ausencias aprobadas y solicitadas y los festivos.
 * - Próximas ausencias aprobadas, que quien aprueba puede modificar (acortar una baja, por ejemplo)
 *   o anular.
 * - Registrar una ausencia ya aprobada de alguien de su ámbito.
 * Filtro por departamento (?departamento=3) entre los que puede ver.
 */
class TeamAbsenceController extends AbsencesController
{
    public const int PENDING_LIMIT = 100;

    public const int UPCOMING_LIMIT = 50;

    /** Ausencias coincidentes que se muestran por solicitud pendiente. */
    public const int OVERLAPS_LIMIT = 5;

    public function __construct(
        private readonly AbsenceService $absences,
        private readonly AbsenceScope $scope,
        private readonly AbsenceDays $days,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewTeam', Absence::class);

        /** @var User $viewer */
        $viewer = $request->user();
        $today = CarbonImmutable::parse(LocalTime::todayString());
        $month = $this->month($request->query('mes'), $today);
        $monthEnd = $month->endOfMonth();

        $departments = $this->scope->departments($viewer);
        $departmentId = $request->integer('departamento') ?: null;
        if ($departmentId !== null && ! $departments->contains('id', $departmentId)) {
            $departmentId = null;
        }

        /** @var Collection<int, User> $everyone */
        $everyone = $this->scope->people($viewer)
            ->with('department:id,name,color')
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'is_active']);
        $people = $departmentId === null ? $everyone : $everyone->where('department_id', $departmentId)->values();
        $byId = $people->keyBy('id');
        $ids = array_values(array_map(fn (User $person): int => $person->id, $people->all()));

        $pending = Absence::query()
            ->where('status', AbsenceStatus::Requested->value)
            ->whereIn('user_id', array_values(array_diff($ids, [$viewer->id])) ?: [0])
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit(self::PENDING_LIMIT)
            ->get();
        $this->attachPeople($pending, $byId);

        $upcoming = Absence::query()
            ->approved()
            ->whereIn('user_id', $ids ?: [0])
            ->where('end_date', '>=', $today->toDateString())
            ->with('approver:id,name')
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit(self::UPCOMING_LIMIT)
            ->get();
        $this->attachPeople($upcoming, $byId);

        $days = $this->days->count($pending->concat($upcoming));
        $overlaps = $this->overlaps($pending, $ids, $byId);

        $calendar = Absence::query()
            ->whereIn('user_id', $ids ?: [0])
            ->whereIn('status', [AbsenceStatus::Requested->value, AbsenceStatus::Approved->value])
            ->overlapping($month->toDateString(), $monthEnd->toDateString())
            ->orderBy('start_date')
            ->get(['id', 'user_id', 'type', 'status', 'start_date', 'end_date', 'partial_minutes']);

        return Inertia::render('absences/team', [
            'pending' => $pending->map(fn (Absence $absence): array => [
                ...AbsencePresenter::row($absence, $viewer, $days, withUser: true),
                'overlaps' => $overlaps[$absence->id] ?? [],
            ])->values()->all(),
            'upcoming' => $upcoming->map(fn (Absence $absence): array => AbsencePresenter::row($absence, $viewer, $days, withUser: true))->values()->all(),
            'calendar' => [
                'month' => $month->format('Y-m'),
                'current' => $today->format('Y-m'),
                'previous' => $month->subMonth()->format('Y-m'),
                'next' => $month->addMonth()->format('Y-m'),
                'days' => $this->daysOf($month, $monthEnd),
                'people' => $people->map(fn (User $person): array => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'department' => $person->department !== null
                        ? ['id' => $person->department->id, 'name' => $person->department->name, 'color' => $person->department->color]
                        : null,
                ])->values()->all(),
                'absences' => $calendar->map(fn (Absence $absence): array => [
                    'id' => $absence->id,
                    'user_id' => $absence->user_id,
                    'type' => $absence->type->value,
                    'status' => $absence->status->value,
                    'start_date' => $absence->start_date->toDateString(),
                    'end_date' => $absence->end_date->toDateString(),
                    'partial_minutes' => $absence->partial_minutes,
                ])->values()->all(),
                'holidays' => Holiday::query()
                    ->whereBetween('date', [$month->toDateString(), $monthEnd->toDateString()])
                    ->orderBy('date')
                    ->get(['date', 'name'])
                    ->map(fn (Holiday $holiday): array => ['date' => $holiday->date->toDateString(), 'name' => $holiday->name])
                    ->values()
                    ->all(),
            ],
            'departments' => $departments->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'color' => $department->color,
            ])->values()->all(),
            'filters' => ['department' => $departmentId],
            'register_people' => $everyone->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])->values()->all(),
            'types' => $this->types(),
            'today' => $today->toDateString(),
            'limits' => [
                'from' => $today->subYears(AbsenceRules::YEARS_AROUND)->toDateString(),
                'to' => $today->addYears(AbsenceRules::YEARS_AROUND)->toDateString(),
            ],
            'pending_limit' => self::PENDING_LIMIT,
        ]);
    }

    /**
     * GET /ausencias/equipo/pendientes (JSON): cuántas solicitudes esperan a quien mira. Lo usa el
     * aviso de /horas/aprobaciones.
     */
    public function pending(Request $request): JsonResponse
    {
        $this->authorize('viewTeam', Absence::class);

        /** @var User $viewer */
        $viewer = $request->user();

        $count = Absence::query()
            ->where('status', AbsenceStatus::Requested->value)
            ->where('user_id', '!=', $viewer->id)
            ->whereIn('user_id', $this->scope->people($viewer)->select('users.id'))
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * POST /ausencias/equipo: registra una ausencia ya aprobada de alguien de su ámbito.
     */
    public function store(RegisterAbsenceRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $request->target();
        $absence = $this->absences->register($actor, $target, $request->absenceData());

        $this->toast(AbsenceText::get('absences.flash.registered', [
            'name' => $target->name,
            'type' => $absence->type->label(),
            'period' => AbsenceText::period($absence->start_date->toDateString(), $absence->end_date->toDateString(), $absence->partial_minutes),
        ]));

        return back();
    }

    /**
     * POST /ausencias/{absence}/aprobar
     */
    public function approve(Request $request, Absence $absence): RedirectResponse
    {
        $this->authorize('review', $absence);

        /** @var User $reviewer */
        $reviewer = $request->user();
        $absence = $this->absences->approve($reviewer, $absence);

        $this->toast(AbsenceText::get('absences.flash.approved', ['name' => $absence->user->name]));

        return back();
    }

    /**
     * POST /ausencias/{absence}/rechazar (con comentario obligatorio).
     */
    public function reject(RejectAbsenceRequest $request, Absence $absence): RedirectResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();
        $absence = $this->absences->reject($reviewer, $absence, $request->string('comment')->toString());

        $this->toast(AbsenceText::get('absences.flash.rejected', ['name' => $absence->user->name]));

        return back();
    }

    /**
     * PUT /ausencias/{absence}: quien puede aprobarla modifica una ausencia aprobada de otra persona.
     */
    public function update(UpdateAbsenceRequest $request, Absence $absence): RedirectResponse
    {
        $this->authorize('update', $absence);

        /** @var User $actor */
        $actor = $request->user();
        $absence = $this->absences->update($actor, $absence, $request->absenceData());

        $changed = $absence->wasChanged();

        $this->toast(AbsenceText::get($changed ? 'absences.flash.updated' : 'absences.flash.unchanged', [
            'name' => $absence->user->name,
            'type' => $absence->type->label(),
            'period' => AbsenceText::period($absence->start_date->toDateString(), $absence->end_date->toDateString(), $absence->partial_minutes),
        ]), $changed ? 'success' : 'info');

        return back();
    }

    /**
     * Primer día del mes pedido (?mes=2026-10) o del actual.
     */
    private function month(mixed $value, CarbonImmutable $today): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})$/', $value, $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2];

            if ($month >= 1 && $month <= 12 && $year >= SpanishNationalHolidays::MIN_YEAR && $year <= SpanishNationalHolidays::MAX_YEAR) {
                return CarbonImmutable::create($year, $month, 1) ?? $today->startOfMonth();
            }
        }

        return $today->startOfMonth();
    }

    /**
     * @return list<string>
     */
    private function daysOf(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * Asigna a cada ausencia su persona (ya cargada, con su departamento): sin consultas por fila.
     *
     * @param  Collection<int, Absence>  $absences
     * @param  \Illuminate\Support\Collection<int, User>  $people
     */
    private function attachPeople(Collection $absences, \Illuminate\Support\Collection $people): void
    {
        foreach ($absences as $absence) {
            $person = $people->get($absence->user_id);

            if ($person !== null) {
                $absence->setRelation('user', $person);
            }
        }
    }

    /**
     * Para cada solicitud pendiente, las ausencias (aprobadas o solicitadas) de otras personas de su
     * mismo departamento que coinciden en fechas. Una consulta.
     *
     * @param  Collection<int, Absence>  $pending
     * @param  list<int>  $ids
     * @param  \Illuminate\Support\Collection<int, User>  $people
     * @return array<int, list<array{user_name: string, type: string, status: string, start_date: string, end_date: string, partial_minutes: int|null}>>
     */
    private function overlaps(Collection $pending, array $ids, \Illuminate\Support\Collection $people): array
    {
        if ($pending->isEmpty()) {
            return [];
        }

        $from = (string) $pending->min(fn (Absence $absence): string => $absence->start_date->toDateString());
        $to = (string) $pending->max(fn (Absence $absence): string => $absence->end_date->toDateString());

        $others = Absence::query()
            ->whereIn('user_id', $ids ?: [0])
            ->whereIn('status', [AbsenceStatus::Requested->value, AbsenceStatus::Approved->value])
            ->overlapping($from, $to)
            ->orderBy('start_date')
            ->get(['id', 'user_id', 'type', 'status', 'start_date', 'end_date', 'partial_minutes']);

        $result = [];

        foreach ($pending as $absence) {
            $department = $people->get($absence->user_id)?->department_id;
            $start = $absence->start_date->toDateString();
            $end = $absence->end_date->toDateString();

            $matches = [];

            foreach ($others as $other) {
                if (count($matches) >= self::OVERLAPS_LIMIT) {
                    break;
                }

                if ($other->user_id === $absence->user_id
                    || $people->get($other->user_id)?->department_id !== $department
                    || $other->start_date->toDateString() > $end
                    || $other->end_date->toDateString() < $start) {
                    continue;
                }

                $matches[] = [
                    'user_name' => (string) $people->get($other->user_id)?->name,
                    'type' => $other->type->value,
                    'status' => $other->status->value,
                    'start_date' => $other->start_date->toDateString(),
                    'end_date' => $other->end_date->toDateString(),
                    'partial_minutes' => $other->partial_minutes,
                ];
            }

            $result[$absence->id] = $matches;
        }

        return $result;
    }
}
