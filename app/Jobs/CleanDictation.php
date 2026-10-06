<?php

namespace App\Jobs;

use App\Domain\Weeklies\Ai\AiQueue;
use App\Domain\Weeklies\Dictation\DictationCleaner;
use App\Domain\Weeklies\Dictation\DictationText;
use App\Enums\TranscriptionStatus;
use App\Events\Weeklies\DictationUpdated;
use App\Models\Dictation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Limpia el texto de un dictado con IA (F-172) en la cola `ai` (D-146 y D-154). Un solo intento: si
 * la IA no responde, el dictado se queda con la transcripción literal de Whisper. Siempre acaba en
 * `done`.
 */
final class CleanDictation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = AiQueue::TIMEOUT;

    /** Único (D-222): no se encola otro igual mientras este espera o se ejecuta. */
    public int $uniqueFor = AiQueue::UNIQUE_FOR;

    public function __construct(public readonly int $dictationId)
    {
        $this->onQueue(AiQueue::NAME);
    }

    public function uniqueId(): string
    {
        return (string) $this->dictationId;
    }

    public function handle(DictationCleaner $cleaner): void
    {
        $dictation = Dictation::query()->with('user')->find($this->dictationId);

        if ($dictation === null || $dictation->status === TranscriptionStatus::Done) {
            return;
        }

        $raw = DictationText::strip((string) $dictation->raw_text);

        $warning = null;

        try {
            $text = $cleaner->clean($dictation, $raw);
        } catch (Throwable $e) {
            report($e);
            $text = $raw;
            $warning = DictationText::WARNING_CLEANUP_FAILED;
        }

        $dictation->forceFill([
            'status' => TranscriptionStatus::Done,
            'text' => $text,
            'warning' => $warning,
            'transcribed_at' => now(),
        ])->save();

        event(DictationUpdated::for($dictation));
    }

    public function failed(?Throwable $exception): void
    {
        $dictation = Dictation::query()->find($this->dictationId);

        if ($dictation !== null && $dictation->status !== TranscriptionStatus::Done) {
            $dictation->forceFill([
                'status' => TranscriptionStatus::Done,
                'text' => DictationText::strip((string) $dictation->raw_text),
                'warning' => DictationText::WARNING_CLEANUP_FAILED,
                'transcribed_at' => now(),
            ])->save();
            event(DictationUpdated::for($dictation));
        }
    }
}
