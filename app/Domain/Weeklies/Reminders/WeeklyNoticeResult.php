<?php

namespace App\Domain\Weeklies\Reminders;

/**
 * Resultado de un envío de WeeklyNotifier: personas avisadas (con algún canal en cola), filas
 * omitidas (sin canal o desactivado) y filas que ya existían para ese disparo (duplicados evitados).
 */
final readonly class WeeklyNoticeResult
{
    public function __construct(
        public int $notified,
        public int $skipped,
        public int $duplicates,
    ) {}
}
