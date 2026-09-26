<?php

namespace App\Models;

use App\Domain\HourBanks\HourBankLedger;
use App\Enums\HourBankStatus;
use App\Enums\OveragePolicy;
use App\Models\Concerns\HasFinancialAttributes;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\HourBankFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bolsa de horas (SPEC §4.2 y §8). consumed_minutes y overage_minutes son una caché que solo
 * escribe App\Domain\HourBanks\HourBankLedger; nunca se rellenan a mano.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property int|null $department_id
 * @property int $total_minutes
 * @property string|null $hourly_rate
 * @property string|null $price_amount
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property HourBankStatus $status
 * @property OveragePolicy $overage_policy
 * @property int|null $renewed_from_id
 * @property string|null $invoice_reference
 * @property string|null $notes
 * @property int $consumed_minutes
 * @property int $overage_minutes
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property int|null $closed_remaining_minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read int $remaining_minutes
 * @property-read int $in_bank_minutes
 * @property-read float $consumed_pct
 * @property-read Project $project
 * @property-read Department|null $department
 * @property-read HourBank|null $renewedFrom
 * @property-read HourBank|null $renewal
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, TimeEntry> $timeEntries
 */
#[Fillable([
    'project_id',
    'name',
    'department_id',
    'total_minutes',
    'hourly_rate',
    'price_amount',
    'start_date',
    'end_date',
    'status',
    'overage_policy',
    'renewed_from_id',
    'invoice_reference',
    'notes',
    'closed_at',
    'closed_by',
    'closed_remaining_minutes',
])]
class HourBank extends Model
{
    /** @use HasFactory<HourBankFactory> */
    use HasFactory, HasFinancialAttributes, LogsDomainActivity, SoftDeletes;

    public const array FINANCIAL_ATTRIBUTES = ['hourly_rate', 'price_amount'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'overage_policy' => 'inherit',
        'consumed_minutes' => 0,
        'overage_minutes' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_minutes' => 'integer',
            'hourly_rate' => 'decimal:2',
            'price_amount' => 'decimal:2',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'status' => HourBankStatus::class,
            'overage_policy' => OveragePolicy::class,
            'consumed_minutes' => 'integer',
            'overage_minutes' => 'integer',
            'closed_at' => 'datetime',
            'closed_remaining_minutes' => 'integer',
        ];
    }

    /**
     * Cambiar el total recalcula el exceso de las entradas y el estado (D-035). HourBankLedger
     * guarda la bolsa con otra instancia y sin tocar el total, así que no hay recálculo en bucle.
     */
    protected static function booted(): void
    {
        static::saved(function (HourBank $bank): void {
            if ($bank->wasChanged('total_minutes')) {
                app(HourBankLedger::class)->recalculate($bank);
            }
        });
    }

    /**
     * La caché de consumo cambia con cada imputación: no se audita (la auditoría está en TimeEntry).
     *
     * @return list<string>
     */
    protected static function activityExcept(): array
    {
        return ['consumed_minutes', 'overage_minutes'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * @return BelongsTo<HourBank, $this>
     */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(HourBank::class, 'renewed_from_id');
    }

    /**
     * La bolsa que renovó a esta (si la hay).
     *
     * @return HasOne<HourBank, $this>
     */
    public function renewal(): HasOne
    {
        return $this->hasOne(HourBank::class, 'renewed_from_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<HourBankAlert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(HourBankAlert::class);
    }

    /**
     * Saldo restante: nunca negativo (SPEC §4.2).
     *
     * @return Attribute<int<0, max>, never>
     */
    protected function remainingMinutes(): Attribute
    {
        return Attribute::get(fn (): int => max($this->total_minutes - $this->consumed_minutes, 0));
    }

    /**
     * Minutos consumidos dentro de la bolsa (sin el exceso).
     *
     * @return Attribute<int, never>
     */
    protected function inBankMinutes(): Attribute
    {
        return Attribute::get(fn (): int => $this->consumed_minutes - $this->overage_minutes);
    }

    /**
     * Porcentaje de consumo (puede superar el 100 %).
     *
     * @return Attribute<float, never>
     */
    protected function consumedPct(): Attribute
    {
        return Attribute::get(fn (): float => $this->total_minutes > 0
            ? round($this->consumed_minutes * 100 / $this->total_minutes, 2)
            : 0.0);
    }

    public function acceptsTime(): bool
    {
        return $this->status->acceptsTime();
    }

    /**
     * @param  Builder<HourBank>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [HourBankStatus::Active->value, HourBankStatus::Exhausted->value]);
    }
}
