<?php

namespace App\Enums;

/**
 * Tipo de fila del registro de jornada (Fase 11, D-332 y D-333). Los cuatro primeros son fichajes;
 * `void` es la anulación de un fichaje por una corrección aceptada (va en la misma cadena de
 * huellas, nunca se borra nada).
 */
enum ClockEventKind: string
{
    case ClockIn = 'clock_in';
    case PauseStart = 'pause_start';
    case PauseEnd = 'pause_end';
    case ClockOut = 'clock_out';
    case Void = 'void';

    public function label(): string
    {
        return __("people.kinds.{$this->value}");
    }

    /** ¿Es un fichaje (y no una anulación)? */
    public function isPunch(): bool
    {
        return $this !== self::Void;
    }

    /** ¿Lleva modo presencial o a distancia? La entrada y la vuelta de la pausa (D-333). */
    public function takesWorkMode(): bool
    {
        return $this === self::ClockIn || $this === self::PauseEnd;
    }

    /**
     * Fichajes, en el orden de la jornada.
     *
     * @return list<string>
     */
    public static function punchValues(): array
    {
        return [self::ClockIn->value, self::PauseStart->value, self::PauseEnd->value, self::ClockOut->value];
    }
}
