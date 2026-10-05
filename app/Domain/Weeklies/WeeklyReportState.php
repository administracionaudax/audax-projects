<?php

namespace App\Domain\Weeklies;

use App\Models\WeeklyCycle;

/**
 * Estado del informe de una semana para la interfaz (F-072 y F-089). Lógica pura.
 */
final class WeeklyReportState
{
    /** «Hay nuevos reportes»: hay más envíos que cuando se generó. */
    public static function isStale(WeeklyCycle $cycle, int $submittedCount): bool
    {
        return $cycle->report !== null
            && $cycle->submission_count_at_generation !== null
            && $submittedCount > $cycle->submission_count_at_generation;
    }

    /**
     * ¿Se puede cerrar? Exige el texto y el audio generados (F-089); las personas pendientes no lo
     * impiden (solo se avisa). Devuelve los motivos que lo bloquean: report, audio o not_active.
     *
     * @return list<string>
     */
    public static function closeBlockers(WeeklyCycle $cycle, bool $hasAudio): array
    {
        return array_values(array_filter([
            $cycle->isActive() ? null : 'not_active',
            $cycle->report === null ? 'report' : null,
            $hasAudio ? null : 'audio',
        ]));
    }
}
