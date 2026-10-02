<?php

namespace App\Jobs;

use App\Domain\Chat\Transcription\TranscriptionService;
use App\Enums\TranscriptionStatus;
use App\Events\Chat\AudioTranscribed;
use App\Http\Controllers\Chat\Media\StoreMediaMessageRequest;
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

    // small en la CPU del servidor: ~5,3 × la duración del audio; un audio de 5 min, ~27 min (D-070).
    public int $timeout = 2400;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 7200;

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
            // Como mucho el máximo de los audios (con su margen): un fichero más largo de lo que
            // declaró el navegador se transcribe solo hasta ahí y /admin/transcripciones lo señala
            // («Supera el máximo») con la duración real que devuelve el motor (D-116).
            $result = $engine->transcribe(
                $path,
                (string) config('services.transcription.language', 'es'),
                StoreMediaMessageRequest::maxDurationMs(),
            );
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

    /**
     * Agotados los intentos de este job (también si el worker lo corta por tiempo y la
     * transcripción se ha quedado «en curso»): queda fallida con su error y la revisión periódica
     * decide si la vuelve a encolar (AudioTranscriptions::requeue, con su tope).
     */
    public function failed(?Throwable $exception): void
    {
        AudioTranscription::query()
            ->whereKey($this->transcriptionId)
            ->whereIn('status', [TranscriptionStatus::Pending, TranscriptionStatus::Processing])
            ->update([
                'status' => TranscriptionStatus::Failed,
                'last_error' => mb_substr($exception?->getMessage() ?: __('chat_media.transcription.interrupted'), 0, 1000),
            ]);
    }
}
