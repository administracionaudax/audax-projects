<?php

namespace App\Models;

use App\Enums\BillingService;
use App\Enums\ServiceUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servicio del catálogo de la emisión propia (tabla `billing_services`; PLAN-EMISION §4.1; D-425):
 * código (BDH, DES, F_UX…), nombre, unidad, precio e impuesto por defecto. Una línea de factura copia
 * su nombre y su código, así los informes la clasifican como las de Holded (BillingService, D-396).
 *
 * @property int $id
 * @property string|null $code
 * @property string $name
 * @property string|null $description
 * @property ServiceUnit $unit
 * @property string $unit_price
 * @property int|null $tax_rate_id
 * @property BillingService|null $category
 * @property string|null $holded_service_id
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read TaxRate|null $taxRate
 */
#[Fillable(['code', 'name', 'description', 'unit', 'unit_price', 'tax_rate_id', 'category', 'holded_service_id', 'archived_at'])]
class CatalogService extends Model
{
    protected $table = 'billing_services';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => ServiceUnit::class,
            'unit_price' => 'decimal:4',
            'category' => BillingService::class,
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * @param  Builder<CatalogService>  $query
     * @return Builder<CatalogService>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
