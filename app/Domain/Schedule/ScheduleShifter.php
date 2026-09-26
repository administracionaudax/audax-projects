<?php

namespace App\Domain\Schedule;

use App\Domain\Tasks\TaskWriter;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Aplica una propuesta de ScheduleConflicts cuando la persona la confirma (SPEC §6.1): cambia las
 * fechas de cada sucesora con TaskWriter, comprobando TaskPolicy::update en cada una, todo o nada.
 */
final class ScheduleShifter
{
    public function __construct(private readonly TaskWriter $writer) {}

    /**
     * @param  list<array{task_id: int, new_start_date: string|null, new_due_date: string|null}>  $proposals
     * @return int tareas desplazadas
     */
    public function apply(User $actor, int $projectId, array $proposals): int
    {
        return DB::transaction(function () use ($actor, $projectId, $proposals): int {
            $tasks = Task::query()->where('project_id', $projectId)
                ->whereIn('id', array_column($proposals, 'task_id'))
                ->with('project')
                ->get()
                ->keyBy('id');

            $count = 0;
            foreach ($proposals as $proposal) {
                $task = $tasks->get($proposal['task_id']);
                if ($task === null) {
                    continue;
                }
                Gate::forUser($actor)->authorize('update', $task);
                $this->writer->update($actor, $task, [
                    'start_date' => $proposal['new_start_date'],
                    'due_date' => $proposal['new_due_date'],
                ]);
                $count++;
            }

            return $count;
        });
    }
}
