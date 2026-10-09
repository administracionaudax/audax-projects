<?php

namespace App\Domain\Billing\Issuing;

use Carbon\CarbonInterface;

/**
 * La huella de un registro de facturación (PLAN-EMISION §5.2, V-04; D-420), la de la especificación
 * de la AEAT («Especificaciones técnicas para la generación de la huella o hash de los registros de
 * facturación», v0.1.2): `campo=valor` en su orden, unidos con «&», valores sin espacios al principio
 * ni al final (un campo vacío se escribe «Huella=»), UTF-8, SHA-256 en hexadecimal en mayúsculas.
 * Comprobada con los tres ejemplos oficiales (tests/Unit/Billing/RecordHasherTest.php). Los mismos
 * campos y el mismo orden que recalcula el *trigger* de PostgreSQL (InvoicingGuards).
 */
final class RecordHasher
{
    /**
     * Registro de alta.
     *
     * @param  array{issuer_tax_id: string, invoice_number: string, issue_date_text: string, invoice_type: string, tax_total_text: string, total_text: string, previous_hash: string, generated_at_text: string}  $fields
     */
    public static function altaInput(array $fields): string
    {
        return self::join([
            'IDEmisorFactura' => $fields['issuer_tax_id'],
            'NumSerieFactura' => $fields['invoice_number'],
            'FechaExpedicionFactura' => $fields['issue_date_text'],
            'TipoFactura' => $fields['invoice_type'],
            'CuotaTotal' => $fields['tax_total_text'],
            'ImporteTotal' => $fields['total_text'],
            'Huella' => $fields['previous_hash'],
            'FechaHoraHusoGenRegistro' => $fields['generated_at_text'],
        ]);
    }

    /**
     * Registro de anulación.
     *
     * @param  array{issuer_tax_id: string, invoice_number: string, issue_date_text: string, previous_hash: string, generated_at_text: string}  $fields
     */
    public static function anulacionInput(array $fields): string
    {
        return self::join([
            'IDEmisorFacturaAnulada' => $fields['issuer_tax_id'],
            'NumSerieFacturaAnulada' => $fields['invoice_number'],
            'FechaExpedicionFacturaAnulada' => $fields['issue_date_text'],
            'Huella' => $fields['previous_hash'],
            'FechaHoraHusoGenRegistro' => $fields['generated_at_text'],
        ]);
    }

    public static function hash(string $input): string
    {
        return strtoupper(hash('sha256', $input));
    }

    /** Fecha de expedición en el formato del registro: «DD-MM-AAAA». */
    public static function date(CarbonInterface $date): string
    {
        return $date->format('d-m-Y');
    }

    /** Importe en el formato del registro: siempre dos decimales y punto («123.10», «-1210.00»). */
    public static function amount(string $amount): string
    {
        return DocumentTotals::fixed($amount);
    }

    /** Fecha, hora y huso de generación en hora de Madrid: «2027-01-04T10:20:30+01:00». */
    public static function timestamp(CarbonInterface $moment): string
    {
        return $moment->setTimezone('Europe/Madrid')->format('Y-m-d\TH:i:sP');
    }

    /**
     * @param  array<string, string>  $fields
     */
    private static function join(array $fields): string
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = $name.'='.trim($value);
        }

        return implode('&', $parts);
    }
}
