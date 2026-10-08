<?php

namespace App\Enums;

/**
 * Régimen de IVA del cliente (Fase 12, D-381; PLAN-FASE-12 §2.4 y H-066): decide la mención de la
 * factura cuando Audax emita (F5). En F1 solo se guarda y se lee de Holded.
 */
enum TaxRegime: string
{
    /** España: IVA español. */
    case General = 'general';
    /** Empresario de la UE: sin IVA, «Inversión del sujeto pasivo» y modelo 349. */
    case IntraEu = 'intra_eu';
    /** Fuera de la UE: no sujeto en España. */
    case Export = 'export';
    case Exempt = 'exempt';
    case NotSubject = 'not_subject';

    public function label(): string
    {
        return __("billing.enums.tax_regime.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
