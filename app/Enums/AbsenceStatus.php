<?php

namespace App\Enums;

/**
 * Estado de una ausencia (SPEC §4.1). Solo las aprobadas restan capacidad (SPEC §9).
 * `cancelled`: la retira la propia persona (si aún no ha empezado) o la anula quien la aprobó.
 */
enum AbsenceStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Solicitada',
            self::Approved => 'Aprobada',
            self::Rejected => 'Rechazada',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
