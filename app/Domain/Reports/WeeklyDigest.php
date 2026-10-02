<?php

namespace App\Domain\Reports;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Contenido del resumen semanal de productividad (SPEC §10 «Alertas de productividad», D-047) de
 * quien lo recibe, sobre la semana anterior (lunes a domingo):
 * - un responsable, sobre su equipo (las personas de los departamentos que dirige, D-024);
 * - un admin, sobre toda la agencia.
 * Contenido:
 * - días sin imputar por persona: días con capacidad y sin horas,
 * - ocupación de cada persona (Metrics::ratio de imputadas / capacidad) por encima de
 *   occupancy_high_threshold o por debajo de occupancy_low_threshold (%),
 * - bolsas en riesgo y tareas vencidas hoy, con el MISMO alcance que el dashboard de dirección
 *   (HourBanksAtRisk::forScope y OverdueTasks::forScope, INT-02): quien lo recibe ve en el email lo
 *   que vería en el dashboard.
 * Las horas y la capacidad salen del contrato de informes (ReportScope, Metrics y PivotReport): la
 * capacidad de todo el equipo con una sola llamada por destinatario (Metrics::capacityByPerson,
 * PERF-07), no persona a persona.
 * Añadido por R3 (clase nueva del contrato).
 */
final class WeeklyDigest
{
    /** Elementos que se detallan por sección; del resto solo se dice cuántos más hay. */
    public const int MAX_ITEMS = 15;

    public function __construct(
        private readonly Metrics $metrics,
        private readonly PivotReport $pivot,
        private readonly HourBanksAtRisk $atRisk,
        private readonly OverdueTasks $overdueTasks,
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
     * department_ids: los departamentos que dirige un responsable (su equipo, para que el enlace al
     * detallado muestre lo mismo que el email); vacío para un admin (toda la agencia).
     *
     * @return array{scope: 'agency'|'team', department_ids: list<int>, from: string, to: string,
     *     unlogged: list<array{user_id: int, name: string, days: list<string>}>,
     *     high: list<array{user_id: int, name: string, occupancy: float, logged_minutes: int, capacity_minutes: int}>,
     *     low: list<array{user_id: int, name: string, occupancy: float, logged_minutes: int, capacity_minutes: int}>,
     *     banks: list<array{id: int, project_id: int, name: string, consumed_pct: float, remaining_minutes: int, overage_minutes: int}>,
     *     banks_count: int,
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

        // La capacidad de todo el equipo por persona y fecha, de una vez (PERF-07).
        $capacities = $people->isEmpty() ? [] : $this->metrics->capacityByPerson($scope);

        $unlogged = [];
        $high = [];
        $low = [];

        foreach ($people as $person) {
            $capacity = $capacities[$person->id] ?? [];
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

        $banks = $this->atRisk->forScope($scope, self::MAX_ITEMS);
        $overdue = $this->overdueTasks->forScope($scope, self::MAX_ITEMS, $today);

        return [
            'scope' => $agency ? 'agency' : 'team',
            'department_ids' => $departmentIds,
            'from' => $week->from->toDateString(),
            'to' => $week->to->toDateString(),
            'unlogged' => $unlogged,
            'high' => $high,
            'low' => $low,
            'banks' => array_map(fn (array $bank): array => [
                'id' => $bank['id'],
                'project_id' => $bank['project']['id'],
                'name' => $bank['project']['code'].' · '.$bank['name'],
                // El consumo en % (puede pasar del 100 %) y el saldo, como HourBank::consumed_pct y remaining_minutes.
                'consumed_pct' => $bank['total_minutes'] > 0 ? round($bank['consumed_minutes'] * 100 / $bank['total_minutes'], 2) : 0.0,
                'remaining_minutes' => max($bank['total_minutes'] - ($bank['consumed_minutes'] - $bank['overage_minutes']), 0),
                'overage_minutes' => $bank['overage_minutes'],
            ], $banks['banks']),
            'banks_count' => $banks['count'],
            'overdue' => array_map(fn (array $task): array => [
                'id' => $task['id'],
                'title' => $task['title'],
                'project' => $task['project']['code'],
                'assignee' => $task['assignee'],
                'due_date' => $task['due_date'],
            ], $overdue['tasks']),
            'overdue_count' => $overdue['count'],
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
}
