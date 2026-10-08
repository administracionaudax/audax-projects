<?php

namespace App\Models;

use App\Enums\InvoiceLinkMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enlace de una factura de Holded con un proyecto y, opcionalmente, una de sus bolsas (Fase 12,
 * D-388). Lo escribe solo HoldedInvoiceLinker; uno por factura, proyecto y bolsa.
 *
 * @property int $id
 * @property int $holded_invoice_id
 * @property int $project_id
 * @property int|null $hour_bank_id
 * @property InvoiceLinkMethod $method
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read HoldedInvoice $invoice
 * @property-read Project $project
 * @property-read HourBank|null $hourBank
 * @property-read User|null $creator
 */
#[Fillable(['holded_invoice_id', 'project_id', 'hour_bank_id', 'method', 'created_by'])]
class HoldedInvoiceLink extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => InvoiceLinkMethod::class,
        ];
    }

    /**
     * @return BelongsTo<HoldedInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(HoldedInvoice::class, 'holded_invoice_id');
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
