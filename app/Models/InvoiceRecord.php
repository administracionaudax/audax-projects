<?php

namespace App\Models;

use App\Enums\InvoiceRecordKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de facturación (PLAN-EMISION §4.3; D-420): uno por emisión (alta) o por anulación por
 * error (anulación), encadenado por instalación con la huella SHA-256 de la especificación de la
 * AEAT. Los campos de la huella se guardan como texto exacto (como irán en el XML de E7) y `payload`
 * lleva todo el contenido del art. 10 del RD 1007/2023. Solo alta (*triggers*): nada se cambia ni se
 * borra; una corrección es un registro nuevo.
 *
 * @property int $id
 * @property int $sif_installation_id
 * @property int $seq
 * @property InvoiceRecordKind $kind
 * @property int $sales_document_id
 * @property string $issuer_tax_id
 * @property string $invoice_number
 * @property string $issue_date_text
 * @property string|null $invoice_type
 * @property string|null $tax_total_text
 * @property string|null $total_text
 * @property string $previous_hash
 * @property string $generated_at_text
 * @property bool $is_first
 * @property int|null $previous_record_id
 * @property string $hash
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $created_at
 * @property-read SalesDocument $document
 * @property-read SifInstallation $installation
 */
#[Fillable([
    'sif_installation_id', 'seq', 'kind', 'sales_document_id', 'issuer_tax_id', 'invoice_number', 'issue_date_text', 'invoice_type',
    'tax_total_text', 'total_text', 'previous_hash', 'generated_at_text', 'is_first', 'previous_record_id', 'hash', 'payload',
])]
class InvoiceRecord extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceRecordKind::class,
            'is_first' => 'boolean',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    /**
     * @return BelongsTo<SifInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(SifInstallation::class, 'sif_installation_id');
    }
}
