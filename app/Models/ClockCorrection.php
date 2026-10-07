<?php

namespace App\Models;

use App\Domain\People\RegisterImmutable;
use App\Enums\CorrectionStatus;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Corrección de un día del registro con doble conformidad (Fase 11, D-335): quien la propone
 * (la persona, su responsable o RR. HH.), el motivo, los fichajes efectivos que anula (`voids`) y los
 * que añade (`adds`), y la decisión de la otra parte. Si no hay acuerdo queda «en discrepancia»
 * con las dos versiones y cuenta la original. **No se borra nunca** y, una vez decidida, no se
 * cambia (el modelo lo impide y la base de datos también, con un *trigger*). Al decidirse se sella
 * con su huella (`hash`). Solo la escribe App\Domain\People\ClockCorrectionService.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $date
 * @property int $proposed_by
 * @property string $reason
 * @property list<int> $voids
 * @property list<array{kind: string, occurred_at: string, work_mode: string|null, pause_type: string|null}> $adds
 * @property CorrectionStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $dispute_reason
 * @property string|null $hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read User $proposer
 * @property-read User|null $decider
 */
class ClockCorrection extends Model
{
    use LogsDomainActivity;

    public const string DISPUTE_REJECTED = 'rejected';

    public const string DISPUTE_NO_ANSWER = 'no_answer';

    /** Días que espera la conformidad de la otra parte antes de quedar en discrepancia (D-335). */
    public const int ANSWER_DAYS = 7;

    /** Nada se asigna en masa: ClockCorrectionService rellena cada columna a mano. */
    protected $guarded = ['*'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'voids' => 'array',
            'adds' => 'array',
            'status' => CorrectionStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ClockCorrection $correction): void {
            $original = $correction->getOriginal('status');
            $status = $original instanceof CorrectionStatus ? $original : CorrectionStatus::from((string) $original);

            if ($status->isFinal()) {
                throw RegisterImmutable::correction('cambiar');
            }

            foreach (['user_id', 'date', 'proposed_by', 'reason', 'voids', 'adds', 'created_at'] as $frozen) {
                if ($correction->isDirty($frozen)) {
                    throw RegisterImmutable::correction('cambiar');
                }
            }
        });

        static::deleting(function (): never {
            throw RegisterImmutable::correction('borrar');
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
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Filas del registro que escribió al aceptarse.
     *
     * @return HasMany<ClockEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ClockEvent::class, 'correction_id');
    }

    /** ¿La propuso la propia persona (y la acepta su responsable o RR. HH.)? */
    public function proposedBySubject(): bool
    {
        return $this->proposed_by === $this->user_id;
    }

    /**
     * @param  Builder<ClockCorrection>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', CorrectionStatus::Pending->value);
    }

    /**
     * Para LogsDomainActivity: el sello no aporta nada en la auditoría.
     *
     * @return list<string>
     */
    protected static function activityExcept(): array
    {
        return ['hash'];
    }
}
