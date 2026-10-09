<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proyecto y, opcionalmente, bolsa de una factura propia (PLAN-EMISION §4.2; como
 * `holded_invoice_links`, D-388), para que «Vendido frente a real» y Por facturar la cuenten igual.
 * No es fiscal: se puede cambiar también en una emitida. Una rectificativa hereda los de su factura
 * (`rectified`).
 *
 * @property int $id
 * @property int $sales_document_id
 * @property int $project_id
 * @property int|null $hour_bank_id
 * @property string $method
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SalesDocument $document
 * @property-read Project $project
 * @property-read HourBank|null $hourBank
 * @property-read User|null $creator
 */
#[Fillable(['sales_document_id', 'project_id', 'hour_bank_id', 'method', 'created_by'])]
class SalesDocumentLink extends Model
{
    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
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
