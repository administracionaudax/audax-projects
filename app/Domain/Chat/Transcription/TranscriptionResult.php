<?php

namespace App\Domain\Chat\Transcription;

/**
 * Texto de un audio. Un audio sin voz da texto vacío: también es una transcripción terminada.
 */
final readonly class TranscriptionResult
{
    public function __construct(
        public string $text,
        public ?string $language = null,
        public ?int $durationMs = null,
    ) {}
}
