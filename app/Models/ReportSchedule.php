<?php

namespace App\Models;

use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\PauseReason;
use App\Domain\Reports\Delivery\RelativePeriod;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Delivery\ScheduleFrequency;
use Carbon\CarbonImmutable;
use Database\Factories\ReportScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Envío programado de un informe (Fase 9, D-141). Las horas son de Europe/Madrid (`time`, el día
 * de la semana o del mes y la fecha de «una vez»); next_run_at, run_at y last_run_at son instantes
 * UTC. Lo calcula App\Domain\Reports\Delivery\ScheduleClock y lo ejecuta reports:send-scheduled.
 *
 * @property int $id
 * @property int $owner_user_id
 * @property string $title
 * @property array{kind: string, route_params?: array<string, int|string>, query?: array<string, mixed>} $request
 * @property list<string> $formats
 * @property RelativePeriod $relative_period
 * @property list<int> $recipient_user_ids
 * @property list<string> $recipient_emails
 * @property string|null $subject
 * @property string|null $message
 * @property ScheduleFrequency $frequency
 * @property CarbonImmutable|null $run_at
 * @property int|null $weekday
 * @property int|null $month_day
 * @property string $time
 * @property bool $is_active
 * @property PauseReason|null $paused_reason
 * @property CarbonImmutable|null $next_run_at
 * @property CarbonImmutable|null $last_run_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $owner
 */
#[Fillable(['owner_user_id', 'title', 'request', 'formats', 'relative_period', 'recipient_user_ids', 'recipient_emails',
    'subject', 'message', 'frequency', 'run_at', 'weekday', 'month_day', 'time', 'is_active', 'paused_reason',
    'next_run_at', 'last_run_at'])]
class ReportSchedule extends Model
{
    /** @use HasFactory<ReportScheduleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request' => 'array',
            'formats' => 'array',
            'relative_period' => RelativePeriod::class,
            'recipient_user_ids' => 'array',
            'recipient_emails' => 'array',
            'frequency' => ScheduleFrequency::class,
            'run_at' => 'datetime',
            'weekday' => 'integer',
            'month_day' => 'integer',
            'is_active' => 'boolean',
            'paused_reason' => PauseReason::class,
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<ReportDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class, 'schedule_id');
    }

    public function reportRequest(): ReportRequest
    {
        return ReportRequest::fromArray($this->request);
    }

    /**
     * @return list<ExportFormat>
     */
    public function exportFormats(): array
    {
        return array_values(array_filter(array_map(fn (string $format): ?ExportFormat => ExportFormat::tryFrom($format), $this->formats)));
    }
}
