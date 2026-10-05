<?php

namespace App\Domain\Import\WeeklySync\Stages;

use App\Domain\Import\WeeklySync\WeeklySyncContext;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport as Report;
use App\Domain\Import\WeeklySync\WeeklySyncText;
use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatusCategory;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskArchive;
use App\Models\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tareas de WeeklySync (D-149 y D-216): no tienen proyecto, así que solo entran si su cliente tiene
 * un proyecto claro en Audax (uno solo, o uno solo sin archivar). El resto se lista en los avisos.
 * Su «archivada» pasa al archivado personal de quien la tiene asignada (`task_archives`).
 */
final class TasksStage
{
    /** @var array<int, int|null> cliente => proyecto (null: ninguno claro) */
    private array $projects = [];

    /** @var array<int, int> proyecto => última posición */
    private array $positions = [];

    public function run(WeeklySyncContext $context): void
    {
        $rows = $context->rows('tasks');

        if ($rows === []) {
            return;
        }

        TaskStatus::ensureDefaults();
        $done = TaskStatus::query()->where('category', TaskStatusCategory::Done->value)->ordered()->first() ?? TaskStatus::defaultStatus();
        $todo = TaskStatus::defaultStatus();

        DB::transaction(function () use ($context, $rows, $done, $todo): void {
            foreach ($rows as $row) {
                $this->task($context, $row, $done, $todo);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function task(WeeklySyncContext $context, array $row, TaskStatus $done, TaskStatus $todo): void
    {
        $id = WeeklySyncContext::id($row['id'] ?? null);
        $title = Str::limit(trim((string) preg_replace('/\s+/u', ' ', WeeklySyncContext::str($row['description'] ?? ''))), 255, '');
        $title = $title !== '' ? $title : 'Tarea de WeeklySync';
        $assignee = $context->user($row['assignee_id'] ?? null);
        $client = $context->client($row['client_id'] ?? null);
        $project = $client !== null ? $this->project($client) : null;

        if ($project === null) {
            $reason = $client === null ? 'Tareas sin cliente' : 'Tareas de clientes sin un proyecto claro';
            $context->report->skip('tasks', $reason);
            $context->report->warn("Tarea no migrada ({$reason}): «".Str::limit($title, 80).'».');

            return;
        }

        $local = $context->refs->find('task', $id);
        $task = $local !== null ? Task::withTrashed()->find($local) : null;
        $created = $task === null;
        $isDone = strtoupper(WeeklySyncContext::str($row['status'] ?? '')) === 'DONE';

        if ($task === null) {
            $task = new Task;
            $this->positions[$project] ??= (int) Task::withTrashed()->where('project_id', $project)->max('position');
            $task->position = ++$this->positions[$project];

            if (($createdAt = WeeklySyncContext::instant($row['created_at'] ?? null)) !== null) {
                $task->created_at = $createdAt;
            }
        }

        $task->fill([
            'project_id' => $project,
            'title' => $title,
            'description' => WeeklySyncText::rich(WeeklySyncContext::nullableStr($row['notes'] ?? null)),
            'status_id' => $isDone ? $done->id : $todo->id,
            'priority' => match (strtoupper(WeeklySyncContext::str($row['priority'] ?? ''))) {
                'HIGH' => TaskPriority::High,
                'LOW' => TaskPriority::Low,
                default => TaskPriority::Normal,
            },
            'assignee_user_id' => $assignee,
            'due_date' => WeeklySyncContext::date($row['due_date'] ?? null),
            'created_by' => $context->user($row['assigner_id'] ?? null),
        ]);

        $completedAt = $isDone ? (WeeklySyncContext::instant($row['updated_at'] ?? null) ?? WeeklySyncContext::instant($row['created_at'] ?? null)) : null;
        if ($task->completed_at?->getTimestamp() !== $completedAt?->getTimestamp()) {
            $task->forceFill(['completed_at' => $completedAt]);
        }

        $dirty = $created || $task->isDirty();
        if ($dirty) {
            $task->save();
        }

        $context->refs->put('task', $id, 'task', $task->id);

        $archived = false;
        if (($row['archived'] ?? false) === true && $assignee !== null) {
            $archive = TaskArchive::query()->firstOrNew(['user_id' => $assignee, 'task_id' => $task->id]);
            if (! $archive->exists) {
                $archive->archived_at = WeeklySyncContext::instant($row['updated_at'] ?? null) ?? now()->toImmutable();
                $archive->save();
                $archived = true;
            }
        }

        $context->report->count('tasks', match (true) {
            $created => Report::CREATED,
            $dirty || $archived => Report::UPDATED,
            default => Report::UNCHANGED,
        });
    }

    /**
     * El proyecto de un cliente para sus tareas: el único que tiene o, si tiene varios, el único
     * sin archivar ni terminar. Si no, ninguno.
     */
    private function project(int $client): ?int
    {
        if (! array_key_exists($client, $this->projects)) {
            $projects = Project::query()->where('client_id', $client)->get(['id', 'status']);
            $open = $projects->filter(fn (Project $project): bool => ! in_array($project->status, [ProjectStatus::Archived, ProjectStatus::Completed], true));

            $this->projects[$client] = match (true) {
                $projects->count() === 1 => (int) $projects->first()?->id,
                $open->count() === 1 => (int) $open->first()?->id,
                default => null,
            };
        }

        return $this->projects[$client];
    }
}
