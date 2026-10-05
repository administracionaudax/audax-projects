<?php

namespace App\Listeners\Weeklies;

use App\Domain\Weeklies\Reminders\WeeklyReminders;
use App\Events\Weeklies\WeeklyCycleClosed;
use App\Models\WeeklyCycle;

/**
 * «Weekly cerrada» (F-095, D-191 y D-199): al terminar la satisfacción de la semana cerrada (evento
 * WeeklyCycleClosed, que lanza UpdateWeeklySatisfaction en la cola `ai`), el aviso con el enlace al
 * informe a todo el equipo activo, una sola vez (clave closed:{semana}).
 */
class SendWeeklyClosedNotice
{
    public function __construct(private readonly WeeklyReminders $reminders) {}

    public function handle(WeeklyCycleClosed $event): void
    {
        $cycle = WeeklyCycle::query()->find($event->cycleId);

        if ($cycle === null || ! $cycle->isClosed()) {
            return;
        }

        $this->reminders->notifyClosed($cycle);
    }
}
