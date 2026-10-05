<?php

namespace App\Enums;

/**
 * Motivo de una fila de weekly_exemptions (D-150 y D-151):
 * - absence: ausencia aprobada que cubre el plazo. Mientras la semana está activa se calcula al
 *   vuelo (WeeklyEligibility); al cerrar se congela con esta fila (F-092),
 * - manual: exención puesta por quien gestiona la weekly (F-038),
 * - waived: la persona renuncia a la exención que le daba su ausencia y quiere enviar (F-053).
 */
enum WeeklyExemptionReason: string
{
    case Absence = 'absence';
    case Manual = 'manual';
    case Waived = 'waived';

    /** ¿Exime de enviar? Una renuncia no. */
    public function exempts(): bool
    {
        return $this !== self::Waived;
    }

    public function label(): string
    {
        return __("weeklies.enums.exemption_reason.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
