<?php

namespace App\Models;

use App\Domain\Weeklies\Report\WeeklyReport;
use App\Enums\WeeklyCycleStatus;
use App\Enums\WeeklyJobState;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\WeeklyCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Semana de la weekly (D-150): de lunes a viernes, número Wnn-aa (App\Domain\Weeklies\WeeklyCalendar),
 * plazo ampliable, una sola activa y cierre manual con el informe y el audio generados.
 *
 * @property int $id
 * @property string $number
 * @property string $label
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable $deadline_date
 * @property WeeklyCycleStatus $status
 * @property array<string, mixed>|null $report JSON de WeeklyReport::toArray(); léelo con reportData()
 * @property string|null $report_text
 * @property int|null $submission_count_at_generation
 * @property WeeklyJobState|null $report_state
 * @property string|null $report_error
 * @property CarbonImmutable|null $report_generated_at
 * @property int|null $report_generated_by
 * @property CarbonImmutable|null $report_edited_at
 * @property int|null $report_edited_by
 * @property WeeklyJobState|null $audio_state
 * @property string|null $audio_error
 * @property string|null $audio_disk
 * @property string|null $audio_path
 * @property CarbonImmutable|null $audio_generated_at
 * @property int|null $audio_generated_by
 * @property list<int>|null $expected_user_ids congelado al cerrar
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, WeeklySubmission> $submissions
 * @property-read Collection<int, WeeklyExemption> $exemptions
 * @property-read Collection<int, WeeklyAudioSection> $audioSections
 * @property-read Collection<int, ClientSatisfactionSnapshot> $satisfactionSnapshots
 * @property-read User|null $reportGenerator
 * @property-read User|null $closer
 */
#[Fillable([
    'number', 'label', 'start_date', 'end_date', 'deadline_date', 'status',
    'report', 'report_text', 'submission_count_at_generation', 'report_state', 'report_error',
    'report_generated_at', 'report_generated_by', 'report_edited_at', 'report_edited_by',
    'audio_state', 'audio_error', 'audio_disk', 'audio_path', 'audio_generated_at', 'audio_generated_by',
    'expected_user_ids', 'closed_at', 'closed_by',
])]
class WeeklyCycle extends Model
{
    /** @use HasFactory<WeeklyCycleFactory> */
    use HasFactory, LogsDomainActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'deadline_date' => 'date:Y-m-d',
            'status' => WeeklyCycleStatus::class,
            'report' => 'array',
            'submission_count_at_generation' => 'integer',
            'report_state' => WeeklyJobState::class,
            'report_generated_at' => 'datetime',
            'report_edited_at' => 'datetime',
            'audio_state' => WeeklyJobState::class,
            'audio_generated_at' => 'datetime',
            'expected_user_ids' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * El informe y su texto no van a la auditoría (son grandes y se regeneran); sí quién y cuándo.
     *
     * @return list<string>
     */
    protected static function activityExcept(): array
    {
        return ['report', 'report_text', 'report_state', 'report_error', 'audio_state', 'audio_error'];
    }

    public function isActive(): bool
    {
        return $this->status === WeeklyCycleStatus::Active;
    }

    public function isClosed(): bool
    {
        return $this->status === WeeklyCycleStatus::Closed;
    }

    /**
     * Informe estructurado tipado, o null si no se ha generado.
     */
    public function reportData(): ?WeeklyReport
    {
        return $this->report === null ? null : WeeklyReport::fromArray($this->report);
    }

    /**
     * @return HasMany<WeeklySubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(WeeklySubmission::class);
    }

    /**
     * @return HasMany<WeeklyExemption, $this>
     */
    public function exemptions(): HasMany
    {
        return $this->hasMany(WeeklyExemption::class);
    }

    /**
     * @return HasMany<WeeklyAudioSection, $this>
     */
    public function audioSections(): HasMany
    {
        return $this->hasMany(WeeklyAudioSection::class)->orderBy('position');
    }

    /**
     * @return HasMany<ClientSatisfactionSnapshot, $this>
     */
    public function satisfactionSnapshots(): HasMany
    {
        return $this->hasMany(ClientSatisfactionSnapshot::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reportGenerator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'report_generated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @param  Builder<WeeklyCycle>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', WeeklyCycleStatus::Active->value);
    }

    /**
     * @param  Builder<WeeklyCycle>  $query
     */
    #[Scope]
    protected function closed(Builder $query): void
    {
        $query->where('status', WeeklyCycleStatus::Closed->value);
    }
}
