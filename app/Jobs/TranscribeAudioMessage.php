<?php

namespace App\Jobs;

use App\Domain\Chat\Transcription\TranscriptionService;
use App\Enums\TranscriptionStatus;
use App\Events\Chat\AudioTranscribed;
use App\Models\AudioTranscription;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transcribe un audio del chat (SPEC §12, D-070) en la cola `transcriptions` de su propia conexión
 * (un único proceso, baja prioridad: audax-transcriber.service). 3 intentos con espera creciente;
 * después, la revisión cada 15 minutos (AudioTranscriptions::requeue) lo vuelve a intentar.
 */
final class TranscribeAudioMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $transcriptionId)
    {
        $this->onConnection((string) config('services.transcription.queue_connection'));
        $this->onQueue('transcriptions');
    }

    public function uniqueId(): string
    {
        return (string) $this->transcriptionId;
    }

    public function handle(TranscriptionService $engine): void
    {
        $transcription = AudioTranscription::query()->with('attachment')->find($this->transcriptionId);

        if ($transcription === null || $transcription->status === TranscriptionStatus::Done) {
            return;
        }

        $transcription->forceFill([
            'status' => TranscriptionStatus::Processing,
            'attempts' => $transcription->attempts + 1,
            'started_at' => now(),
        ])->save();

        $started = hrtime(true);

        try {
            $attachment = $transcription->attachment;
            $path = Storage::disk($attachment->disk)->path($attachment->path);
            $result = $engine->transcribe($path, (string) config('services.transcription.language', 'es'));
        } catch (Throwable $e) {
            $transcription->forceFill([
                'status' => TranscriptionStatus::Failed,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }

        $transcription->forceFill([
            'status' => TranscriptionStatus::Done,
            'text' => $result->text,
            'language' => $result->language,
            'engine' => $engine->engine(),
            'model' => $engine->model(),
            'audio_duration_ms' => $result->durationMs,
            'processing_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'transcribed_at' => now(),
            'last_error' => null,
        ])->save();

        AudioTranscribed::dispatch($transcription);
    }
}
