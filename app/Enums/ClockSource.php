<?php

namespace App\Enums;

/**
 * Desde dónde llega cada fila del registro (D-332): la web, la app instalada (PWA) o una corrección
 * aceptada. R5 añadirá la importación del histórico de Woffu, que no va a esta tabla.
 */
enum ClockSource: string
{
    case Web = 'web';
    case Pwa = 'pwa';
    case Correction = 'correction';

    public function label(): string
    {
        return __("people.sources.{$this->value}");
    }
}
