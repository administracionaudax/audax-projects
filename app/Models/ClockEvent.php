<?php

namespace App\Models;

use App\Domain\People\RegisterImmutable;
use App\Enums\ClockEventKind;
use App\Enums\ClockSource;
use App\Enums\PauseType;
use App\Enums\WorkMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila del registro de jornada (Fase 11, D-332): un fichaje (entrada, inicio y fin de la pausa,
 * salida) o la anulación de uno por una corrección aceptada (`void`). **Solo de alta**: este modelo
 * no deja cambiar ni borrar una fila (y la base de datos tampoco, con un *trigger*). Solo la escribe
 * App\Domain\People\ClockWriter, que pone la hora del servidor y encadena las huellas.
 *
 * @property int $id
 * @property int $user_id
 * @property int $seq
 * @property ClockEventKind $kind
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property WorkMode|null $work_mode
 * @property PauseType|null $pause_type
 * @property ClockSource $source
 * @property int|null $voided_event_id
 * @property int|null $correction_id
 * @property int|null $created_by
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property string $prev_hash
 * @property string $hash
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 * @property-read User|null $author
 * @property-read ClockCorrection|null $correction
 * @property-read ClockEvent|null $voidedEvent
 */
class ClockEvent extends Model
{
    public const UPDATED_AT = null;

    /** Nada se asigna en masa: ClockWriter rellena cada columna a mano. */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'kind' => ClockEventKind::class,
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'work_mode' => WorkMode::class,
            'pause_type' => PauseType::class,
            'source' => ClockSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw RegisterImmutable::event('cambiar');
        });

        static::deleting(function (): never {
            throw RegisterImmutable::event('borrar');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quién escribió la fila: la propia persona al fichar o quien aceptó la corrección.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * El fichaje que anula una fila `void`.
     *
     * @return BelongsTo<ClockEvent, $this>
     */
    public function voidedEvent(): BelongsTo
    {
        return $this->belongsTo(ClockEvent::class, 'voided_event_id');
    }

    /**
     * @return BelongsTo<ClockCorrection, $this>
     */
    public function correction(): BelongsTo
    {
        return $this->belongsTo(ClockCorrection::class);
    }
}
