<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un periodo de una recurrente (E3): generado (con su factura) u omitido. Uno por recurrente y
 * periodo (índice único): es lo que hace que la tarea diaria y «Generar ahora» nunca dupliquen.
 *
 * @property int $id
 * @property int $recurring_invoice_id
 * @property CarbonImmutable $period_on
 * @property string $status generated | skipped
 * @property string $trigger schedule | manual
 * @property int|null $sales_document_id
 * @property string|null $error
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read RecurringInvoice $recurring
 * @property-read SalesDocument|null $document
 * @property-read User|null $creator
 */
#[Fillable(['recurring_invoice_id', 'period_on', 'status', 'trigger', 'sales_document_id', 'error', 'created_by'])]
class RecurringInvoiceRun extends Model
{
    public const string GENERATED = 'generated';

    public const string SKIPPED = 'skipped';

    public const string SCHEDULE = 'schedule';

    public const string MANUAL = 'manual';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_on' => 'immutable_date',
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
}
