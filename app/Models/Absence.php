<?php

namespace App\Models;

use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AbsenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ausencia de una persona (SPEC §4.1). Las aprobadas restan capacidad (SPEC §9): el día entero o,
 * con partial_minutes, esos minutos de cada día del rango. Las aprueba un responsable de su
 * departamento o un admin, como las horas (D-020).
 *
 * @property int $id
 * @property int $user_id
 * @property AbsenceType $type
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property int|null $partial_minutes
 * @property AbsenceStatus $status
 * @property int|null $approved_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $notes
 * @property string|null $review_comment
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read User|null $approver
 */
#[Fillable(['user_id', 'type', 'start_date', 'end_date', 'partial_minutes', 'status', 'approved_by', 'reviewed_at', 'notes', 'review_comment'])]
class Absence extends Model
{
    /** @use HasFactory<AbsenceFactory> */
    use HasFactory, LogsDomainActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'requested',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AbsenceType::class,
            'status' => AbsenceStatus::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'partial_minutes' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPartial(): bool
    {
        return $this->partial_minutes !== null;
    }

    public function covers(CarbonInterface|string $date): bool
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $this->start_date->toDateString() <= $day && $this->end_date->toDateString() >= $day;
    }

    /**
     * @param  Builder<Absence>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', AbsenceStatus::Approved->value);
    }

    /**
     * Ausencias que se solapan con [from, to].
     *
     * @param  Builder<Absence>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, string $from, string $to): void
    {
        $query->where('start_date', '<=', $to)->where('end_date', '>=', $from);
    }
}
