<?php

namespace App\Domain\Reports;

use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ingreso estimado y coste de un conjunto de entradas (SPEC §10, D-043), opcionalmente agrupado
 * por una dimensión. Agrega en SQL las sumas de cada unidad (proyecto · bolsa · persona ·
 * facturable) y las valora en PHP con bcmath (Valuation, las mismas fórmulas que EntryValuation),
 * con un número fijo de consultas.
 *
 * Céntimos (INT-04): el total de un conjunto de entradas es SIEMPRE el mismo, se agrupe como se
 * agrupe: el de sus unidades (el «total canónico»), redondeado una sola vez. Los grupos de un
 * desglose reciben su parte de ese total en céntimos por resto mayor (Cents::largestRemainder,
 * como la exportación para facturar), así que las filas de cualquier tabla suman exactamente el
 * total del resumen, y el mismo ingreso sale con los mismos céntimos en todas las pantallas.
 *
 * @phpstan-import-type Sums from Valuation
 * @phpstan-import-type Unit from Valuation
 */
final class RevenueCalculator
{
    /**
     * Importes de cada grupo redondeados a céntimos, repartidos para que sumen el total canónico
     * redondeado una vez ('all' sin agrupar).
     *
     * @param  Builder<TimeEntry>  $entries  Consulta ya acotada (ReportScope::entries()).
     * @return array<string, array{income: numeric-string, cost: numeric-string, billable_minutes: int}> Clave del grupo ('all' sin agrupar).
     */
    public function compute(Builder $entries, ?Dimension $groupBy = null): array
    {
        ['groups' => $groups, 'total' => $total] = $this->exact($entries, $groupBy);

        return self::distribute($groups, $total);
    }

    /**
     * Importes exactos (6 decimales) de cada grupo y el total canónico del conjunto (el de sus
     * unidades, que no depende de cómo se agrupe). Para sumar grupos y redondear una sola vez su
     * total, o repartir sus céntimos (exportación para facturar, R2).
     *
     * @param  Builder<TimeEntry>  $entries  Consulta ya acotada (ReportScope::entries()).
     * @return array{groups: array<string, array{income: numeric-string, cost: numeric-string, billable_minutes: int}>,
     *     total: array{income: numeric-string, cost: numeric-string, billable_minutes: int}}
     */
    public function exact(Builder $entries, ?Dimension $groupBy = null): array
    {
        $query = clone $entries;
        $groupBy?->join($query);

        return $this->valuate($query, $groupBy?->expression());
    }

    /**
     * Reparte en céntimos el total canónico entre los grupos (resto mayor, por separado el ingreso
     * y el coste): los grupos suman exactamente el total redondeado.
     *
     * @param  array<string, array{income: numeric-string, cost: numeric-string, billable_minutes: int}>  $groups
     * @param  array{income: numeric-string, cost: numeric-string, billable_minutes: int}  $total
     * @return array<string, array{income: numeric-string, cost: numeric-string, billable_minutes: int}>
     */
    public static function distribute(array $groups, array $total): array
    {
        if ($groups === []) {
            return [];
        }

        $income = Cents::largestRemainder(array_map(fn (array $group): string => $group['income'], $groups), Money::round($total['income']));
        $cost = Cents::largestRemainder(array_map(fn (array $group): string => $group['cost'], $groups), Money::round($total['cost']));

        foreach ($groups as $key => $group) {
            $groups[$key]['income'] = $income[$key];
            $groups[$key]['cost'] = $cost[$key];
        }

        return $groups;
    }

    /**
     * @param  Builder<TimeEntry>  $query
     * @param  literal-string|null  $keyExpression
     * @return array{groups: array<string, array{income: numeric-string, cost: numeric-string, billable_minutes: int}>,
     *     total: array{income: numeric-string, cost: numeric-string, billable_minutes: int}}
     */
    private function valuate(Builder $query, ?string $keyExpression): array
    {
        $columns = 'time_entries.project_id, time_entries.hour_bank_id, time_entries.user_id, time_entries.is_billable';
        $query->selectRaw(($keyExpression ?? "'all'").' as group_key, '.$columns.', '.Valuation::SUMS_SQL)
            ->groupByRaw(($keyExpression !== null ? $keyExpression.', ' : '').$columns);

        /** @var Collection<int, object> $rows */
        $rows = $query->toBase()->get();
        $zero = ['income' => '0', 'cost' => '0', 'billable_minutes' => 0];

        if ($rows->isEmpty()) {
            return ['groups' => [], 'total' => $zero];
        }

        $ids = fn (string $column): array => array_values($rows->pluck($column)->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)->unique()->all());
        $valuation = Valuation::load($this, $ids('project_id'), $ids('hour_bank_id'), $ids('user_id'));

        /** @var array<string, array{income: numeric-string, cost: numeric-string, billable_minutes: int}> $groups */
        $groups = [];
        /** @var array<string, array{unit: Unit, sums: Sums}> $units sumas de cada unidad, de todos los grupos */
        $units = [];
        /** @var array<string, array<int, int>> $fixed grupo → proyecto de precio cerrado → minutos facturables */
        $fixed = [];

        foreach ($rows as $raw) {
            /** @var array<string, mixed> $row */
            $row = (array) $raw;
            $key = (string) ($row['group_key'] ?? '');
            $bankId = $row['hour_bank_id'] !== null ? (int) $row['hour_bank_id'] : null;
            $unit = $valuation->unit((int) $row['project_id'], $bankId, (int) $row['user_id'], (bool) $row['is_billable']);
            $sums = Valuation::fromRow($row);

            $groups[$key] ??= $zero;
            $groups[$key]['cost'] = Money::add($groups[$key]['cost'], $valuation->cost($unit, $sums));

            $unitKey = $row['project_id'].':'.($bankId ?? '-').':'.$row['user_id'].':'.((bool) $row['is_billable'] ? 1 : 0);
            $units[$unitKey] = ['unit' => $unit, 'sums' => Valuation::add($units[$unitKey]['sums'] ?? Valuation::zero(), $sums)];

            if (! Valuation::earns($unit)) {
                continue;
            }

            $groups[$key]['billable_minutes'] += $sums['minutes'];

            if ($unit['basis'] === EntryValuation::FIXED_PRICE) {
                $fixed[$key][$unit['project']] = ($fixed[$key][$unit['project']] ?? 0) + $sums['minutes'];

                continue;
            }

            $groups[$key]['income'] = Money::add($groups[$key]['income'], $valuation->income($unit, $sums));
        }

        foreach ($fixed as $key => $byProject) {
            $groups[$key] ??= $zero;
            foreach ($byProject as $projectId => $minutes) {
                $groups[$key]['income'] = Money::add($groups[$key]['income'], $valuation->fixedIncome($projectId, $minutes));
            }
        }

        return ['groups' => $groups, 'total' => self::canonical($valuation, $units)];
    }

    /**
     * El total canónico: cada unidad valorada con las sumas de todos sus grupos (y el precio
     * cerrado, por proyecto). Es el que da compute() sin agrupar para las mismas entradas y el que
     * suman, entrada a entrada, las exportaciones (EntryValuation::next).
     *
     * @param  array<string, array{unit: Unit, sums: Sums}>  $units
     * @return array{income: numeric-string, cost: numeric-string, billable_minutes: int}
     */
    private static function canonical(Valuation $valuation, array $units): array
    {
        $total = ['income' => '0', 'cost' => '0', 'billable_minutes' => 0];
        /** @var array<int, int> $fixed */
        $fixed = [];

        foreach ($units as ['unit' => $unit, 'sums' => $sums]) {
            $total['cost'] = Money::add($total['cost'], $valuation->cost($unit, $sums));

            if (! Valuation::earns($unit)) {
                continue;
            }

            $total['billable_minutes'] += $sums['minutes'];

            if ($unit['basis'] === EntryValuation::FIXED_PRICE) {
                $fixed[$unit['project']] = ($fixed[$unit['project']] ?? 0) + $sums['minutes'];
            } else {
                $total['income'] = Money::add($total['income'], $valuation->income($unit, $sums));
            }
        }

        foreach ($fixed as $projectId => $minutes) {
            $total['income'] = Money::add($total['income'], $valuation->fixedIncome($projectId, $minutes));
        }

        return $total;
    }

    /**
     * Tarifa vigente: bolsa > proyecto > cliente > persona (la misma prioridad que RateResolver).
     * Pública para valorar entradas sueltas con el mismo criterio (Valuation, EntryValuation).
     */
    public function rate(?HourBank $bank, Project $project, ?Client $client, ?User $user): ?string
    {
        foreach ([$bank?->hourly_rate, $project->hourly_rate, $client?->default_hourly_rate, $user?->default_hourly_rate] as $rate) {
            if ($rate !== null && $rate !== '') {
                return (string) $rate;
            }
        }

        return null;
    }

    /**
     * Base de avance de cada proyecto de precio cerrado (D-043): el mayor del presupuesto, la suma
     * de la estimación efectiva de las tareas raíz y los minutos facturables imputados. Pública para
     * valorar entradas sueltas con la misma base (Valuation, EntryValuation).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Project>  $projects
     * @return array<int, int>
     */
    public function fixedPriceBases(\Illuminate\Database\Eloquent\Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $ids = $projects->modelKeys();

        $logged = TimeEntry::query()->whereIn('project_id', $ids)->where('is_billable', true)
            ->groupBy('project_id')->selectRaw('project_id, SUM(minutes) as minutes')->toBase()->pluck('minutes', 'project_id');

        // Estimación efectiva de las tareas raíz (SPEC §6, la regla de ProjectSummary y de
        // Task::effectiveEstimatedMinutes): la suma de sus subtareas estimadas si alguna lo está; si
        // no, la suya. Así no se cuenta dos veces y no se pierde la de un padre con subtareas sin estimar.
        $fromSubtasks = Task::query()->whereIn('tasks.project_id', $ids)->where('tasks.is_milestone', false)
            ->whereNotNull('tasks.estimated_minutes')
            ->whereExists(fn ($root) => $root->selectRaw('1')->from('tasks as roots')
                ->whereColumn('roots.id', 'tasks.parent_task_id')
                ->whereColumn('roots.project_id', 'tasks.project_id')
                ->whereNull('roots.parent_task_id')
                ->whereNull('roots.deleted_at')
                ->where('roots.is_milestone', false))
            ->groupBy('tasks.project_id')->selectRaw('tasks.project_id as project_id, SUM(tasks.estimated_minutes) as minutes')
            ->toBase()->pluck('minutes', 'project_id');

        $fromRoots = Task::query()->whereIn('tasks.project_id', $ids)->whereNull('tasks.parent_task_id')->where('tasks.is_milestone', false)
            ->whereNotNull('tasks.estimated_minutes')
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('tasks as children')
                ->whereColumn('children.parent_task_id', 'tasks.id')
                ->whereNull('children.deleted_at')
                ->whereNotNull('children.estimated_minutes')
                ->where('children.is_milestone', false))
            ->groupBy('tasks.project_id')->selectRaw('tasks.project_id as project_id, SUM(tasks.estimated_minutes) as minutes')
            ->toBase()->pluck('minutes', 'project_id');

        $bases = [];
        foreach ($projects as $project) {
            $bases[$project->id] = max(
                (int) ($project->budget_minutes ?? 0),
                (int) ($fromSubtasks[$project->id] ?? 0) + (int) ($fromRoots[$project->id] ?? 0),
                (int) ($logged[$project->id] ?? 0),
            );
        }

        return $bases;
    }
}
