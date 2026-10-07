<?php

namespace App\Models;

use App\Domain\People\RegisterImmutable;
use App\Enums\MonthCloseStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cierre mensual del registro de una persona (Fase 11, R2; PLAN-FASE-11 §7.5; D-347): los totales y
 * el diario del mes congelados tal como los calcula WorkdayCalculator, el PDF del resumen con su
 * SHA-256, el punto de la cadena al que corresponde (`register_seq` y `register_hash`) y la respuesta
 * de la persona (confirmación o desacuerdo con motivo). Su responsable o RR. HH. lo puede
 * desconfirmar con un motivo: esa versión queda cerrada y el siguiente cierre del mes es una
 * versión nueva. Lo congelado no cambia nunca y no se borra (el modelo y un *trigger*). Solo lo
 * escribe App\Domain\People\MonthCloser.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $month
 * @property int $version
 * @property MonthCloseStatus $status
 * @property int $worked_minutes
 * @property int $expected_minutes
 * @property int $difference_minutes
 * @property int $overtime_minutes
 * @property array<string, int|bool|string|null> $totals
 * @property list<array<string, mixed>> $days
 * @property int|null $register_seq
 * @property string|null $register_hash
 * @property string $pdf_path
 * @property string $pdf_sha256
 * @property string $content_hash
 * @property CarbonImmutable $generated_at
 * @property int|null $generated_by
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $disagreed_at
 * @property string|null $disagreement_note
 * @property CarbonImmutable|null $reopened_at
 * @property int|null $reopened_by
 * @property string|null $reopen_reason
 * @property int $reminders
 * @property CarbonImmutable|null $reminded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read User|null $reopener
 * @property-read User|null $generator
 */
class MonthClose extends Model
{
    /** Columnas congeladas al generarse. */
    public const array FROZEN = [
        'user_id', 'month', 'version', 'worked_minutes', 'expected_minutes', 'difference_minutes', 'overtime_minutes',
        'totals', 'days', 'register_seq', 'register_hash', 'pdf_path', 'pdf_sha256', 'content_hash', 'generated_at',
        'generated_by', 'created_at',
    ];

    /** Nada se asigna en masa: MonthCloser rellena cada columna a mano. */
    protected $guarded = ['*'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'reminders' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'version' => 'integer',
            'status' => MonthCloseStatus::class,
            'worked_minutes' => 'integer',
            'expected_minutes' => 'integer',
            'difference_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'totals' => 'array',
            'days' => 'array',
            'register_seq' => 'integer',
            'generated_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'disagreed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'reminders' => 'integer',
            'reminded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (MonthClose $close): void {
            $original = $close->getOriginal('status');
            $status = $original instanceof MonthCloseStatus ? $original : MonthCloseStatus::from((string) $original);

            if (! $status->isCurrent()) {
                throw RegisterImmutable::close();
            }

            foreach (self::FROZEN as $frozen) {
                if ($close->isDirty($frozen)) {
                    throw RegisterImmutable::close();
                }
            }

            if ($close->getOriginal('confirmed_at') !== null && $close->isDirty('confirmed_at')) {
                throw RegisterImmutable::close();
            }
        });

        static::deleting(function (): never {
            throw RegisterImmutable::close();
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
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** «2026-09». */
    public function monthKey(): string
    {
        return $this->month->format('Y-m');
    }

    /**
     * Versiones vigentes (pendiente, confirmada o en desacuerdo): como mucho una por persona y mes.
     *
     * @param  Builder<MonthClose>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereIn('status', [MonthCloseStatus::Pending->value, MonthCloseStatus::Confirmed->value, MonthCloseStatus::Disagreed->value]);
    }
}
