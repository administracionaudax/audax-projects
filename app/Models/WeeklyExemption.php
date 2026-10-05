<?php

namespace App\Models;

use App\Enums\WeeklyExemptionReason;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\WeeklyExemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exención de una persona en una semana (D-150, D-151): manual, renuncia o, al cerrar, la foto de la
 * ausencia que la eximía. Una por persona y semana. La lee App\Domain\Weeklies\WeeklyEligibility.
 *
 * @property int $id
 * @property int $weekly_cycle_id
 * @property int $user_id
 * @property WeeklyExemptionReason $reason
 * @property int|null $absence_id
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WeeklyCycle $cycle
 * @property-read User $user
 * @property-read Absence|null $absence
 * @property-read User|null $creator
 */
#[Fillable(['weekly_cycle_id', 'user_id', 'reason', 'absence_id', 'note', 'created_by'])]
class WeeklyExemption extends Model
{
    /** @use HasFactory<WeeklyExemptionFactory> */
    use HasFactory, LogsDomainActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => WeeklyExemptionReason::class,
        ];
    }

    /**
     * @return BelongsTo<WeeklyCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(WeeklyCycle::class, 'weekly_cycle_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Absence, $this>
     */
    public function absence(): BelongsTo
    {
        return $this->belongsTo(Absence::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
