<?php

namespace App\Domain\Reports;

use App\Enums\BillingType;
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
 * por una dimensión. Agrega en SQL y valora en PHP con bcmath, con un número fijo de consultas.
 *
 * Ingreso (solo horas facturables; los proyectos internos dan 0):
 * - bolsa con price_amount: dentro = precio × minutos dentro / total de la bolsa; el exceso, a la
 *   tarifa bolsa > proyecto > cliente > persona,
 * - precio cerrado: importe × minutos facturables / base, con base = máx(presupuesto, suma de las
 *   estimaciones de las tareas raíz, minutos facturables imputados hasta hoy): nunca supera el importe,
 * - resto: la instantánea de tarifa si la entrada está aprobada o bloqueada; si no, la tarifa vigente.
 * Coste: la instantánea de coste si existe; si no, el coste por hora actual de la persona.
 */
final class RevenueCalculator
{
    /**
     * @param  Builder<TimeEntry>  $entries  Consulta ya acotada (ReportScope::entries()).
     * @return array<string, array{income: string, cost: string, billable_minutes: int}> Clave del grupo ('all' sin agrupar).
     */
    public function compute(Builder $entries, ?Dimension $groupBy = null): array
    {
        $query = clone $entries;
        $groupBy?->join($query);
        $keyExpression = $groupBy?->expression();

        $columns = 'time_entries.project_id, time_entries.hour_bank_id, time_entries.user_id, time_entries.is_billable';
        $query->selectRaw(($keyExpression ?? "'all'").' as group_key, '.$columns.',
            SUM(time_entries.minutes) as minutes,
            SUM(time_entries.overage_minutes) as overage,
            SUM(CASE WHEN time_entries.hourly_rate_snapshot IS NOT NULL THEN time_entries.minutes ELSE 0 END) as rate_snap_minutes,
            SUM(CASE WHEN time_entries.hourly_rate_snapshot IS NOT NULL THEN time_entries.minutes * time_entries.hourly_rate_snapshot ELSE 0 END) as rate_snap_amount,
            SUM(CASE WHEN time_entries.hourly_cost_snapshot IS NOT NULL THEN time_entries.minutes ELSE 0 END) as cost_snap_minutes,
            SUM(CASE WHEN time_entries.hourly_cost_snapshot IS NOT NULL THEN time_entries.minutes * time_entries.hourly_cost_snapshot ELSE 0 END) as cost_snap_amount')
            ->groupByRaw(($keyExpression !== null ? $keyExpression.', ' : '').$columns);

        /** @var Collection<int, object> $rows */
        $rows = $query->toBase()->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $projects = Project::query()->withTrashed()->whereIn('id', $rows->pluck('project_id')->unique()->values())
            ->get(['id', 'client_id', 'billing_type', 'fixed_price_amount', 'budget_minutes', 'hourly_rate'])->keyBy('id');
        $clients = Client::query()->withTrashed()->whereIn('id', $projects->pluck('client_id')->filter()->unique())
            ->get(['id', 'default_hourly_rate'])->keyBy('id');
        $banks = HourBank::query()->withTrashed()->whereIn('id', $rows->pluck('hour_bank_id')->filter()->unique())
            ->get(['id', 'total_minutes', 'hourly_rate', 'price_amount'])->keyBy('id');
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())
            ->get(['id', 'default_hourly_rate', 'hourly_cost'])->keyBy('id');

        $fixedBases = $this->fixedPriceBases($projects->filter(fn (Project $p): bool => $p->billing_type === BillingType::FixedPrice));

        $result = [];
        /** @var array<string, array<int, int>> $fixedMinutes grupo → proyecto → minutos facturables */
        $fixedMinutes = [];

        foreach ($rows as $raw) {
            /** @var array<string, mixed> $row */
            $row = (array) $raw;
            $key = (string) ($row['group_key'] ?? '');
            $result[$key] ??= ['income' => '0', 'cost' => '0', 'billable_minutes' => 0];

            /** @var Project|null $project */
            $project = $projects->get((int) $row['project_id']);
            /** @var User|null $user */
            $user = $users->get((int) $row['user_id']);
            /** @var HourBank|null $bank */
            $bank = $row['hour_bank_id'] !== null ? $banks->get((int) $row['hour_bank_id']) : null;
            $client = $project?->client_id !== null ? $clients->get($project->client_id) : null;
            $minutes = (int) $row['minutes'];

            // Coste
            $costSnapMinutes = (int) $row['cost_snap_minutes'];
            $result[$key]['cost'] = Money::add(
                $result[$key]['cost'],
                Money::div(Money::of($row['cost_snap_amount']), '60'),
                Money::forMinutes($minutes - $costSnapMinutes, $user?->hourly_cost),
            );

            // Ingreso
            if (! (bool) $row['is_billable'] || $project === null || $project->billing_type === BillingType::Internal) {
                continue;
            }

            $result[$key]['billable_minutes'] += $minutes;

            if ($project->billing_type === BillingType::FixedPrice) {
                $fixedMinutes[$key][$project->id] = ($fixedMinutes[$key][$project->id] ?? 0) + $minutes;

                continue;
            }

            $rate = $this->rate($bank, $project, $client, $user);

            if ($bank !== null && $bank->price_amount !== null && $bank->total_minutes > 0) {
                $overage = (int) $row['overage'];
                $income = Money::add(
                    Money::div(Money::mul($bank->price_amount, (string) ($minutes - $overage)), (string) $bank->total_minutes),
                    Money::forMinutes($overage, $rate),
                );
            } else {
                $rateSnapMinutes = (int) $row['rate_snap_minutes'];
                $income = Money::add(
                    Money::div(Money::of($row['rate_snap_amount']), '60'),
                    Money::forMinutes($minutes - $rateSnapMinutes, $rate),
                );
            }

            $result[$key]['income'] = Money::add($result[$key]['income'], $income);
        }

        foreach ($fixedMinutes as $key => $byProject) {
            $result[$key] ??= ['income' => '0', 'cost' => '0', 'billable_minutes' => 0];
            foreach ($byProject as $projectId => $minutes) {
                $project = $projects->get($projectId);
                $base = $fixedBases[$projectId] ?? 0;
                if ($project === null || $project->fixed_price_amount === null || $base <= 0) {
                    continue;
                }
                $result[$key]['income'] = Money::add(
                    $result[$key]['income'],
                    Money::div(Money::mul($project->fixed_price_amount, (string) $minutes), (string) $base),
                );
            }
        }

        foreach ($result as $key => $values) {
            $result[$key]['income'] = Money::round($values['income']);
            $result[$key]['cost'] = Money::round($values['cost']);
        }

        return $result;
    }

    /**
     * Tarifa vigente: bolsa > proyecto > cliente > persona (la misma prioridad que RateResolver).
     * Pública para valorar entradas sueltas con el mismo criterio (EntryValuation, R2).
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
     * valorar entradas sueltas con la misma base (EntryValuation, R2).
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
