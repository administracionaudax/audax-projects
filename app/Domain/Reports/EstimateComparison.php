<?php

namespace App\Domain\Reports;

use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Estimado frente a real de un proyecto (SPEC §10, dashboard de proyecto; R2), con la regla de
 * subtareas del SPEC §6 (la misma de ProjectSummary y Task::effectiveEstimatedMinutes):
 * - una tarea principal con alguna subtarea estimada se estima con la suma de sus subtareas (su
 *   propia estimación no cuenta: sería contarla dos veces); si no, con la suya,
 * - las horas de las subtareas suman en su tarea principal,
 * - los hitos no llevan horas ni estimación.
 *
 * Es de toda la vida del proyecto (la estimación no tiene periodo): las horas reales salen de
 * ReportScope::entries() con las fechas abiertas, así que respetan quién puede ver qué horas (D-044)
 * y los filtros de persona, departamento, bolsa, tipo y facturable. Los filtros de bolsa y tipo
 * también acotan las tareas. Las horas de tareas que ya no están en la lista (movidas a otro
 * proyecto) van aparte, en other_minutes, para que los totales cuadren.
 *
 * Cuatro consultas: tareas, sus tipos y estados (carga ansiosa) y horas por tarea.
 */
final class EstimateComparison
{
    public const string LIFETIME_FROM = '2000-01-01';

    public const string LIFETIME_TO = '2099-12-31';

    /**
     * El mismo alcance y filtros, con las fechas abiertas (toda la vida del proyecto).
     */
    public static function lifetime(ReportScope $scope): ReportScope
    {
        return $scope->withFilters($scope->filters->withDates(
            CarbonImmutable::parse(self::LIFETIME_FROM),
            CarbonImmutable::parse(self::LIFETIME_TO),
        ));
    }

    /**
     * @return array{
     *     tasks: list<array{id: int, parent_id: int|null, depth: int, title: string, type: array{id: int, name: string, color: string}|null,
     *         status: array{name: string, color: string, category: string}, completed: bool, derived: bool,
     *         estimated_minutes: int|null, actual_minutes: int}>,
     *     by_type: list<array{type: array{id: int, name: string, color: string}|null, estimated_minutes: int, actual_minutes: int}>,
     *     totals: array{estimated_minutes: int, actual_minutes: int, other_minutes: int, tasks: int, estimated_tasks: int, over_tasks: int}
     * }
     */
    public function forProject(ReportScope $scope, Project $project): array
    {
        $filters = $scope->filters;

        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->where('is_milestone', false)
            ->when($filters->bankIds !== [], fn (Builder $query) => $query->whereIn('hour_bank_id', $filters->bankIds))
            ->when($filters->taskTypeIds !== [], fn (Builder $query) => $query->whereIn('task_type_id', $filters->taskTypeIds))
            ->with(['type:id,name,color', 'status:id,name,color,category'])
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'parent_task_id', 'title', 'task_type_id', 'status_id', 'estimated_minutes', 'completed_at', 'position']);

        /** @var array<int, int> $actual minutos por tarea */
        $actual = [];
        $rows = (clone self::lifetime($scope)->entries())->toBase()
            ->selectRaw('time_entries.task_id as task_id, SUM(time_entries.minutes) as minutes')
            ->groupBy('time_entries.task_id')
            ->get();
        foreach ($rows as $row) {
            $actual[(int) $row->task_id] = (int) $row->minutes;
        }

        $byId = $tasks->keyBy('id');
        /** @var array<int, list<Task>> $children */
        $children = [];
        /** @var list<Task> $roots */
        $roots = [];
        foreach ($tasks as $task) {
            if ($task->parent_task_id !== null && $byId->has($task->parent_task_id)) {
                $children[$task->parent_task_id][] = $task;
            } else {
                $roots[] = $task;
            }
        }

        /** @var array<string, array{type: array{id: int, name: string, color: string}|null, estimated_minutes: int, actual_minutes: int}> $byType */
        $byType = [];
        $addType = function (Task $task, int $estimated, int $minutes) use (&$byType): void {
            $key = (string) ($task->task_type_id ?? '');
            $byType[$key] ??= ['type' => self::type($task), 'estimated_minutes' => 0, 'actual_minutes' => 0];
            $byType[$key]['estimated_minutes'] += $estimated;
            $byType[$key]['actual_minutes'] += $minutes;
        };

        $groups = [];
        $totals = ['estimated_minutes' => 0, 'actual_minutes' => 0, 'other_minutes' => 0, 'tasks' => 0, 'estimated_tasks' => 0, 'over_tasks' => 0];

        foreach ($roots as $root) {
            $subtasks = $children[$root->id] ?? [];
            $derived = collect($subtasks)->contains(fn (Task $subtask): bool => $subtask->estimated_minutes !== null);
            $estimated = $derived
                ? (int) collect($subtasks)->sum(fn (Task $subtask): int => (int) $subtask->estimated_minutes)
                : $root->estimated_minutes;
            $rootActual = $actual[$root->id] ?? 0;
            $total = $rootActual + (int) collect($subtasks)->sum(fn (Task $subtask): int => $actual[$subtask->id] ?? 0);

            // Por tipo: la estimación cuenta donde vive (subtareas estimadas o la tarea principal) y
            // las horas, en el tipo de la tarea en la que se imputaron.
            $addType($root, $derived ? 0 : (int) $root->estimated_minutes, $rootActual);
            foreach ($subtasks as $subtask) {
                $addType($subtask, $derived ? (int) $subtask->estimated_minutes : 0, $actual[$subtask->id] ?? 0);
            }

            $group = [$this->row($root, 0, $estimated, $total, $derived)];
            foreach ($subtasks as $subtask) {
                $group[] = $this->row($subtask, 1, $subtask->estimated_minutes, $actual[$subtask->id] ?? 0, false);
            }
            $groups[] = $group;

            $totals['tasks']++;
            $totals['estimated_minutes'] += (int) $estimated;
            $totals['actual_minutes'] += $total;
            if ($estimated !== null) {
                $totals['estimated_tasks']++;
                if ($total > $estimated) {
                    $totals['over_tasks']++;
                }
            }
        }

        foreach ($actual as $taskId => $minutes) {
            if (! $byId->has($taskId)) {
                $totals['other_minutes'] += $minutes;
                $totals['actual_minutes'] += $minutes;
            }
        }

        // Primero las que más se han desviado por encima de su estimación; después las que no
        // tienen estimación, por horas reales.
        usort($groups, function (array $a, array $b): int {
            $ea = $a[0]['estimated_minutes'];
            $eb = $b[0]['estimated_minutes'];

            if (($ea === null) !== ($eb === null)) {
                return $ea === null ? 1 : -1;
            }

            $da = $ea === null ? $a[0]['actual_minutes'] : $a[0]['actual_minutes'] - $ea;
            $db = $eb === null ? $b[0]['actual_minutes'] : $b[0]['actual_minutes'] - $eb;

            return [$db, $a[0]['id']] <=> [$da, $b[0]['id']];
        });

        $byType = array_values($byType);
        usort($byType, fn (array $a, array $b): int => [$b['actual_minutes'], $b['estimated_minutes']] <=> [$a['actual_minutes'], $a['estimated_minutes']]);

        return [
            'tasks' => array_merge(...($groups === [] ? [[]] : $groups)),
            'by_type' => $byType,
            'totals' => $totals,
        ];
    }

    /**
     * @return array{id: int, parent_id: int|null, depth: int, title: string, type: array{id: int, name: string, color: string}|null,
     *     status: array{name: string, color: string, category: string}, completed: bool, derived: bool,
     *     estimated_minutes: int|null, actual_minutes: int}
     */
    private function row(Task $task, int $depth, ?int $estimated, int $actual, bool $derived): array
    {
        return [
            'id' => $task->id,
            'parent_id' => $depth === 1 ? $task->parent_task_id : null,
            'depth' => $depth,
            'title' => $task->title,
            'type' => self::type($task),
            'status' => [
                'name' => $task->status->name,
                'color' => $task->status->color,
                'category' => $task->status->category->value,
            ],
            'completed' => $task->completed_at !== null,
            'derived' => $derived,
            'estimated_minutes' => $estimated,
            'actual_minutes' => $actual,
        ];
    }

    /**
     * @return array{id: int, name: string, color: string}|null
     */
    private static function type(Task $task): ?array
    {
        return $task->type === null ? null : ['id' => $task->type->id, 'name' => $task->type->name, 'color' => $task->type->color];
    }
}
