<?php

namespace App\Models;

use App\Enums\TranscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transcripción OBLIGATORIA de un audio del chat (SPEC §12, D-070): se crea con el mensaje en
 * pending y acaba en done; si falla, se reintenta (job, revisión cada 15 min y backfill).
 *
 * @property int $id
 * @property int $message_id
 * @property int $attachment_id
 * @property TranscriptionStatus $status
 * @property string|null $text
 * @property string|null $language
 * @property string|null $engine
 * @property string|null $model
 * @property int $attempts
 * @property string|null $last_error
 * @property int|null $audio_duration_ms
 * @property int|null $processing_ms
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $transcribed_at
 * @property CarbonImmutable|null $admin_notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Message $message
 * @property-read Attachment $attachment
 */
#[Fillable(['message_id', 'attachment_id', 'status', 'text', 'language', 'engine', 'model', 'attempts', 'last_error', 'audio_duration_ms', 'processing_ms', 'queued_at', 'started_at', 'transcribed_at', 'admin_notified_at'])]
class AudioTranscription extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TranscriptionStatus::class,
            'attempts' => 'integer',
            'audio_duration_ms' => 'integer',
            'processing_ms' => 'integer',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'transcribed_at' => 'immutable_datetime',
            'admin_notified_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class)->withTrashed();
    }
}
