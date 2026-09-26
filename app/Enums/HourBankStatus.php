<?php

namespace App\Enums;

/**
 * Estado de una bolsa de horas (SPEC §4.2 y §8). `active` ↔ `exhausted` los calcula
 * HourBankLedger según el consumo; `closed` y `renewed` son manuales y definitivos
 * (un admin puede reabrir una cerrada no renovada, D-035).
 */
enum HourBankStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Closed = 'closed';
    case Renewed = 'renewed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Exhausted => 'Agotada',
            self::Closed => 'Cerrada',
            self::Renewed => 'Renovada',
        };
    }

    /**
     * En bolsas cerradas o renovadas no se imputa (SPEC §7).
     */
    public function acceptsTime(): bool
    {
        return $this === self::Active || $this === self::Exhausted;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
