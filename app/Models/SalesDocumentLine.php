<?php

namespace App\Models;

use App\Enums\ServiceUnit;
use App\Enums\TaxOperationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Línea de una factura propia (PLAN-EMISION §4.2; D-422): concepto (`item`) o texto (`text`, sin
 * importe). Base = cantidad × precio × (1 − descuento), al céntimo. Copia el nombre y el código del
 * servicio y, al emitir, el tipo y la calificación del impuesto. No se toca si su factura no es un
 * borrador (*trigger*).
 *
 * @property int $id
 * @property int $sales_document_id
 * @property int $position
 * @property string $kind
 * @property int|null $service_id
 * @property string|null $name
 * @property string|null $service_code
 * @property string|null $description
 * @property string $quantity
 * @property ServiceUnit $unit
 * @property string $unit_price
 * @property string $discount_pct
 * @property int|null $tax_rate_id
 * @property string|null $tax_rate
 * @property TaxOperationType|null $operation_type
 * @property string $line_base
 * @property int|null $project_id
 * @property int|null $hour_bank_id
 * @property string $origin
 * @property int|null $minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SalesDocument $document
 * @property-read CatalogService|null $service
 * @property-read TaxRate|null $taxRate
 */
#[Fillable([
    'sales_document_id', 'position', 'kind', 'service_id', 'name', 'service_code', 'description', 'quantity', 'unit', 'unit_price',
    'discount_pct', 'tax_rate_id', 'tax_rate', 'operation_type', 'line_base', 'project_id', 'hour_bank_id', 'origin', 'minutes',
])]
class SalesDocumentLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit' => ServiceUnit::class,
            'unit_price' => 'decimal:4',
            'discount_pct' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'operation_type' => TaxOperationType::class,
            'line_base' => 'decimal:2',
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
     * @return BelongsTo<CatalogService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'service_id');
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * Las horas facturadas en esta línea (E3 las añade desde Por facturar; E1 las bloquea al emitir y
     * las desbloquea al anular).
     *
     * @return HasMany<SalesDocumentTimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(SalesDocumentTimeEntry::class);
    }

    public function isItem(): bool
    {
        return $this->kind === 'item';
    }
}
