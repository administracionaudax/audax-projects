<?php

namespace App\Models;

use App\Enums\TaxOperationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Desglose de impuestos de una factura emitida, congelado al emitir (PLAN-EMISION §4.2; L-07):
 * calificación, tipo, base y cuota por tipo. Es lo que leen el PDF, el libro y el registro.
 *
 * @property int $id
 * @property int $sales_document_id
 * @property int|null $tax_rate_id
 * @property TaxOperationType $operation_type
 * @property string $rate
 * @property string $base
 * @property string $tax
 * @property string|null $legal_mention
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['sales_document_id', 'tax_rate_id', 'operation_type', 'rate', 'base', 'tax', 'legal_mention'])]
class SalesDocumentTax extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation_type' => TaxOperationType::class,
            'rate' => 'decimal:2',
            'base' => 'decimal:2',
            'tax' => 'decimal:2',
        ];
    }
}
