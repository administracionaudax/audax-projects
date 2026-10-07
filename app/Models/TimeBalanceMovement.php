<?php

namespace App\Models;

use App\Domain\People\RegisterImmutable;
use App\Enums\BalanceMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento del saldo de horas de una persona (Fase 11, R2; PLAN-FASE-11 §7.2; D-350; W-052):
 * minutos con signo, su tipo, la fecha y el motivo. **Solo alta**: un saldo nunca se edita, solo se
 * añaden movimientos (el modelo y un *trigger*). Sellado con su huella. Solo lo escribe
 * App\Domain\People\TimeBalanceLedger.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $date
 * @property int $minutes
 * @property BalanceMovementKind $kind
 * @property string $reason
 * @property int|null $overtime_decision_id
 * @property int|null $created_by
 * @property string $hash
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 * @property-read User|null $author
 */
class TimeBalanceMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'minutes' => 'integer',
            'kind' => BalanceMovementKind::class,
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
