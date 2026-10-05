<?php

namespace App\Domain\Weeklies\Ai;

/**
 * Audio locutado: los bytes del MP3 (los trozos ya unidos), el tipo, la voz y los caracteres
 * facturados.
 */
final readonly class SynthesizedSpeech
{
    public function __construct(
        public string $audio,
        public string $voice,
        public int $characters,
        public int $chunks = 1,
        public string $mime = 'audio/mpeg',
    ) {}

    public function size(): int
    {
        return strlen($this->audio);
    }
}
