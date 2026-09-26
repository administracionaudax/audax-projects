<?php

namespace App\Notifications\Tasks;

/**
 * Cambio de estado de una tarea que sigues (SPEC §13). No llega a quien hace el cambio.
 */
class TaskStatusChangedNotification extends TaskNotification
{
    public function __construct(
        int $taskId,
        int $projectId,
        string $taskTitle,
        string $actorName,
        public readonly string $statusName,
        public readonly string $projectName,
    ) {
        parent::__construct($taskId, $projectId, $taskTitle, $actorName);
    }

    public function kind(): string
    {
        return 'task.status_changed';
    }

    public function title(object $notifiable): string
    {
        return __('tasks.notifications.status_changed', ['task' => $this->taskTitle, 'status' => $this->statusName]);
    }

    public function body(object $notifiable): ?string
    {
        return __('tasks.notifications.status_changed_body', ['actor' => $this->actorName, 'project' => $this->projectName]);
    }

    public function icon(): ?string
    {
        return 'circle-dot';
    }
}
