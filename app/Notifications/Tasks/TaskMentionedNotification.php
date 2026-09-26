<?php

namespace App\Notifications\Tasks;

/**
 * Te han mencionado en la descripción o en un comentario de una tarea (SPEC §13).
 */
class TaskMentionedNotification extends TaskNotification
{
    public const string IN_DESCRIPTION = 'description';

    public const string IN_COMMENT = 'comment';

    public function __construct(
        int $taskId,
        int $projectId,
        string $taskTitle,
        string $actorName,
        public readonly string $context,
        public readonly string $excerpt,
    ) {
        parent::__construct($taskId, $projectId, $taskTitle, $actorName);
    }

    public function kind(): string
    {
        return 'task.mentioned';
    }

    public function title(object $notifiable): string
    {
        $key = $this->context === self::IN_COMMENT ? 'tasks.notifications.mentioned_comment' : 'tasks.notifications.mentioned_description';

        return __($key, ['actor' => $this->actorName, 'task' => $this->taskTitle]);
    }

    public function body(object $notifiable): ?string
    {
        return $this->excerpt === '' ? null : $this->excerpt;
    }

    public function icon(): ?string
    {
        return 'at-sign';
    }
}
