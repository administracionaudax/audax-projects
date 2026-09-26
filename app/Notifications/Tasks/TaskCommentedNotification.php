<?php

namespace App\Notifications\Tasks;

/**
 * Comentario nuevo en una tarea que sigues (SPEC §13). No llega al autor ni a quien ya recibió
 * el aviso de mención por ese mismo comentario.
 */
class TaskCommentedNotification extends TaskNotification
{
    public function __construct(
        int $taskId,
        int $projectId,
        string $taskTitle,
        string $actorName,
        public readonly string $excerpt,
    ) {
        parent::__construct($taskId, $projectId, $taskTitle, $actorName);
    }

    public function kind(): string
    {
        return 'task.commented';
    }

    public function title(object $notifiable): string
    {
        return __('tasks.notifications.commented', ['actor' => $this->actorName, 'task' => $this->taskTitle]);
    }

    public function body(object $notifiable): ?string
    {
        return $this->excerpt === '' ? null : $this->excerpt;
    }

    public function icon(): ?string
    {
        return 'message-square';
    }
}
