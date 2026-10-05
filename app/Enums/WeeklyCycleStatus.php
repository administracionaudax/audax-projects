<?php

namespace App\Enums;

/**
 * Estado de una semana de la weekly (D-150): una sola activa; se cierra a mano con el informe y el audio.
 */
enum WeeklyCycleStatus: string
{
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return __("weeklies.enums.cycle_status.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
