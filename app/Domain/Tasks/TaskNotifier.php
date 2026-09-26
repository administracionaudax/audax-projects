<?php

namespace App\Domain\Tasks;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\Tasks\TaskAssignedNotification;
use App\Notifications\Tasks\TaskCommentedNotification;
use App\Notifications\Tasks\TaskMentionedNotification;
use App\Notifications\Tasks\TaskStatusChangedNotification;
use App\Support\RichText;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Notificaciones de tareas en la app (SPEC §13, solo canal database):
 * - asignación: al nuevo responsable, si no es quien asigna,
 * - mención (descripción o comentario): solo a los mencionados nuevos, internos activos, sin el autor,
 * - comentario en una tarea que sigo: a los seguidores salvo el autor y los ya avisados por mención,
 * - cambio de estado de una tarea que sigo: a los seguidores salvo quien lo cambia.
 *
 * Dentro de capture() los avisos se guardan y se envían solo si todo el trabajo termina bien
 * (acciones masivas: si una tarea falla y se deshace todo, no sale ningún aviso).
 */
#[Scoped]
final class TaskNotifier
{
    /**
     * @var list<callable(): void>|null
     */
    private ?array $pending = null;

    /**
     * Ejecuta $work y envía los avisos que genere solo si termina sin excepción.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function capture(callable $work): mixed
    {
        if ($this->pending !== null) {
            return $work();
        }

        $this->pending = [];

        try {
            $result = $work();
            $pending = $this->pendingSends();
        } finally {
            $this->pending = null;
        }

        foreach ($pending as $send) {
            $send();
        }

        return $result;
    }

    /**
     * @return list<callable(): void>
     */
    private function pendingSends(): array
    {
        return $this->pending ?? [];
    }

    /**
     * @return list<int> Ids avisados.
     */
    public function assigned(Task $task, User $actor): array
    {
        if ($task->assignee_user_id === null || $task->assignee_user_id === $actor->id) {
            return [];
        }

        $recipients = $this->recipients([$task->assignee_user_id]);

        $this->send($recipients, new TaskAssignedNotification(
            $task->id,
            $task->project_id,
            $task->title,
            $actor->name,
            $task->project->name,
        ));

        return $this->ids($recipients);
    }

    /**
     * Avisa a los mencionados nuevos (sin el autor ni quien no sea interno activo).
     *
     * @param  list<int>  $userIds
     * @return list<int> Ids avisados.
     */
    public function mentioned(Task $task, User $actor, array $userIds, string $context, ?string $html): array
    {
        $recipients = $this->recipients(array_values(array_diff($userIds, [$actor->id])));

        $this->send($recipients, new TaskMentionedNotification(
            $task->id,
            $task->project_id,
            $task->title,
            $actor->name,
            $context,
            RichText::toPlainText($html, 140),
        ));

        return $this->ids($recipients);
    }

    /**
     * @param  list<int>  $alreadyNotified  mencionados en ese comentario (ya tienen su aviso)
     * @return list<int> Ids avisados.
     */
    public function commented(Task $task, User $actor, TaskComment $comment, array $alreadyNotified): array
    {
        $watcherIds = $task->watchers()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
        $recipients = $this->recipients(array_values(array_diff($watcherIds, [$actor->id], $alreadyNotified)));

        $this->send($recipients, new TaskCommentedNotification(
            $task->id,
            $task->project_id,
            $task->title,
            $actor->name,
            RichText::toPlainText($comment->body, 140),
        ));

        return $this->ids($recipients);
    }

    /**
     * @param  list<int>  $except  ya avisados en la misma operación (p. ej. el nuevo responsable)
     * @return list<int> Ids avisados.
     */
    public function statusChanged(Task $task, User $actor, TaskStatus $status, array $except = []): array
    {
        $watcherIds = $task->watchers()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
        $recipients = $this->recipients(array_values(array_diff($watcherIds, [$actor->id], $except)));

        $this->send($recipients, new TaskStatusChangedNotification(
            $task->id,
            $task->project_id,
            $task->title,
            $actor->name,
            $status->name,
            $task->project->name,
        ));

        return $this->ids($recipients);
    }

    /**
     * Solo personas internas y activas (un cliente nunca recibe avisos de la app interna).
     *
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function recipients(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return new Collection;
        }

        return User::query()->whereKey($ids)->active()->internal()->get();
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @return list<int>
     */
    private function ids(Collection $recipients): array
    {
        return array_values($recipients->map(fn (User $user): int => $user->id)->all());
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function send(Collection $recipients, object $notification): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        $dispatch = fn () => Notification::send($recipients, $notification);

        if ($this->pending !== null) {
            $this->pending[] = $dispatch;

            return;
        }

        $dispatch();
    }
}
