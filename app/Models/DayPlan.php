<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\DayPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabecera del plan del día de una persona (docs/PLAN-CARGAS.md §7.1, D-250): una por persona y
 * fecha local. La crea App\Domain\DayPlan\DayPlanWriter con la primera línea (o el recordatorio, que
 * la usa para no avisar dos veces el mismo día, D-252). `published_at` es la hora de la primera línea.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $date
 * @property CarbonImmutable|null $published_at
 * @property string|null $note
 * @property CarbonImmutable|null $reminded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'date', 'published_at', 'note', 'reminded_at'])]
class DayPlan extends Model
{
    /** @use HasFactory<DayPlanFactory> */
    use HasFactory, LogsDomainActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'published_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /**
     * El recordatorio no es un cambio del plan (D-252).
     *
     * @return list<string>
     */
    protected static function activityExcept(): array
    {
        return ['reminded_at'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DayPlanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DayPlanItem::class);
    }
}
