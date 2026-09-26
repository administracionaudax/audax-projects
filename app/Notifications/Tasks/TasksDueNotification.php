<?php

namespace App\Notifications\Tasks;

use App\Notifications\AppNotification;

/**
 * Resumen diario de tareas que vencen mañana o ya vencidas (SPEC §13). Lo envía el comando
 * app:notify-due-tasks: como máximo una notificación por persona y día. Lleva a Mis tareas.
 */
class TasksDueNotification extends AppNotification
{
    /**
     * Títulos que se citan en el cuerpo; el resto se resume con «y N más».
     */
    public const int TITLES_IN_BODY = 3;

    /**
     * @param  list<string>  $dueTomorrow  títulos de las tareas que vencen mañana
     * @param  list<string>  $overdue  títulos de las tareas vencidas
     */
    public function __construct(
        public readonly array $dueTomorrow,
        public readonly array $overdue,
    ) {}

    public function kind(): string
    {
        return 'task.due';
    }

    public function title(object $notifiable): string
    {
        $tomorrow = count($this->dueTomorrow);
        $overdue = count($this->overdue);

        if ($tomorrow > 0 && $overdue > 0) {
            return __('tasks.notifications.due_title_both', ['tomorrow' => $tomorrow, 'overdue' => $overdue]);
        }

        return $tomorrow > 0
            ? trans_choice('tasks.notifications.due_title_tomorrow', $tomorrow, ['count' => $tomorrow])
            : trans_choice('tasks.notifications.due_title_overdue', $overdue, ['count' => $overdue]);
    }

    public function body(object $notifiable): ?string
    {
        $titles = [...$this->overdue, ...$this->dueTomorrow];
        $shown = array_map(fn (string $title): string => "«{$title}»", array_slice($titles, 0, self::TITLES_IN_BODY));
        $rest = count($titles) - count($shown);

        $body = implode(', ', $shown);

        return $rest > 0 ? $body.' '.__('tasks.notifications.due_more', ['count' => $rest]) : $body;
    }

    public function url(object $notifiable): ?string
    {
        return '/mis-tareas';
    }

    public function icon(): ?string
    {
        return 'calendar-clock';
    }
}
