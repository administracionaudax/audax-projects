<?php

namespace App\Models;

use App\Domain\HourBanks\HourBankLedger;
use App\Enums\TimeEntryStatus;
use App\Models\Concerns\HasFinancialAttributes;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada de horas (SPEC §4.4). Se crea, edita y borra SIEMPRE con
 * App\Domain\Time\TimeEntryWriter, que aplica las validaciones del SPEC §7 y la política de
 * exceso con la bolsa bloqueada. project_id y hour_bank_id se copian de la tarea al imputar.
 *
 * overage_minutes (D-019) lo calcula solo HourBankLedger. Al cambiar minutos, fecha o bolsa, o al
 * borrar, este modelo recalcula sus bolsas (red de seguridad para cualquier ruta de escritura).
 *
 * @property int $id
 * @property int $user_id
 * @property int $task_id
 * @property int $project_id
 * @property int|null $hour_bank_id
 * @property CarbonImmutable $date
 * @property int $minutes
 * @property int $overage_minutes
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $description
 * @property bool $is_billable
 * @property string|null $hourly_rate_snapshot
 * @property string|null $hourly_cost_snapshot
 * @property TimeEntryStatus $status
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $locked_at
 * @property int|null $time_entry_lock_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int $in_bank_minutes
 * @property-read bool $is_overage
 * @property-read User $user
 * @property-read Task $task
 * @property-read Project $project
 * @property-read HourBank|null $hourBank
 * @property-read User|null $approver
 * @property-read User|null $creator
 */
#[Fillable([
    'user_id',
    'task_id',
    'project_id',
    'hour_bank_id',
    'date',
    'minutes',
    'started_at',
    'ended_at',
    'description',
    'is_billable',
    'hourly_rate_snapshot',
    'hourly_cost_snapshot',
    'status',
    'approved_by',
    'approved_at',
    'locked_at',
    'time_entry_lock_id',
    'created_by',
])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory, HasFinancialAttributes, LogsDomainActivity;

    public const array FINANCIAL_ATTRIBUTES = ['hourly_rate_snapshot', 'hourly_cost_snapshot'];

    /**
     * Máximo de minutos imputables en un día (SPEC §7).
     */
    public const int MAX_MINUTES_PER_DAY = 24 * 60;

    /**
     * Campos que afectan al consumo de la bolsa.
     */
    public const array LEDGER_FIELDS = ['minutes', 'date', 'hour_bank_id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'overage_minutes' => 0,
        'status' => 'draft',
        'is_billable' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'minutes' => 'integer',
            'overage_minutes' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_billable' => 'boolean',
            'hourly_rate_snapshot' => 'decimal:2',
            'hourly_cost_snapshot' => 'decimal:2',
            'status' => TimeEntryStatus::class,
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (TimeEntry $entry): void {
            if (! $entry->wasRecentlyCreated && ! $entry->wasChanged(self::LEDGER_FIELDS)) {
                return;
            }

            $ledger = app(HourBankLedger::class);
            // Un admin ha cambiado los minutos, la fecha o la bolsa de una bloqueada: su exceso se
            // recalcula (las demás bloqueadas no cambian, D-019).
            $reprice = $entry->isLocked() && $entry->wasChanged(self::LEDGER_FIELDS) ? $entry->id : null;
            $ledger->recalculateById($entry->hour_bank_id, reprice: $reprice);

            $previousBank = $entry->getOriginal('hour_bank_id');
            if ($entry->wasChanged('hour_bank_id') && $previousBank !== null) {
                $ledger->recalculateById((int) $previousBank);
            }
        });

        static::deleted(function (TimeEntry $entry): void {
            app(HourBankLedger::class)->recalculateById($entry->hour_bank_id);
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
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<HourBank, $this>
     */
    public function hourBank(): BelongsTo
    {
        return $this->belongsTo(HourBank::class)->withTrashed();
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<TimeEntryLock, $this>
     */
    public function lock(): BelongsTo
    {
        return $this->belongsTo(TimeEntryLock::class, 'time_entry_lock_id');
    }

    /**
     * Minutos dentro de la bolsa (D-019).
     *
     * @return Attribute<int, never>
     */
    protected function inBankMinutes(): Attribute
    {
        return Attribute::get(fn (): int => $this->minutes - $this->overage_minutes);
    }

    /**
     * Derivado de overage_minutes (D-019 sustituye al is_overage del SPEC).
     *
     * @return Attribute<bool, never>
     */
    protected function isOverage(): Attribute
    {
        return Attribute::get(fn (): bool => $this->overage_minutes > 0);
    }

    public function isLocked(): bool
    {
        return $this->status === TimeEntryStatus::Locked;
    }

    /**
     * ¿La imputó otra persona en nombre del usuario (SPEC §7)?
     */
    public function wasLoggedOnBehalf(): bool
    {
        return $this->created_by !== null && $this->created_by !== $this->user_id;
    }

    /**
     * Entradas que $viewer puede ver (D-021): las suyas; las de las personas de los departamentos
     * que dirige; las de los proyectos que gestiona; todas si es admin.
     *
     * @param  Builder<TimeEntry>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $viewer): void
    {
        if ($viewer->isAdmin()) {
            return;
        }

        $departmentIds = $viewer->managedDepartmentIds();
        $projectIds = $viewer->managedProjectIds();

        $query->where(function (Builder $scope) use ($viewer, $departmentIds, $projectIds): void {
            $scope->where('time_entries.user_id', $viewer->id);

            if ($departmentIds !== []) {
                $scope->orWhereIn('time_entries.user_id', User::query()->select('id')->whereIn('department_id', $departmentIds));
            }

            if ($projectIds !== []) {
                $scope->orWhereIn('time_entries.project_id', $projectIds);
            }
        });
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    #[Scope]
    protected function between(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('time_entries.date', [$from, $to]);
    }
}
