<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cobro registrado en Holded (Fase 12, D-386), espejo de solo lectura. El importe lleva el IVA.
 *
 * @property int $id
 * @property string $holded_id
 * @property string|null $holded_document_id
 * @property int|null $holded_invoice_id
 * @property CarbonImmutable $paid_on
 * @property string $amount
 * @property string|null $method
 * @property string|null $description
 * @property CarbonImmutable|null $synced_at
 * @property-read HoldedInvoice|null $invoice
 */
#[Fillable(['holded_id', 'holded_document_id', 'holded_invoice_id', 'paid_on', 'amount', 'method', 'description', 'synced_at'])]
class HoldedPayment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_on' => 'immutable_date',
            'amount' => 'decimal:2',
            'synced_at' => 'immutable_datetime',
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
