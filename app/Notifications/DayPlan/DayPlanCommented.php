<?php

namespace App\Notifications\DayPlan;

use App\Notifications\AppNotification;
use Illuminate\Support\Str;

/**
 * Comentario de su responsable (o de un admin) en una línea de mi plan del día (§9, D-255). Solo en
 * la app por defecto. El texto de la línea y el comentario van recortados.
 */
class DayPlanCommented extends AppNotification
{
    public function __construct(
        public readonly string $author,
        public readonly string $line,
        public readonly string $comment,
        public readonly string $date,
    ) {}

    public function kind(): string
    {
        return 'day_plan.commented';
    }

    public function title(object $notifiable): string
    {
        return (string) __('day_plan.notifications.commented_title', ['name' => $this->author]);
    }

    public function body(object $notifiable): ?string
    {
        return (string) __('day_plan.notifications.commented_body', [
            'line' => Str::limit($this->line, 80),
            'comment' => Str::limit($this->comment, 160),
        ]);
    }

    public function url(object $notifiable): ?string
    {
        return '/dia?fecha='.$this->date;
    }

    public function icon(): ?string
    {
        return 'message-square';
    }
}
