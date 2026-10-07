<?php

namespace App\Models;

use App\Domain\People\RegisterImmutable;
use App\Enums\HourType;
use App\Enums\OvertimeDestination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Clasificación del exceso de un día (Fase 11, R2; PLAN-FASE-11 §7.2; D-349; W-047 y W-053): de lo
 * que pasa de la jornada teórica, cuánto es hora extra (o complementaria, a tiempo parcial) y su
 * destino (compensar con descanso o pagar) y cuánto es flexibilidad. La decide el responsable de la
 * persona o RR. HH., nunca ella misma. **Solo alta**: una decisión nueva del mismo día sustituye a la
 * anterior (`supersedes_id`); las dos quedan. Sellada con su huella (`hash`). Solo la escribe
 * App\Domain\People\OvertimeService.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $date
 * @property HourType $hour_type
 * @property int $excess_minutes
 * @property int $overtime_minutes
 * @property int $flex_minutes
 * @property OvertimeDestination|null $destination
 * @property string|null $note
 * @property int $decided_by
 * @property int|null $supersedes_id
 * @property string $hash
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 * @property-read User $decider
 */
class OvertimeDecision extends Model
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
            'hour_type' => HourType::class,
            'excess_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'flex_minutes' => 'integer',
            'destination' => OvertimeDestination::class,
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
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Las vigentes: las que ninguna otra sustituye.
     *
     * @param  Builder<OvertimeDecision>  $query
     */
    #[Scope]
    protected function effective(Builder $query): void
    {
        $query->whereNotExists(fn (QueryBuilder $newer) => $newer->from('overtime_decisions as newer')
            ->whereColumn('newer.supersedes_id', 'overtime_decisions.id'));
    }
}
