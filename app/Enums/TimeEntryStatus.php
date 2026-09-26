<?php

namespace App\Enums;

/**
 * Estado de una entrada de horas (SPEC §4.4). Todas cuentan para el consumo de la bolsa
 * desde el primer momento (SPEC §8.4). Las bloqueadas solo las edita un admin (SPEC §7).
 */
enum TimeEntryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Submitted => 'Enviada',
            self::Approved => 'Aprobada',
            self::Locked => 'Bloqueada',
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
