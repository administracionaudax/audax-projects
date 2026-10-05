<?php

namespace App\Models;

use App\Enums\DictationContext;
use App\Enums\TranscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dictado de la weekly (F-049) o de las notas de una tarea (F-060), D-152. Se transcribe con
 * App\Domain\Chat\Transcription\TranscriptionService (Whisper del servidor) en un Job; el texto
 * limpio (F-172) va en `text` y el audio se borra al terminar (path pasa a null).
 *
 * @property int $id
 * @property int $user_id
 * @property DictationContext $context
 * @property int|null $weekly_cycle_id
 * @property int|null $client_id
 * @property int|null $task_id
 * @property TranscriptionStatus $status
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $mime
 * @property int|null $size
 * @property int|null $audio_duration_ms
 * @property string|null $raw_text
 * @property string|null $text
 * @property string|null $warning no_speech|too_short|…
 * @property string|null $engine
 * @property string|null $model
 * @property int $attempts
 * @property string|null $last_error
 * @property int|null $processing_ms
 * @property CarbonImmutable|null $transcribed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read WeeklyCycle|null $cycle
 * @property-read Client|null $client
 * @property-read Task|null $task
 */
#[Fillable(['user_id', 'context', 'weekly_cycle_id', 'client_id', 'task_id', 'status', 'disk', 'path', 'mime', 'size', 'audio_duration_ms', 'raw_text', 'text', 'warning', 'engine', 'model', 'attempts', 'last_error', 'processing_ms', 'transcribed_at'])]
class Dictation extends Model
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
            'context' => DictationContext::class,
            'status' => TranscriptionStatus::class,
            'size' => 'integer',
            'audio_duration_ms' => 'integer',
            'attempts' => 'integer',
            'processing_ms' => 'integer',
            'transcribed_at' => 'datetime',
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
     * @return BelongsTo<WeeklyCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(WeeklyCycle::class, 'weekly_cycle_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }
}
