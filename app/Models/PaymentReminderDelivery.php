<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un recordatorio de un escalón para una factura (único por factura y escalón; D-442): enviado (con
 * su email) o fallido (sin destinatarios o el email falló), lo que sale en «Requiere atención».
 *
 * @property int $id
 * @property int $sales_document_id
 * @property int $rule_id
 * @property int|null $email_id
 * @property string $status sent | failed
 * @property string|null $detail
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SalesDocument $document
 * @property-read PaymentReminderRule $rule
 * @property-read SalesDocumentEmail|null $email
 */
#[Fillable(['sales_document_id', 'rule_id', 'email_id', 'status', 'detail'])]
class PaymentReminderDelivery extends Model
{
    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    /**
     * @return BelongsTo<PaymentReminderRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PaymentReminderRule::class, 'rule_id');
    }

    /**
     * @return BelongsTo<SalesDocumentEmail, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(SalesDocumentEmail::class, 'email_id');
    }
}
