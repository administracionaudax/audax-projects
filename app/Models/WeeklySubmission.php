<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WeeklySubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Weekly de una persona en una semana (D-150): borrador mientras submitted_at es nulo. Una por
 * persona y semana. submitted_at es el PRIMER envío y se conserva al reenviar (F-052); decide si
 * llegó a tiempo. Se escribe SIEMPRE con App\Domain\Weeklies\WeeklySubmissionWriter.
 *
 * @property int $id
 * @property int $weekly_cycle_id
 * @property int $user_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $resubmitted_at
 * @property CarbonImmutable|null $draft_saved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WeeklyCycle $cycle
 * @property-read User $user
 * @property-read Collection<int, WeeklyEntry> $entries
 */
#[Fillable(['weekly_cycle_id', 'user_id', 'submitted_at', 'resubmitted_at', 'draft_saved_at'])]
class WeeklySubmission extends Model
{
    /** @use HasFactory<WeeklySubmissionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'resubmitted_at' => 'datetime',
            'draft_saved_at' => 'datetime',
        ];
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
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
     * @return HasMany<WeeklyEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(WeeklyEntry::class)->orderBy('position');
    }

    /**
     * Solo los enviados (los borradores no cuentan para el informe ni para la participación).
     *
     * @param  Builder<WeeklySubmission>  $query
     */
    #[Scope]
    protected function submitted(Builder $query): void
    {
        $query->whereNotNull('submitted_at');
    }
}
