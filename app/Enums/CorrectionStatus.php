<?php

namespace App\Enums;

/**
 * Estado de una corrección del registro (D-335):
 * - `pending`: espera la conformidad de la otra parte,
 * - `accepted`: las dos partes están de acuerdo; sus fichajes y anulaciones ya están en la cadena,
 * - `disputed`: no hay acuerdo (la otra parte la rechaza con un motivo o no contesta en 7 días);
 *   constan las dos versiones y cuenta la original,
 * - `withdrawn`: quien la propuso la retira antes de que se decida.
 */
enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Disputed = 'disputed';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return __("people.correction_status.{$this->value}");
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
