<?php

namespace App\Domain\Billing\Issuing;

use App\Enums\TaxOperationType;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\SalesDocumentTax;
use App\Models\SifInstallation;

/**
 * El contenido de un registro de facturación (RD 1007/2023, art. 10; PLAN-EMISION §4.3 y §5.3;
 * D-420): todo lo que llevará el XML de VeriFactu en E7 (emisor, número, fechas, tipo, rectificación,
 * descripción, destinatario, desglose, importes y sistema), para generarlo entonces sin leer la
 * factura. El encadenamiento y la huella los añade RecordChain.
 */
final class RecordPayload
{
    /** Países de la UE (para el destinatario con NIF-IVA, IDOtro con IDType 02). */
    public const array EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'GR', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    /**
     * @param  array<string, string|null>  $issuer
     * @param  array<string, mixed>  $client
     * @param  iterable<SalesDocumentTax>  $taxes
     * @param  list<array{number: string, date: string}>  $rectified
     * @return array<string, mixed>
     */
    public static function alta(SalesDocument $document, SifInstallation $installation, array $issuer, array $client, iterable $taxes, array $rectified, string $invoiceType): array
    {
        $breakdown = [];
        foreach ($taxes as $tax) {
            $type = $tax->operation_type;
            $breakdown[] = array_filter([
                'impuesto' => '01',
                'clave_regimen' => '01',
                'calificacion_operacion' => str_starts_with($type->value, 'E') ? null : $type->value,
                'operacion_exenta' => str_starts_with($type->value, 'E') ? $type->value : null,
                'tipo_impositivo' => in_array($type, [TaxOperationType::S1], true) ? RecordHasher::amount((string) $tax->rate) : null,
                'base_imponible' => RecordHasher::amount((string) $tax->base),
                'cuota_repercutida' => $type === TaxOperationType::S1 ? RecordHasher::amount((string) $tax->tax) : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        $description = $document->lines->first(fn (SalesDocumentLine $line): bool => $line->isItem());
        $text = trim((string) ($description->description ?? $description->name ?? ''));

        return array_filter([
            'id_version' => '1.0',
            'id_factura' => [
                'id_emisor_factura' => $issuer['tax_id'] ?? '',
                'num_serie_factura' => $document->full_number,
                'fecha_expedicion_factura' => RecordHasher::date($document->issue_date),
            ],
            'nombre_razon_emisor' => $issuer['legal_name'] ?? '',
            'tipo_factura' => $invoiceType,
            'tipo_rectificativa' => $document->isCreditNote() ? 'I' : null,
            'facturas_rectificadas' => $rectified === [] ? null : array_map(fn (array $one): array => [
                'id_emisor_factura' => $issuer['tax_id'] ?? '',
                'num_serie_factura' => $one['number'],
                'fecha_expedicion_factura' => $one['date'],
            ], $rectified),
            'motivo_rectificacion' => $document->rectification_reason,
            'fecha_operacion' => $document->operation_date !== null ? RecordHasher::date($document->operation_date) : null,
            'descripcion_operacion' => mb_substr($text === '' ? ($document->isCreditNote() ? 'Rectificación' : 'Servicios') : $text, 0, 500),
            'destinatarios' => [self::recipient($client)],
            'desglose' => $breakdown,
            'cuota_total' => RecordHasher::amount((string) $document->tax_total),
            'importe_total' => RecordHasher::amount(bcadd(DocumentTotals::num((string) $document->subtotal), DocumentTotals::num((string) $document->tax_total), 2)),
            'sistema_informatico' => self::system($installation, $issuer),
            'entorno' => $installation->environment->value,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Registro de anulación (V-03): la factura anulada, el sistema y el motivo (interno).
     *
     * @param  array<string, string|null>  $issuer
     * @return array<string, mixed>
     */
    public static function anulacion(SalesDocument $document, SifInstallation $installation, array $issuer, string $reason): array
    {
        return [
            'id_version' => '1.0',
            'id_factura' => [
                'id_emisor_factura_anulada' => $issuer['tax_id'] ?? '',
                'num_serie_factura_anulada' => $document->full_number,
                'fecha_expedicion_factura_anulada' => RecordHasher::date($document->issue_date),
            ],
            'motivo' => $reason,
            'sistema_informatico' => self::system($installation, $issuer),
            'entorno' => $installation->environment->value,
        ];
    }

    /**
     * Destinatario: NIF español o, si es de fuera, IDOtro (02 NIF-IVA en la UE; 04 documento del país).
     *
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    private static function recipient(array $client): array
    {
        $name = (string) ($client['legal_name'] ?? $client['name'] ?? '');
        $country = strtoupper((string) ($client['country_code'] ?? 'ES'));

        if ($country === 'ES' && ($client['tax_id'] ?? null) !== null) {
            return ['nombre_razon' => $name, 'nif' => $client['tax_id']];
        }

        $vat = $client['eu_vat_number'] ?? null;

        return ['nombre_razon' => $name, 'id_otro' => [
            'codigo_pais' => $country,
            'id_type' => $vat !== null && in_array($country, self::EU, true) ? '02' : '04',
            'id' => $vat ?? $client['tax_id'] ?? '',
        ]];
    }

    /**
     * @param  array<string, string|null>  $issuer
     * @return array<string, string>
     */
    private static function system(SifInstallation $installation, array $issuer): array
    {
        return [
            'nombre_razon' => (string) ($issuer['legal_name'] ?? ''),
            'nif' => (string) ($issuer['tax_id'] ?? ''),
            'nombre_sistema_informatico' => $installation->sif_name,
            'id_sistema_informatico' => $installation->sif_code,
            'version' => (string) config('invoicing.system.version'),
            'numero_instalacion' => $installation->installation_number,
            'tipo_uso_posible_solo_verifactu' => 'S',
            'tipo_uso_posible_multi_ot' => 'N',
            'indicador_multiples_ot' => 'N',
        ];
    }
}
