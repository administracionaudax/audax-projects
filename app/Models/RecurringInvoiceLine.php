<?php

namespace App\Models;

use App\Enums\ServiceUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una factura recurrente (E3): como una línea de factura, con las palabras dinámicas en la
 * descripción ([mes], [trimestre], [año]…), que se cambian al generar cada factura.
 *
 * @property int $id
 * @property int $recurring_invoice_id
 * @property int $position
 * @property string $kind item | text
 * @property int|null $service_id
 * @property string|null $description
 * @property string $quantity
 * @property ServiceUnit $unit
 * @property string $unit_price
 * @property string $discount_pct
 * @property int|null $tax_rate_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read RecurringInvoice $recurring
 * @property-read CatalogService|null $service
 * @property-read TaxRate|null $taxRate
 */
#[Fillable(['recurring_invoice_id', 'position', 'kind', 'service_id', 'description', 'quantity', 'unit', 'unit_price', 'discount_pct', 'tax_rate_id'])]
class RecurringInvoiceLine extends Model
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
        ];
    }

    /**
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class, 'recurring_invoice_id');
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

    public function isItem(): bool
    {
        return $this->kind === 'item';
    }
}
