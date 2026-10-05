<?php

namespace App\Events\Weeklies;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una semana se ha cerrado y su satisfacción ya está calculada (F-095, D-191): es el momento en que
 * WeeklySync enviaba el email «weekly cerrada» a todo el equipo activo. Lo lanza el Job
 * UpdateWeeklySatisfaction (cola `ai`) al terminar, también si la IA falla. El aviso (app, email y
 * push), sus plantillas y su registro los escucha la entrega 10.5.
 */
final class WeeklyCycleClosed
{
    use Dispatchable;

    public function __construct(
        public readonly int $cycleId,
        public readonly ?int $closedBy,
        public readonly ?int $nextCycleId,
    ) {}
}
