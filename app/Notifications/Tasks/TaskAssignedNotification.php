<?php

namespace App\Notifications\Tasks;

/**
 * Te han asignado una tarea (SPEC §13). No se envía si te la asignas tú.
 */
class TaskAssignedNotification extends TaskNotification
{
    public function __construct(
        int $taskId,
        int $projectId,
        string $taskTitle,
        string $actorName,
        public readonly string $projectName,
    ) {
        parent::__construct($taskId, $projectId, $taskTitle, $actorName);
    }

    public function kind(): string
    {
        return 'task.assigned';
    }

    public function title(object $notifiable): string
    {
        return __('tasks.notifications.assigned', ['actor' => $this->actorName, 'task' => $this->taskTitle]);
    }

    public function body(object $notifiable): ?string
    {
        return $this->projectName;
    }

    public function icon(): ?string
    {
        return 'user-check';
    }
}
