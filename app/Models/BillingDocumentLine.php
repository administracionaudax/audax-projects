<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la vista `billing_document_lines` (D-427): las de Holded y las propias (en negativo,
 * solo las de concepto), con las columnas de `holded_invoice_lines`. Solo lectura.
 *
 * @property int $id
 * @property int $document_id
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
 * @property-read BillingDocument $document
 */
class BillingDocumentLine extends Model
{
    protected $table = 'billing_document_lines';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'document_id' => 'integer',
            'units' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_pct' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<BillingDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(BillingDocument::class, 'document_id');
    }
}
