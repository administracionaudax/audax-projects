<?php

namespace App\Notifications\Tasks;

use App\Notifications\AppNotification;

/**
 * Base de las notificaciones de una tarea (SPEC §13): solo en la app (canal database) y con enlace
 * estable a la tarea (/tareas/{id}), que redirige al panel en el proyecto que tenga AL PULSAR: el
 * enlace sigue valiendo aunque la tarea se mueva de proyecto después del aviso. Guarda datos
 * planos (ids y textos) y no modelos: la cola no falla si la tarea se borra antes de enviarse.
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
        return self::taskUrl($this->taskId);
    }

    /**
     * /tareas/{t} (tasks.show): abre el panel de la tarea en su proyecto actual
     * (resources/js/lib/urls.ts, taskById()).
     */
    public static function taskUrl(int $taskId): string
    {
        return "/tareas/{$taskId}";
    }
}
