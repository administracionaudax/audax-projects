<?php

namespace App\Models;

use App\Enums\BillingPaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cobro de una factura propia (PLAN-EMISION §3.3 y §4.2, E2; D-436). Importe con IVA y con signo:
 * una devolución registrada en una rectificativa va en negativo. Un cobro no se borra: se anula con
 * motivo (PaymentRecorder) y queda en la historia. `sales_documents.paid_total` es la suma de los
 * cobros sin anular.
 *
 * @property int $id
 * @property int $sales_document_id
 * @property CarbonImmutable $paid_on
 * @property string $amount
 * @property BillingPaymentMethod $method
 * @property string|null $bank_reference
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SalesDocument $document
 * @property-read User|null $creator
 * @property-read User|null $voider
 */
#[Fillable(['sales_document_id', 'paid_on', 'amount', 'method', 'bank_reference', 'note', 'created_by'])]
class Payment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_on' => 'immutable_date',
            'amount' => 'decimal:2',
            'method' => BillingPaymentMethod::class,
            'voided_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('payments.voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
