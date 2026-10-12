<?php

namespace App\Enums;

/**
 * Cada cuánto se genera una factura recurrente (PLAN-EMISION §3.1, H-057; E3): mensual, bimestral,
 * trimestral, semestral, anual u «otra» (cada N meses).
 */
enum RecurringPeriodicity: string
{
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';
    case Custom = 'custom';

    /** Meses entre una factura y la siguiente (null en «otra»: los dice la recurrente). */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Bimonthly => 2,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Annual => 12,
            self::Custom => null,
        };
    }

    /** La periodicidad de un número de meses (la fija si la hay; si no, «otra»). */
    public static function fromMonths(int $months): self
    {
        foreach (self::cases() as $case) {
            if ($case->months() === $months) {
                return $case;
            }
        }

        return self::Custom;
    }

    public function label(): string
    {
        return __("invoicing_work.periodicity.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
