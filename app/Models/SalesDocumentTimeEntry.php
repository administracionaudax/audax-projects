<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrada de horas facturada en una línea (PLAN-EMISION §3.8; D-424). Una entrada solo puede
 * estar en una factura viva (índice único parcial sobre las no liberadas); al anular la factura se
 * libera (`released_at`) y vuelve a poder facturarse.
 *
 * @property int $id
 * @property int $sales_document_line_id
 * @property int $time_entry_id
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SalesDocumentLine $line
 * @property-read TimeEntry $timeEntry
 */
#[Fillable(['sales_document_line_id', 'time_entry_id', 'released_at'])]
class SalesDocumentTimeEntry extends Model
{
    protected $table = 'sales_document_time_entry';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SalesDocumentLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesDocumentLine::class, 'sales_document_line_id');
    }

    /**
     * @return BelongsTo<TimeEntry, $this>
     */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }
}
