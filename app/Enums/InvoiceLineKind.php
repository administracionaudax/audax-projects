<?php

namespace App\Enums;

use App\Domain\Billing\Holded\HoldedContactMatcher;

/**
 * Qué vende una línea de factura de Holded (Fase 12, D-396), por el servicio del catálogo de Audax
 * en Holded (su código y, si no, su nombre):
 * - **bolsa** (bolsadehoras, BDH): las unidades son HORAS y el precio, €/h,
 * - **fee** (Fee MK y RRSS FMKRRSS, Fee Producto digital F_UX): una unidad por el importe del mes,
 * - **repercutido** (Inversión, Herramienta): gasto de medios o licencias, no son horas de la agencia,
 * - **horas** (Desarrollo DES, Diseño Producto UX/UI D_UX_UI, Diseño Gráfico D_GR, SEO, auditorías,
 *   Mantenimiento…): con más de una unidad, las unidades son horas (8 × 70 €); con una, un importe
 *   cerrado («otro»).
 */
enum InvoiceLineKind: string
{
    case HourBank = 'bank';
    case Fee = 'fee';
    case Hours = 'hours';
    case PassThrough = 'pass_through';
    case Other = 'other';

    public const array BANK_CODES = ['BDH'];

    public const array FEE_CODES = ['FMKRRSS', 'F_UX'];

    public static function classify(?string $code, ?string $name, string $units): self
    {
        $code = strtoupper(trim((string) $code));
        $normalized = HoldedContactMatcher::normalizeName($name);
        $compact = str_replace(' ', '', $normalized);

        return match (true) {
            in_array($code, self::BANK_CODES, true) || str_contains($compact, 'bolsadehoras') || str_starts_with($normalized, 'bolsa horas') => self::HourBank,
            in_array($code, self::FEE_CODES, true) || str_starts_with($normalized, 'fee') => self::Fee,
            str_starts_with($normalized, 'inversion') || str_starts_with($normalized, 'herramienta') => self::PassThrough,
            is_numeric($units) && (float) $units > 1 => self::Hours,
            default => self::Other,
        };
    }

    /** ¿Sus unidades son horas? */
    public function countsHours(): bool
    {
        return $this === self::HourBank || $this === self::Hours;
    }
}
