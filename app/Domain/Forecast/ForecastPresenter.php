<?php

namespace App\Domain\Forecast;

use App\Domain\Audit\AuditValues;
use App\Domain\Time\CapacityPlan;
use App\Enums\ForecastStatus;
use App\Http\Resources\Forecast\AllocationResource;
use App\Http\Resources\Forecast\ForecastProjectResource;
use App\Http\Resources\Tasks\Plain;
use App\Models\Allocation;
use App\Models\Client;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Datos de las páginas de la previsión (contrato: resources/js/types/forecast.ts): la lista y la
 * ficha de los previstos y la pestaña Planificación de un proyecto real. Las cifras de cada
 * asignación salen del plan completo (AllocationPlanner sin «hoy») y, en un proyecto real, de las
 * horas imputadas y de la carga desde hoy. Sin N+1: una consulta por cosa, sea cual sea el número
 * de asignaciones.
 */
final class ForecastPresenter
{
    /** Filtros de la lista de previstos (?estado=). */
    public const array FILTERS = ['active', 'lost', 'linked', 'all'];

    /** Semanas como mucho de «plan frente a imputado» de la Planificación. */
    public const int MAX_WEEKS = 52;

    public function __construct(private readonly AllocationPlanner $planner) {}

    /**
     * Lista de previstos: activos (abiertos y confirmados, por defecto), perdidos, vinculados o todos.
     *
     * @return list<array<array-key, mixed>>
     */
    public function list(string $filter): array
    {
        $forecasts = ForecastProject::query()
            ->with(['client:id,name', 'owner:id,name', 'project:id,code,name', 'allocations'])
            ->when($filter === 'active', fn (Builder $query) => $query->counting())
            ->when($filter === 'lost', fn (Builder $query) => $query->where('status', ForecastStatus::Lost->value))
            ->when($filter === 'linked', fn (Builder $query) => $query->where('status', ForecastStatus::Linked->value))
            ->orderByRaw('start_date IS NULL')
            ->orderBy('start_date')
            ->orderBy('name')
            ->get();

        $allocations = $forecasts->flatMap(fn (ForecastProject $forecast) => $forecast->allocations)->values()->all();
        $horizons = [];
        foreach ($forecasts as $forecast) {
            $horizon = self::forecastHorizon($forecast, $forecast->allocations);
            foreach ($forecast->allocations as $allocation) {
                $horizons[$allocation->id] = $horizon;
            }
        }

        $plan = $this->planner->plan($allocations, $horizons);

        return array_values($forecasts->map(function (ForecastProject $forecast) use ($plan): array {
            $total = 0;
            foreach ($forecast->allocations as $allocation) {
                $total += $plan->minutes($allocation->id);
            }

            return Plain::of(new ForecastProjectResource($forecast, $total));
        })->all());
    }

    /**
     * Ficha de un previsto: sus datos, sus asignaciones con el plan completo (total y por mes) y la
     * suma frente a la estimación.
     *
     * @return array{forecast: array<array-key, mixed>, allocations: list<array<array-key, mixed>>, months: list<string>, totals: array{allocated_minutes: int, estimated_minutes: int|null, difference_minutes: int|null}}
     */
    public function show(User $viewer, ForecastProject $forecast): array
    {
        $forecast->loadMissing(['client:id,name', 'owner:id,name', 'project:id,code,name']);
        $allocations = $forecast->allocations()->with(['user:id,name,department_id', 'department:id,name,color'])->get();
        $allocations->each(fn (Allocation $allocation) => $allocation->setRelation('forecastProject', $forecast));
        $forecast->setRelation('allocations', $allocations);

        $plan = $this->planner->plan($allocations->all(), self::forecastHorizon($forecast, $allocations));
        [$rows, $months, $total] = $this->rows($viewer, $allocations, $plan);

        return [
            'forecast' => Plain::of(new ForecastProjectResource($forecast, $total)),
            'allocations' => $rows,
            'months' => $months,
            'totals' => [
                'allocated_minutes' => $total,
                'estimated_minutes' => $forecast->estimated_minutes,
                'difference_minutes' => $forecast->estimated_minutes === null ? null : $total - $forecast->estimated_minutes,
            ],
        ];
    }

    /**
     * Pestaña Planificación de un proyecto real: sus asignaciones con plan, imputado (de esa persona
     * en el proyecto dentro del rango) y lo que queda desde hoy, y «plan frente a imputado» por
     * semana del proyecto.
     *
     * @return array{allocations: list<array<array-key, mixed>>, months: list<string>, weeks: list<array{key: string, from: string, to: string, planned: int, logged: int, missing: list<string>}>, totals: array{planned_minutes: int, planned_to_date_minutes: int, logged_minutes: int, remaining_minutes: int}}
     */
    public function planning(User $viewer, Project $project): array
    {
        $allocations = $project->allocations()->with(['user:id,name,department_id', 'department:id,name,color', 'project'])->get();
        $horizon = ForecastBaseline::horizon($project->due_date?->toDateString(), $project->start_date?->toDateString(), $allocations);
        $today = CapacityPlan::day(LocalTime::todayString());

        $plan = $this->planner->plan($allocations->all(), $horizon);
        $forward = $this->planner->plan($allocations->all(), max($horizon, $today), $today, AllocationPlanner::logged($allocations));

        // Imputado por persona y día en el proyecto (una consulta).
        $entries = [];
        $byDay = [];
        foreach (TimeEntry::query()->where('project_id', $project->id)->toBase()
            ->selectRaw('user_id, date, SUM(minutes) as minutes')->groupBy('user_id', 'date')->get() as $row) {
            $day = CapacityPlan::day(substr((string) $row->date, 0, 10));
            $entries[(int) $row->user_id][$day] = (int) $row->minutes;
            $byDay[$day] = ($byDay[$day] ?? 0) + (int) $row->minutes;
        }

        $figures = [];
        foreach ($allocations as $allocation) {
            $logged = null;

            if ($allocation->user_id !== null) {
                [$start, $end] = $this->planner->range($allocation, min($horizon, $today));
                $logged = 0;
                foreach ($entries[$allocation->user_id] ?? [] as $day => $minutes) {
                    if ($day >= $start && $day <= $end) {
                        $logged += $minutes;
                    }
                }
            }

            $toDate = 0;
            foreach ($plan->days[$allocation->id] ?? [] as $day => $minutes) {
                if ($day < $today) {
                    $toDate += $minutes;
                }
            }

            $figures[$allocation->id] = [
                'logged_minutes' => $logged,
                'remaining_minutes' => array_sum($forward->days[$allocation->id] ?? []),
                // Plan hasta hoy (D-296): lo que ya debería estar hecho, para la desviación.
                'planned_to_date_minutes' => $toDate,
                'overdue' => in_array($allocation->id, $forward->overdue, true),
            ];
        }

        [$rows, $months, $total] = $this->rows($viewer, $allocations, $plan, $figures);

        return [
            'allocations' => $rows,
            'months' => $months,
            'weeks' => $this->weeks($allocations, $plan, $byDay, $horizon, $entries, $today),
            'totals' => [
                'planned_minutes' => $total,
                'planned_to_date_minutes' => (int) array_sum(array_column($figures, 'planned_to_date_minutes')),
                'logged_minutes' => (int) array_sum(array_map(fn (array $figure): int => (int) $figure['logged_minutes'], $figures)),
                'remaining_minutes' => (int) array_sum(array_column($figures, 'remaining_minutes')),
            ],
        ];
    }

    /** Entradas como mucho del historial de la ficha. */
    public const int HISTORY_LIMIT = 30;

    /** Campos del historial que no se nombran (ruido técnico o la foto de la línea base). */
    private const array HISTORY_HIDDEN = ['id', 'created_at', 'updated_at', 'deleted_at', 'baseline', 'forecast_project_id', 'project_id', 'created_by', 'copied_from_allocation_id'];

    /**
     * Historial de un previsto (D-307): sus cambios y los de sus asignaciones, de la auditoría
     * (LogsDomainActivity), del más reciente al más antiguo. Solo los nombres de los campos
     * cambiados, nunca sus valores; el importe estimado ni se nombra sin view-financials.
     *
     * @return list<array{id: int, at: string|null, causer: string|null, subject: string, event: string, who: string|null, fields: list<string>}>
     */
    public function history(User $viewer, ForecastProject $forecast): array
    {
        $allocations = Allocation::withTrashed()
            ->where('forecast_project_id', $forecast->id)
            ->with(['user:id,name', 'department:id,name'])
            ->get(['id', 'user_id', 'department_id', 'forecast_project_id']);
        $who = [];
        foreach ($allocations as $allocation) {
            $gap = $allocation->department === null ? null : __('forecast.history.gap', ['department' => $allocation->department->name]);
            $who[$allocation->id] = $allocation->user !== null ? $allocation->user->name : (is_string($gap) ? $gap : null);
        }

        $forecastType = $forecast->getMorphClass();
        $allocationType = (new Allocation)->getMorphClass();
        $financials = $viewer->can('view-financials');
        $labels = app(AuditValues::class);

        return array_values(Activity::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $own) => $own->where('subject_type', $forecastType)->where('subject_id', $forecast->id))
                ->orWhere(fn (Builder $children) => $children->where('subject_type', $allocationType)->whereIn('subject_id', array_keys($who) === [] ? [0] : array_keys($who))))
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(function (Activity $activity) use ($forecastType, $who, $financials, $labels): array {
                $changes = $activity->attribute_changes?->toArray() ?? [];
                $fields = array_keys(is_array($changes['attributes'] ?? null) ? $changes['attributes'] : []);
                $fields = array_values(array_filter($fields, fn (string $field): bool => ! in_array($field, self::HISTORY_HIDDEN, true)
                    && ($financials || $field !== 'estimated_amount')));
                $isForecast = $activity->subject_type === $forecastType;
                $causer = $activity->causer;

                return [
                    'id' => (int) $activity->id,
                    'at' => $activity->created_at?->toIso8601String(),
                    'causer' => $causer instanceof User ? $causer->name : null,
                    'subject' => $isForecast ? 'forecast' : 'allocation',
                    'event' => (string) $activity->event,
                    'who' => $isForecast ? null : ($who[(int) $activity->subject_id] ?? null),
                    'fields' => $activity->event === 'updated'
                        ? array_values(array_unique(array_map(fn (string $field): string => $labels->label($field, $activity->log_name), $fields)))
                        : [],
                ];
            })->all());
    }

    /**
     * Personas y departamentos de los selectores de una asignación: la plantilla activa y los
     * colaboradores externos activos (D-300), marcados.
     *
     * @return array{people: list<array{id: int, name: string, department_id: int|null, collaborator: bool}>, departments: list<array{id: int, name: string, color: string}>}
     */
    public function options(): array
    {
        return [
            'people' => array_values(ForecastPeople::assignables()->with('roles:id,name')->orderBy('name')->get(['id', 'name', 'department_id'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'department_id' => $user->department_id, 'collaborator' => $user->isCollaborator()])->all()),
            'departments' => array_values(Department::query()->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color])->all()),
        ];
    }

    /**
     * Clientes activos para el alta y la edición de un previsto.
     *
     * @return list<array{id: int, name: string}>
     */
    public function clients(): array
    {
        return array_values(Client::query()->active()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])->all());
    }

    /**
     * Proyectos con los que se puede vincular el previsto (§6.6.2): no archivados, sin otro previsto
     * y del mismo cliente (de cualquiera si el previsto no tiene cliente) que $viewer gestiona.
     *
     * @return list<array{id: int, code: string, name: string, client_id: int|null}>
     */
    public function linkCandidates(User $viewer, ForecastProject $forecast): array
    {
        return array_values(Project::query()
            ->notArchived()
            ->whereNotIn('id', ForecastProject::withTrashed()->select('project_id')->whereNotNull('project_id'))
            ->when($forecast->client_id !== null, fn (Builder $query) => $query->where('client_id', $forecast->client_id))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'code', 'name', 'client_id', 'status'])
            ->filter(fn (Project $project): bool => $viewer->canManageProject($project))
            ->map(fn (Project $project): array => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'client_id' => $project->client_id])
            ->all());
    }

    /**
     * Fin de las mensuales sin fin de un previsto.
     *
     * @param  iterable<Allocation>  $allocations
     */
    public static function forecastHorizon(ForecastProject $forecast, iterable $allocations): int
    {
        return ForecastBaseline::horizon($forecast->end_date?->toDateString(), $forecast->start_date?->toDateString(), $allocations);
    }

    /**
     * @param  Collection<int, Allocation>  $allocations
     * @param  array<int, array<string, mixed>>  $figures
     * @return array{0: list<array<array-key, mixed>>, 1: list<string>, 2: int}
     */
    private function rows(User $viewer, Collection $allocations, AllocationPlan $plan, array $figures = []): array
    {
        $months = [];
        $total = 0;
        $rows = [];

        foreach ($allocations as $allocation) {
            $byMonth = [];
            $planned = 0;

            foreach ($plan->days[$allocation->id] ?? [] as $day => $minutes) {
                $month = substr(CapacityPlan::date($day), 0, 7);
                $byMonth[$month] = ($byMonth[$month] ?? 0) + $minutes;
                $months[$month] = true;
                $planned += $minutes;
            }

            ksort($byMonth);
            $total += $planned;

            $rows[] = Plain::of(new AllocationResource($allocation, [
                'planned_minutes' => $planned,
                'months' => $byMonth,
                'unscheduled' => in_array($allocation->id, $plan->unscheduled, true),
                ...($figures[$allocation->id] ?? []),
            ], [
                'update' => $viewer->can('update', $allocation),
                'assign' => $viewer->can('assign', $allocation),
            ]));
        }

        $months = array_keys($months);
        sort($months);

        return [$rows, array_map('strval', $months), $total];
    }

    /**
     * Plan (completo) e imputado (todo el proyecto) por semana, del lunes de la primera asignación
     * hasta la última (como mucho 52 semanas).
     *
     * @param  Collection<int, Allocation>  $allocations
     * @param  array<int, int>  $logged  día → minutos imputados en el proyecto
     * @param  array<int, array<int, int>>  $byUser  persona → día → minutos imputados
     * @return list<array{key: string, from: string, to: string, planned: int, logged: int, missing: list<string>}>
     */
    private function weeks(Collection $allocations, AllocationPlan $plan, array $logged, int $horizon, array $byUser = [], ?int $today = null): array
    {
        if ($allocations->isEmpty()) {
            return [];
        }

        $first = PHP_INT_MAX;
        $last = PHP_INT_MIN;
        foreach ($allocations as $allocation) {
            [$start, $end] = $this->planner->range($allocation, $horizon);
            $first = min($first, $start);
            $last = max($last, $end);
        }

        $first -= CapacityPlan::weekday($first) - 1;
        $last = min($last, $first + self::MAX_WEEKS * 7 - 1);
        $period = new ForecastPeriod(CarbonImmutable::parse(CapacityPlan::date($first)), CarbonImmutable::parse(CapacityPlan::date($last)), ForecastPeriod::WEEK);
        $map = $period->bucketOfDays($first);
        $planned = [];
        $done = [];

        $users = $allocations->keyBy('id');
        /** @var array<int, array<int, true>> $plannedBy semana → personas con plan */
        $plannedBy = [];
        foreach ($plan->days as $allocationId => $days) {
            $userId = $users->get($allocationId)?->user_id;
            foreach ($days as $day => $minutes) {
                if (isset($map[$day])) {
                    $planned[$map[$day]] = ($planned[$map[$day]] ?? 0) + $minutes;

                    if ($userId !== null && $minutes > 0) {
                        $plannedBy[$map[$day]][$userId] = true;
                    }
                }
            }
        }

        // Quién tenía plan una semana ya pasada y no imputó nada en el proyecto (D-296).
        $loggedBy = [];
        foreach ($byUser as $userId => $days) {
            foreach ($days as $day => $minutes) {
                if (isset($map[$day]) && $minutes > 0) {
                    $loggedBy[$map[$day]][$userId] = true;
                }
            }
        }
        $names = $allocations->pluck('user.name', 'user_id')->filter()->all();

        foreach ($logged as $day => $minutes) {
            if (isset($map[$day])) {
                $done[$map[$day]] = ($done[$map[$day]] ?? 0) + $minutes;
            }
        }

        $weeks = [];
        foreach ($period->buckets() as $index => $bucket) {
            $missing = [];
            if ($today !== null && CapacityPlan::day($bucket['to']) < $today) {
                foreach (array_keys($plannedBy[$index] ?? []) as $userId) {
                    if (! isset($loggedBy[$index][$userId]) && isset($names[$userId])) {
                        $missing[] = (string) $names[$userId];
                    }
                }
                sort($missing);
            }

            $weeks[] = [...$bucket, 'planned' => $planned[$index] ?? 0, 'logged' => $done[$index] ?? 0, 'missing' => $missing];
        }

        return $weeks;
    }
}
