<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un escalón de los recordatorios de cobro (PLAN-EMISION §3.3, E2; D-442): cuántos días antes
 * (negativo) o después (positivo) del vencimiento se manda el recordatorio. Se archivan, no se
 * borran: sus envíos los siguen nombrando.
 *
 * @property int $id
 * @property int $days
 * @property bool $is_active
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['days', 'is_active'])]
class PaymentReminderRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'is_active' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('archived_at')->orderBy('days')->orderBy('id');
    }
}
