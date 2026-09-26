<?php

namespace App\Models;

use App\Enums\TimesheetStatus;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\TimesheetPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Semana de un usuario (SPEC §4.4, D-020, D-034). Si no hay fila, la semana está abierta.
 * Reabrir o devolver queda en la auditoría.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $week_start
 * @property TimesheetStatus $status
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_comment
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read User|null $reviewer
 */
#[Fillable(['user_id', 'week_start', 'status', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_comment'])]
class TimesheetPeriod extends Model
{
    /** @use HasFactory<TimesheetPeriodFactory> */
    use HasFactory, LogsDomainActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'date:Y-m-d',
            'status' => TimesheetStatus::class,
            'submitted_at' => 'datetime',
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
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Lunes de la semana de una fecha (la semana empieza en lunes).
     */
    public static function weekStartOf(CarbonInterface|string $date): CarbonImmutable
    {
        $day = $date instanceof CarbonInterface ? CarbonImmutable::parse($date->toDateString()) : CarbonImmutable::parse($date);

        return $day->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /**
     * Semana del usuario que contiene la fecha (sin guardar si no existe: estado abierta).
     */
    public static function forUserOn(User|int $user, CarbonInterface|string $date): self
    {
        $userId = $user instanceof User ? $user->id : $user;
        $weekStart = self::weekStartOf($date)->toDateString();

        return static::query()->firstOrNew(
            ['user_id' => $userId, 'week_start' => $weekStart],
            ['status' => TimesheetStatus::Open],
        );
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function weekEnd(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->week_start->toDateString())->addDays(6);
    }
}
