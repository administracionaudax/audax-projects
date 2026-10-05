<?php

namespace App\Http\Resources\Tasks;

use App\Domain\Planning\TaskDependencyList;
use App\Domain\Tasks\TaskActivityFeed;
use App\Http\Resources\TaskResource;
use App\Http\Resources\TimeEntryResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\ActiveTimer;
use App\Models\CommentReaction;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Datos del panel lateral de una tarea (contrato: resources/js/types/tasks.ts, TaskPanelData).
 * Se carga con una recarga parcial de Inertia (prop `panel`) al abrir ?tarea={id}.
 *
 * Las horas son solo las que quien mira puede ver (TimeEntry::visibleTo, D-021). Las dependencias
 * (predecesoras y sucesoras, con su conflicto) llegan en la Fase 4 (D-062, TaskDependencyList).
 */
final class TaskPanel
{
    public const int TIME_ENTRIES_LIMIT = 50;

    public function __construct(
        private readonly TaskActivityFeed $activity,
        private readonly TaskDependencyList $dependencies,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Task $task, Project $project, User $viewer): array
    {
        $task->setRelation('project', $project);
        $task->load([
            'assignee',
            'creator',
            'parent:id,title,project_id',
            'subtasks' => fn ($query) => $query->with('assignee')->withSum('timeEntries', 'minutes')->orderBy('position')->orderBy('id'),
            'watchers' => fn ($query) => $query->orderBy('name'),
            'attachments' => fn ($query) => $query->with('uploader')->oldest('id'),
            'comments' => fn ($query) => $query->with(['author', 'reactions.user', 'attachments' => fn ($attachments) => $attachments->with('uploader')->oldest('id')])->oldest('id'),
        ]);
        $task->loadSum('timeEntries', 'minutes');
        // Registrado total = propio + subtareas (D-160), con las subtareas ya cargadas.
        TaskListItemResource::withSubtasksLogged($task);

        $canUpdate = Gate::forUser($viewer)->allows('update', $task);
        $canManage = $viewer->canManageProject($project);
        $blocked = $this->deleteBlockedReason($task);

        $entries = TimeEntry::query()
            ->where('task_id', $task->id)
            ->visibleTo($viewer)
            ->with('user')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::TIME_ENTRIES_LIMIT)
            ->get();

        $visibleMinutes = (int) TimeEntry::query()->where('task_id', $task->id)->visibleTo($viewer)->sum('minutes');
        $fromSubtasks = $task->subtasks->whereNotNull('estimated_minutes')->isNotEmpty();

        return [
            'task' => [
                ...Plain::of(TaskResource::make($task)),
                'creator' => $task->creator === null ? null : Plain::of(UserSummaryResource::make($task->creator)),
                'created_at' => $task->created_at?->toIso8601ZuluString(),
                'updated_at' => $task->updated_at?->toIso8601ZuluString(),
            ],
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'uses_hour_banks' => $project->usesHourBanks(),
                'is_internal' => $project->isInternal(),
            ],
            'parent' => $task->parent === null ? null : ['id' => $task->parent->id, 'title' => $task->parent->title],
            'subtasks' => Plain::of(TaskListItemResource::collection($task->subtasks)),
            'estimate_from_subtasks' => $fromSubtasks,
            'effective_estimated_minutes' => $fromSubtasks ? (int) $task->subtasks->sum('estimated_minutes') : $task->estimated_minutes,
            'watchers' => Plain::of(UserSummaryResource::collection($task->watchers)),
            'is_watching' => $task->watchers->contains('id', $viewer->id),
            'attachments' => AttachmentResource::listFor($task->attachments, $viewer, $canManage),
            'comments' => TaskCommentResource::listFor($task->comments, $viewer, $canManage),
            'time_entries' => Plain::of(TimeEntryResource::collection($entries)),
            'time_visible_minutes' => $visibleMinutes,
            'has_time' => (int) ($task->time_entries_sum_minutes ?? 0) > 0
                || (int) ($task->getAttribute('subtasks_logged_minutes') ?? 0) > 0,
            'activity' => $this->activity->for($task),
            'dependencies' => $this->dependencies->for($task),
            'reaction_emojis' => CommentReaction::EMOJIS,
            'delete_blocked' => $blocked,
            'source_message' => $this->sourceMessage($task, $viewer),
            'can' => [
                'update' => $canUpdate,
                'delete' => $canUpdate && $blocked === null,
                'comment' => Gate::forUser($viewer)->allows('comment', $task),
                'move' => $canUpdate && ! $task->isSubtask(),
                'log_time' => ! $task->is_milestone && (Gate::forUser($viewer)->allows('logTime', $project) || $canManage),
            ],
        ];
    }

    /**
     * Mensaje del chat desde el que se creó la tarea (SPEC §12, Fase 6): el enlace «Ver mensaje»,
     * solo si quien mira ve esa conversación (sus participantes y el admin, D-071). Una consulta.
     *
     * @return array{conversation_id: int, message_id: int}|null
     */
    private function sourceMessage(Task $task, User $viewer): ?array
    {
        $message = Message::query()
            ->where('task_id', $task->id)
            ->unless($viewer->isAdmin(), fn (Builder $query) => $query->whereHas('conversation.participants', fn (Builder $participants) => $participants
                ->where('user_id', $viewer->id)
                ->whereNull('left_at')))
            ->oldest('id')
            ->first(['id', 'conversation_id']);

        return $message === null ? null : ['conversation_id' => $message->conversation_id, 'message_id' => $message->id];
    }

    /**
     * Motivo por el que no se puede borrar (D-037: nada con horas se borra), o null.
     *
     * @return 'has_time'|'subtasks_have_time'|'timer_running'|null
     */
    public function deleteBlockedReason(Task $task): ?string
    {
        if ($task->timeEntries()->exists()) {
            return 'has_time';
        }

        $subtaskIds = $task->subtasks()->pluck('id');

        if ($subtaskIds->isNotEmpty() && TimeEntry::query()->whereIn('task_id', $subtaskIds)->exists()) {
            return 'subtasks_have_time';
        }

        if (ActiveTimer::query()->whereIn('task_id', [$task->id, ...$subtaskIds])->exists()) {
            return 'timer_running';
        }

        return null;
    }
}
