<?php

namespace App\Notifications\Tasks;

use App\Notifications\AppNotification;

/**
 * Base de las notificaciones de una tarea (SPEC §13): solo en la app (canal database) y con enlace
 * al panel de la tarea en su proyecto. Guarda datos planos (ids y textos) y no modelos: la cola
 * no falla si la tarea se borra antes de enviarse.
 */
abstract class TaskNotification extends AppNotification
{
    public function __construct(
        public readonly int $taskId,
        public readonly int $projectId,
        public readonly string $taskTitle,
        public readonly string $actorName,
    ) {}

    public function url(object $notifiable): ?string
    {
        return self::taskUrl($this->projectId, $this->taskId);
    }

    /**
     * /proyectos/{p}/tareas?tarea={t}: abre el panel de la tarea (resources/js/lib/urls.ts, task()).
     */
    public static function taskUrl(int $projectId, int $taskId): string
    {
        return "/proyectos/{$projectId}/tareas?tarea={$taskId}";
    }
}
