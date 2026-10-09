<?php

namespace App\Enums;

/**
 * Calificación de la operación de un tipo de impuesto (PLAN-EMISION §4.1 y §5.3; D-423), con las
 * claves del desglose de VeriFactu: S1 sujeta y no exenta, S2 sujeta con inversión del sujeto pasivo,
 * N1 no sujeta (arts. 7 y 14), N2 no sujeta por reglas de localización, E1…E6 exenta por su artículo.
 */
enum TaxOperationType: string
{
    case S1 = 'S1';
    case S2 = 'S2';
    case N1 = 'N1';
    case N2 = 'N2';
    case E1 = 'E1';
    case E2 = 'E2';
    case E3 = 'E3';
    case E4 = 'E4';
    case E5 = 'E5';
    case E6 = 'E6';

    public function label(): string
    {
        return __("invoicing.enums.operation_type.{$this->value}");
    }

    /** ¿Lleva cuota repercutida? Solo la sujeta y no exenta sin inversión del sujeto pasivo. */
    public function charges(): bool
    {
        return $this === self::S1;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
