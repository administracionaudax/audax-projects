<?php

namespace App\Domain\Chat\Transcription;

/**
 * Motor falso para local y tests: devuelve un texto fijo (o el que se programe) y puede fallar a
 * propósito para probar los reintentos.
 */
final class FakeTranscriber implements TranscriptionService
{
    /** @var list<string|TranscriptionFailed> */
    private array $queue = [];

    public int $calls = 0;

    /** Lo que se pidió procesar como mucho en la última llamada. */
    public ?int $lastMaxDurationMs = null;

    /** Duración «real» que devuelve (la que mediría el motor). */
    public int $durationMs = 1000;

    public function __construct(private string $default = 'Transcripción de prueba') {}

    /**
     * Respuestas en orden: un texto o un fallo (TranscriptionFailed).
     */
    public function respondWith(string|TranscriptionFailed ...$responses): self
    {
        $this->queue = array_values([...$this->queue, ...$responses]);

        return $this;
    }

    public function transcribe(string $path, string $language, ?int $maxDurationMs = null): TranscriptionResult
    {
        $this->calls++;
        $this->lastMaxDurationMs = $maxDurationMs;
        $next = array_shift($this->queue) ?? $this->default;

        if ($next instanceof TranscriptionFailed) {
            throw $next;
        }

        return new TranscriptionResult($next, $language, $this->durationMs);
    }

    public function engine(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake';
    }
}
