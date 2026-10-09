<?php

namespace App\Models;

use App\Enums\TaxOperationType;
use App\Enums\TaxRateKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de impuesto del catálogo de la emisión propia (PLAN-EMISION §4.1; D-423): IVA (con su
 * calificación de VeriFactu y su mención legal en el PDF) o retención de IRPF. Al emitir se copian el
 * tipo, la calificación y la mención en la factura: cambiar o archivar uno no cambia nada emitido.
 *
 * @property int $id
 * @property string $key
 * @property TaxRateKind $kind
 * @property string $name
 * @property string $rate
 * @property TaxOperationType|null $operation_type
 * @property string|null $legal_mention
 * @property string|null $legal_mention_en
 * @property bool $is_default
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'kind', 'name', 'rate', 'operation_type', 'legal_mention', 'legal_mention_en', 'is_default', 'position', 'archived_at'])]
class TaxRate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TaxRateKind::class,
            'rate' => 'decimal:2',
            'operation_type' => TaxOperationType::class,
            'is_default' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<TaxRate>  $query
     * @return Builder<TaxRate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** La mención del PDF en el idioma de la factura. */
    public function mention(string $language): ?string
    {
        return $language === 'en' ? ($this->legal_mention_en ?? $this->legal_mention) : $this->legal_mention;
    }
}
