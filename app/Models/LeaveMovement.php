<?php

namespace App\Models;

use App\Domain\People\RegisterImmutable;
use App\Enums\LeaveMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento del libro de saldos de ausencias (Fase 11, R3; D-363): la cantidad con signo en las
 * unidades del tipo (centésimas de día o minutos), el año de la asignación, desde cuándo se puede
 * gastar y cuándo caduca, el motivo y quién lo anotó. **Solo alta** (el modelo y un *trigger*),
 * sellado con su huella. Solo lo escribe App\Domain\Absences\LeaveLedger.
 *
 * @property int $id
 * @property int $user_id
 * @property int $leave_type_id
 * @property int $year
 * @property LeaveMovementKind $kind
 * @property int $amount
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $expires_on
 * @property string $reason
 * @property int|null $created_by
 * @property string $hash
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 * @property-read LeaveType $leaveType
 * @property-read User|null $author
 */
class LeaveMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'kind' => LeaveMovementKind::class,
            'amount' => 'integer',
            'valid_from' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw RegisterImmutable::append('cambiar'));
        static::deleting(fn (): never => throw RegisterImmutable::append('borrar'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
