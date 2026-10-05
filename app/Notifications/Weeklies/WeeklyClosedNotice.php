<?php

namespace App\Notifications\Weeklies;

/**
 * «Weekly cerrada» (F-095 y F-105): a todo el equipo activo que escribe la weekly cuando se cierra la
 * semana y su satisfacción ya está calculada (evento WeeklyCycleClosed), con la plantilla
 * weekly_closed y el enlace al informe, como hacía close-week-and-update-satisfaction.
 */
class WeeklyClosedNotice extends WeeklyNotice
{
    public function kind(): string
    {
        return 'weeklies.closed';
    }

    public function body(object $notifiable): ?string
    {
        return self::text('weeklies.reminders.notice.closed_body', ['label' => $this->cycleLabel]);
    }

    public function icon(): ?string
    {
        return 'file-check';
    }

    protected function path(): string
    {
        return route('weeklies.show', ['cycle' => $this->cycleId], absolute: false);
    }

    protected function actionLabel(): string
    {
        return self::text('weeklies.reminders.notice.closed_action');
    }
}
