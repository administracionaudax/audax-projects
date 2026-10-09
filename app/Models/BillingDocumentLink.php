<?php

namespace App\Models;

use App\Enums\InvoiceLinkMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un enlace de la vista `billing_document_links` (D-427): los de las facturas de Holded y los de las
 * propias (en negativo), con las columnas de `holded_invoice_links`. Solo lectura: se escriben en sus
 * tablas (HoldedInvoiceLinker y SalesDocumentLink).
 *
 * @property int $id
 * @property int $document_id
 * @property int $project_id
 * @property int|null $hour_bank_id
 * @property InvoiceLinkMethod $method
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BillingDocument $invoice
 * @property-read Project $project
 * @property-read HourBank|null $hourBank
 * @property-read User|null $creator
 */
class BillingDocumentLink extends Model
{
    protected $table = 'billing_document_links';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'document_id' => 'integer',
            'project_id' => 'integer',
            'hour_bank_id' => 'integer',
            'method' => InvoiceLinkMethod::class,
        ];
    }

    /**
     * @return BelongsTo<BillingDocument, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingDocument::class, 'document_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<HourBank, $this>
     */
    public function hourBank(): BelongsTo
    {
        return $this->belongsTo(HourBank::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
