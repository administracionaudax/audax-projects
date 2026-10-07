<?php

namespace App\Enums;

/**
 * Estado de un cierre mensual del registro (PLAN-FASE-11 §7.5; D-347; W-084 y W-085):
 * - `pending`: generado, espera que la persona lo confirme o diga que no está de acuerdo,
 * - `confirmed`: la persona lo ha confirmado; el mes queda bloqueado para las correcciones,
 * - `disagreed`: la persona no está de acuerdo (con su motivo); no bloquea nada,
 * - `reopened`: su responsable o RR. HH. lo ha desconfirmado con un motivo; el mes vuelve a
 *   admitir correcciones y el siguiente cierre es una versión nueva,
 * - `superseded`: sustituido por una versión nueva antes de confirmarse (cambió el registro del mes).
 */
enum MonthCloseStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Disagreed = 'disagreed';
    case Reopened = 'reopened';
    case Superseded = 'superseded';

    public function label(): string
    {
        return __("people.close_status.{$this->value}");
    }

    /** ¿Es la versión vigente del mes (no desconfirmada ni sustituida)? */
    public function isCurrent(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Disagreed], true);
    }

    /** ¿Espera la respuesta de la persona? */
    public function awaitsAnswer(): bool
    {
        return $this === self::Pending;
    }
}
