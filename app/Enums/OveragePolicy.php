<?php

namespace App\Enums;

/**
 * Política de exceso de una bolsa (SPEC §8.6). `inherit` usa el ajuste global
 * `allow_hour_bank_overage`; HourBankLedger::effectivePolicy() la resuelve a allow o block.
 */
enum OveragePolicy: string
{
    case Inherit = 'inherit';
    case Allow = 'allow';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Inherit => 'Según el ajuste general',
            self::Allow => 'Permitir exceso',
            self::Block => 'Bloquear el exceso',
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
