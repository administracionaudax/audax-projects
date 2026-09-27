<?php

namespace App\Domain\Reports;

use App\Domain\HourBanks\HourBankRenewal;
use App\Enums\HourBankStatus;
use App\Models\HourBank;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contenido del resumen semanal de productividad (SPEC §10 «Alertas de productividad», D-047) de
 * quien lo recibe, sobre la semana anterior (lunes a domingo):
 * - un responsable, sobre su equipo (las personas de los departamentos que dirige, D-024);
 * - un admin, sobre toda la agencia.
 * Contenido:
 * - días sin imputar por persona: días con capacidad (Metrics::capacityByDate) y sin horas,
 * - ocupación de cada persona (Metrics::ratio de imputadas / capacidad) por encima de
 *   occupancy_high_threshold o por debajo de occupancy_low_threshold (%),
 * - bolsas en riesgo: abiertas y desde el primer umbral de aviso (D-035, lo que va dentro de la
 *   bolsa), de los departamentos que dirige (todas para un admin),
 * - tareas vencidas hoy: abiertas con fecha límite pasada, de su equipo (todas para un admin).
 * Las horas y la capacidad salen del contrato de informes (ReportScope, Metrics y PivotReport).
 * Añadido por R3 (clase nueva del contrato).
 */
final class WeeklyDigest
{
    /** Elementos que se detallan por sección; del resto solo se dice cuántos más hay. */
    public const int MAX_ITEMS = 15;

    /** @var array<string, array<int, array<string, int>>> capacidad por semana, persona y fecha (no depende de quién mira) */
    private array $capacity = [];

    public function __construct(
        private readonly Metrics $metrics,
        private readonly PivotReport $pivot,
        private readonly HourBankRenewal $renewal,
    ) {}

    /**
     * Semana anterior a $today (lunes a domingo) como filtros de informe.
     */
    public static function previousWeek(CarbonImmutable $today): ReportFilters
    {
        return ReportFilters::fromQuery(['periodo' => 'semana', 'fecha' => $today->startOfWeek()->subWeek()->toDateString()], $today);
    }

    /**
     * ¿Recibe el resumen? Admins (toda la agencia) y responsables de algún departamento (su equipo).
     */
    public static function receives(User $user): bool
    {
        return $user->isActive() && $user->isInternal() && ($user->isAdmin() || $user->managedDepartmentIds() !== []);
    }

    /**
     * @return array{scope: 'agency'|'team', from: string, to: string,
     *     unlogged: list<array{user_id: int, name: string, days: list<string>}>,
     *     high: list<array{user_id: int, name: string, occupancy: float, logged_minutes: int, capacity_minutes: int}>,
     *     low: list<array{user_id: int, name: string, occupancy: float, logged_minutes: int, capacity_minutes: int}>,
     *     banks: list<array{id: int, project_id: int, name: string, consumed_pct: float, remaining_minutes: int, overage_minutes: int}>,
     *     overdue: list<array{id: int, title: string, project: string, assignee: string|null, due_date: string}>,
     *     overdue_count: int, thresholds: array{low: int, high: int}}
     */
    public function for(User $recipient, ReportFilters $week, CarbonImmutable $today): array
    {
        $agency = $recipient->isAdmin();
        $departmentIds = $agency ? [] : $recipient->managedDepartmentIds();
        $filters = $agency ? $week : $week->with(['departmentIds' => $departmentIds === [] ? [0] : $departmentIds]);
        $scope = new ReportScope($recipient, $filters);
        $thresholds = self::thresholds();

        $people = $scope->people()->where('is_active', true)->values();

        // Minutos imputados por persona y día (PivotReport), con claves enteras de persona.
        $loggedByDay = [];
        $loggedTotal = [];
        if ($people->isNotEmpty()) {
            $pivot = $this->pivot->run($scope, Dimension::Person, Dimension::Day);
            foreach ($pivot['cells'] as $userKey => $byDate) {
                foreach ($byDate as $date => $minutes) {
                    $loggedByDay[(int) $userKey][substr((string) $date, 0, 10)] = $minutes;
                }
            }
            foreach ($pivot['row_totals'] as $userKey => $minutes) {
                $loggedTotal[(int) $userKey] = $minutes;
            }
        }

        $unlogged = [];
        $high = [];
        $low = [];
        $teamIds = [];

        foreach ($people as $person) {
            $teamIds[] = $person->id;
            $capacity = $this->capacity($scope, $person);
            $days = $loggedByDay[$person->id] ?? [];

            $missing = [];
            foreach (CarbonPeriod::create($week->from, $week->to) as $day) {
                $date = $day->toDateString();
                if (($capacity[$date] ?? 0) > 0 && ($days[$date] ?? 0) === 0) {
                    $missing[] = $date;
                }
            }

            if ($missing !== []) {
                $unlogged[] = ['user_id' => $person->id, 'name' => $person->name, 'days' => $missing];
            }

            $loggedMinutes = $loggedTotal[$person->id] ?? 0;
            $capacityMinutes = array_sum($capacity);
            $occupancy = Metrics::ratio($loggedMinutes, $capacityMinutes);

            if ($occupancy === null) {
                continue;
            }

            $row = ['user_id' => $person->id, 'name' => $person->name, 'occupancy' => $occupancy, 'logged_minutes' => $loggedMinutes, 'capacity_minutes' => $capacityMinutes];
            // En % con 2 decimales: 1,1 × 100 no debe quedar en 110,000…01 (por encima de un umbral del 110 %).
            $percent = round($occupancy * 100, 2);

            if ($percent > $thresholds['high']) {
                $high[] = $row;
            } elseif ($percent < $thresholds['low']) {
                $low[] = $row;
            }
        }

        usort($high, fn (array $a, array $b): int => $b['occupancy'] <=> $a['occupancy']);
        usort($low, fn (array $a, array $b): int => $a['occupancy'] <=> $b['occupancy']);

        [$overdue, $overdueCount] = $this->overdueTasks($agency, $teamIds, $today);

        return [
            'scope' => $agency ? 'agency' : 'team',
            'from' => $week->from->toDateString(),
            'to' => $week->to->toDateString(),
            'unlogged' => $unlogged,
            'high' => $high,
            'low' => $low,
            'banks' => $this->banksAtRisk($agency, $departmentIds),
            'overdue' => $overdue,
            'overdue_count' => $overdueCount,
            'thresholds' => $thresholds,
        ];
    }

    /**
     * ¿Hay algo que contar? Sin nada, no se envía (D-047).
     *
     * @param  array{unlogged: list<mixed>, high: list<mixed>, low: list<mixed>, banks: list<mixed>, overdue_count: int}  $digest
     */
    public static function isEmpty(array $digest): bool
    {
        return $digest['unlogged'] === [] && $digest['high'] === [] && $digest['low'] === []
            && $digest['banks'] === [] && $digest['overdue_count'] === 0;
    }

    /**
     * Umbrales de ocupación configurados (%), por defecto 70 y 110.
     *
     * @return array{low: int, high: int}
     */
    public static function thresholds(): array
    {
        $low = Setting::get('occupancy_low_threshold', Setting::DEFAULTS['occupancy_low_threshold']);
        $high = Setting::get('occupancy_high_threshold', Setting::DEFAULTS['occupancy_high_threshold']);

        return [
            'low' => is_numeric($low) ? (int) $low : 70,
            'high' => is_numeric($high) ? (int) $high : 110,
        ];
    }

    /**
     * Capacidad por fecha de una persona en la semana (una vez por persona aunque haya varios destinatarios).
     *
     * @return array<string, int>
     */
    private function capacity(ReportScope $scope, User $person): array
    {
        return $this->capacity[$scope->filters->from->toDateString()][$person->id] ??= $this->metrics->capacityByDate(
            $scope->withFilters($scope->filters->with(['userIds' => [$person->id]])),
        );
    }

    /**
     * Bolsas abiertas de proyectos no archivados desde el primer umbral (o agotadas), de más a menos consumidas.
     *
     * @param  list<int>  $departmentIds
     * @return list<array{id: int, project_id: int, name: string, consumed_pct: float, remaining_minutes: int, overage_minutes: int}>
     */
    private function banksAtRisk(bool $agency, array $departmentIds): array
    {
        if (! $agency && $departmentIds === []) {
            return [];
        }

        $threshold = $this->renewal->firstThreshold();

        return array_values(HourBank::query()
            ->open()
            ->whereHas('project', fn (Builder $project) => $project->notArchived())
            ->when(! $agency, fn (Builder $query) => $query->whereIn('department_id', $departmentIds))
            ->where(fn (Builder $near) => $near
                ->where('status', HourBankStatus::Exhausted->value)
                ->orWhereRaw('(consumed_minutes - overage_minutes) * 100 >= ? * total_minutes', [$threshold]))
            ->with(['project' => fn ($project) => $project->select(['id', 'code', 'name'])])
            ->get(['id', 'project_id', 'name', 'total_minutes', 'consumed_minutes', 'overage_minutes', 'status'])
            ->sortByDesc(fn (HourBank $bank): float => $bank->consumed_pct)
            ->values()
            ->map(fn (HourBank $bank): array => [
                'id' => $bank->id,
                'project_id' => $bank->project_id,
                'name' => $bank->project->code.' · '.$bank->name,
                'consumed_pct' => $bank->consumed_pct,
                'remaining_minutes' => $bank->remaining_minutes,
                'overage_minutes' => $bank->overage_minutes,
            ])
            ->all());
    }

    /**
     * Tareas abiertas con la fecha límite pasada (antes de hoy), de proyectos no archivados: las del
     * equipo o, para un admin, todas. Las más antiguas primero.
     *
     * @param  list<int>  $teamIds
     * @return array{0: list<array{id: int, title: string, project: string, assignee: string|null, due_date: string}>, 1: int}
     */
    private function overdueTasks(bool $agency, array $teamIds, CarbonImmutable $today): array
    {
        if (! $agency && $teamIds === []) {
            return [[], 0];
        }

        $query = Task::query()
            ->open()
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today->toDateString())
            ->whereHas('project', fn (Builder $project) => $project->notArchived())
            ->when(! $agency, fn (Builder $tasks) => $tasks->whereIn('assignee_user_id', $teamIds));

        $count = (clone $query)->count();

        $items = $query
            ->with([
                'project' => fn ($project) => $project->select(['id', 'code']),
                'assignee' => fn ($assignee) => $assignee->select(['id', 'name']),
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::MAX_ITEMS)
            ->get(['id', 'title', 'project_id', 'assignee_user_id', 'due_date'])
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'project' => $task->project->code,
                'assignee' => $task->assignee?->name,
                'due_date' => (string) $task->due_date?->toDateString(),
            ])
            ->all();

        return [array_values($items), $count];
    }
}
