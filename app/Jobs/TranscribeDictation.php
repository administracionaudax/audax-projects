<?php

namespace App\Jobs;

use App\Domain\Chat\Transcription\TranscriptionService;
use App\Domain\Weeklies\Dictation\DictationCleaner;
use App\Domain\Weeklies\Dictation\DictationText;
use App\Enums\TranscriptionStatus;
use App\Events\Weeklies\DictationUpdated;
use App\Http\Controllers\Chat\Media\StoreMediaMessageRequest;
use App\Models\Dictation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transcribe un dictado de la weekly (F-049, F-171, D-152) con el Whisper del servidor, en la misma
 * cola `transcriptions` que los audios del chat (un único proceso de baja prioridad). El audio no
 * sale del servidor y se borra al acabar, con éxito o sin él: el dictado es un borrador de texto.
 *
 * - Sin voz útil (silencio, muletillas o una alucinación típica de Whisper): queda hecho, sin texto
 *   y con el aviso `no_speech` (F-171).
 * - Con la limpieza activada (F-172), el texto pasa a CleanDictation en la cola `ai`; el dictado
 *   sigue «en curso» hasta que vuelve.
 */
final class TranscribeDictation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 2400;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $dictationId)
    {
        $this->onConnection((string) config('services.transcription.queue_connection'));
        $this->onQueue('transcriptions');
    }

    public function uniqueId(): string
    {
        return 'dictation-'.$this->dictationId;
    }

    public function handle(TranscriptionService $engine): void
    {
        $dictation = Dictation::query()->find($this->dictationId);

        if ($dictation === null || $dictation->status === TranscriptionStatus::Done) {
            return;
        }

        if ($dictation->path === null || $dictation->disk === null || ! Storage::disk($dictation->disk)->exists($dictation->path)) {
            $dictation->forceFill(['status' => TranscriptionStatus::Failed, 'last_error' => 'audio_missing'])->save();
            event(DictationUpdated::for($dictation));

            return;
        }

        $dictation->forceFill([
            'status' => TranscriptionStatus::Processing,
            'attempts' => $dictation->attempts + 1,
        ])->save();

        $started = hrtime(true);

        try {
            $result = $engine->transcribe(
                Storage::disk($dictation->disk)->path($dictation->path),
                (string) config('services.transcription.language', 'es'),
                StoreMediaMessageRequest::maxDurationMs(),
            );
        } catch (Throwable $e) {
            $dictation->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            throw $e;
        }

        $raw = DictationText::strip($result->text);
        $meaningful = DictationText::isMeaningful($raw);
        $clean = $meaningful && DictationCleaner::enabled() && ! DictationText::shouldSkipCleanup($raw);

        $dictation->forceFill([
            'status' => $clean ? TranscriptionStatus::Processing : TranscriptionStatus::Done,
            'raw_text' => $result->text,
            'text' => $meaningful ? ($clean ? null : $raw) : '',
            'warning' => $meaningful ? null : DictationText::WARNING_NO_SPEECH,
            'engine' => $engine->engine(),
            'model' => $engine->model(),
            'audio_duration_ms' => $result->durationMs ?? $dictation->audio_duration_ms,
            'processing_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'transcribed_at' => $clean ? null : now(),
            'last_error' => null,
        ])->save();

        self::discardAudio($dictation);

        if ($clean) {
            CleanDictation::dispatch($dictation->id);

            return;
        }

        event(DictationUpdated::for($dictation));
    }

    /**
     * Agotados los intentos: queda fallido y se borra el audio (no se guarda nunca, D-152).
     */
    public function failed(?Throwable $exception): void
    {
        $dictation = Dictation::query()->find($this->dictationId);

        if ($dictation === null) {
            return;
        }

        if ($dictation->status !== TranscriptionStatus::Done) {
            $dictation->forceFill([
                'status' => TranscriptionStatus::Failed,
                'last_error' => mb_substr($exception?->getMessage() ?: 'failed', 0, 1000),
            ])->save();
            event(DictationUpdated::for($dictation));
        }

        self::discardAudio($dictation);
    }

    public static function discardAudio(Dictation $dictation): void
    {
        if ($dictation->disk !== null && $dictation->path !== null) {
            Storage::disk($dictation->disk)->delete($dictation->path);
        }

        $dictation->forceFill(['path' => null])->save();
    }
}
