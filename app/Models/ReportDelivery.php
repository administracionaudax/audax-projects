<?php

namespace App\Models;

use App\Domain\Reports\Delivery\DeliveryStatus;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\ReportRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un envío de un informe por correo (D-141): al momento (schedule_id null) o de un envío
 * programado. Guarda el informe ya resuelto (el periodo del día del envío), los destinatarios y
 * los formatos tal como salieron. Lo procesa App\Jobs\SendReportDelivery en la cola `mail`.
 *
 * @property int $id
 * @property int|null $schedule_id
 * @property int $sender_user_id
 * @property string $title
 * @property array{kind: string, route_params?: array<string, int|string>, query?: array<string, mixed>} $request
 * @property list<string> $formats
 * @property list<int> $recipient_user_ids
 * @property list<string> $recipient_emails
 * @property string|null $subject
 * @property string|null $message
 * @property DeliveryStatus $status
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $sender
 * @property-read ReportSchedule|null $schedule
 */
#[Fillable(['schedule_id', 'sender_user_id', 'title', 'request', 'formats', 'recipient_user_ids', 'recipient_emails',
    'subject', 'message', 'status', 'error', 'sent_at'])]
class ReportDelivery extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request' => 'array',
            'formats' => 'array',
            'recipient_user_ids' => 'array',
            'recipient_emails' => 'array',
            'status' => DeliveryStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @return BelongsTo<ReportSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'schedule_id');
    }

    /**
     * @return HasMany<ReportDownload, $this>
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(ReportDownload::class, 'delivery_id');
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

    public function recipientCount(): int
    {
        return count($this->recipient_user_ids) + count($this->recipient_emails);
    }
}
