<?php

namespace App\Models;

use App\Enums\AbsenceStatus;
use App\Enums\AbsenceType;
use App\Enums\CancellationStatus;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AbsenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property int|null $leave_type_id
 * @property string|null $start_time
 * @property string|null $end_time
 * @property int|null $first_approved_by
 * @property CarbonImmutable|null $first_approved_at
 * @property CancellationStatus|null $cancellation_status
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancellation_requested_at
 * @property int|null $cancellation_decided_by
 * @property CarbonImmutable|null $cancellation_decided_at
 * @property string|null $cancellation_comment
 * @property-read User $user
 * @property-read User|null $approver
 * @property-read User|null $firstApprover
 * @property-read LeaveType|null $leaveType
 * @property-read Collection<int, AbsenceDocument> $documents
 *
 * Fase 11, R3 (D-360 a D-379): `leave_type_id` es el tipo del catálogo (su categoría sigue en
 * `type`), la franja de las de horas (`start_time` y `end_time`, HH:MM de Madrid), el primer nivel de
 * aprobación cuando el tipo pide dos (`first_approved_*`; el segundo es la aprobación de siempre) y
 * «Pedir cancelación» de una aprobada (`cancellation_*`).
 */
#[Fillable(['user_id', 'type', 'leave_type_id', 'start_date', 'end_date', 'partial_minutes', 'start_time', 'end_time', 'status', 'approved_by', 'reviewed_at', 'first_approved_by', 'first_approved_at', 'notes', 'review_comment', 'cancellation_status', 'cancellation_reason', 'cancellation_requested_at', 'cancellation_decided_by', 'cancellation_decided_at', 'cancellation_comment'])]
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
     * Una ausencia siempre tiene su tipo del catálogo: la que llega solo con la categoría de la
     * Fase 3 (factorías, la importación de WeeklySync, el formulario con el módulo apagado) se queda
     * con el tipo de esa categoría, que tiene su misma clave (LeaveCatalog::legacy, D-360).
     */
    protected static function booted(): void
    {
        static::saving(function (Absence $absence): void {
            $category = $absence->getAttributes()['type'] ?? null;

            if ($absence->leave_type_id === null && is_string($category)) {
                $absence->leave_type_id = self::legacyTypeIds()[$category] ?? null;
            }
        });
    }

    /**
     * Ids de los cinco tipos de siempre por su clave (una consulta por proceso).
     *
     * @return array<string, int>
     */
    public static function legacyTypeIds(): array
    {
        static $ids = null;

        if ($ids === null || $ids === []) {
            /** @var array<string, int> $found */
            $found = LeaveType::query()->whereIn('key', AbsenceType::values())->pluck('id', 'key')->map(fn (mixed $id): int => (int) $id)->all();
            $ids = $found;
        }

        return $ids;
    }

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
            'first_approved_at' => 'datetime',
            'cancellation_status' => CancellationStatus::class,
            'cancellation_requested_at' => 'datetime',
            'cancellation_decided_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approved_by');
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return HasMany<AbsenceDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(AbsenceDocument::class)->orderBy('id');
    }

    /** ¿Ha pedido la persona cancelarla y espera respuesta? */
    public function cancellationPending(): bool
    {
        return $this->cancellation_status === CancellationStatus::Requested;
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
