<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una factura de Holded (Fase 12, D-385). Se rehacen enteras en cada sincronización.
 *
 * @property int $id
 * @property int $holded_invoice_id
 * @property int $position
 * @property string|null $name
 * @property string|null $service_code
 * @property string|null $description
 * @property string $units
 * @property string $unit_price
 * @property string $discount_pct
 * @property string $subtotal
 * @property string|null $tax_rate
 * @property string|null $holded_project_id
 * @property-read HoldedInvoice $invoice
 */
#[Fillable(['holded_invoice_id', 'position', 'name', 'service_code', 'description', 'units', 'unit_price', 'discount_pct', 'subtotal', 'tax_rate', 'holded_project_id'])]
class HoldedInvoiceLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'units' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_pct' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<HoldedInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(HoldedInvoice::class, 'holded_invoice_id');
    }
}
