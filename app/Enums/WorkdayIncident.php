<?php

namespace App\Enums;

/**
 * Incidencias de un día del registro (PLAN-FASE-11 §7.3, W-083; D-338). Avisan, nunca bloquean ni
 * corrigen nada: la persona propone una corrección si hace falta.
 */
enum WorkdayIncident: string
{
    /** Hay entrada y no hay salida (día pasado, o jornada abierta hace más de 16 h). */
    case MissingClockOut = 'missing_clock_out';
    /** Día pasado con jornada teórica, sin ausencia y sin ningún fichaje. */
    case NoRecords = 'no_records';
    /** Fichajes en un día de ausencia aprobada de día completo. */
    case DuringAbsence = 'during_absence';
    /** Día cerrado con 30 minutos o más por debajo de la jornada teórica. */
    case ShortDay = 'short_day';
    /** Menos de 12 h desde la salida de la jornada anterior (art. 34.3 ET). */
    case ShortRest = 'short_rest';
    /** Más de 6 h seguidas sin pausa (art. 34.4 ET). */
    case LongStretch = 'long_stretch';
    /** Más de 9 h trabajadas en el día (art. 34.3 ET). */
    case OverNineHours = 'over_nine_hours';
    /** Salida fichada estando en la pausa. */
    case PauseOpen = 'pause_open';

    public function label(): string
    {
        return __("people.incidents.{$this->value}");
    }

    /**
     * ¿Pide hacer algo (proponer una corrección)? Las otras son avisos de los límites legales.
     */
    public function needsAction(): bool
    {
        return in_array($this, [self::MissingClockOut, self::NoRecords, self::PauseOpen], true);
    }
}
