<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Último número emitido de una serie en un año (PLAN-EMISION §4.1; D-419). Solo sube (*trigger*):
 * un borrador eliminado no deja hueco porque el número se asigna al emitir, y un número no se
 * reutiliza nunca (V-18).
 *
 * @property int $id
 * @property int $series_id
 * @property int $year
 * @property int $first_number
 * @property int $last_number
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read NumberingSeries $series
 */
#[Fillable(['series_id', 'year', 'first_number', 'last_number'])]
class NumberingCounter extends Model
{
    /**
     * @return BelongsTo<NumberingSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(NumberingSeries::class, 'series_id');
    }
}
