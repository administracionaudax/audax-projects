<?php

namespace App\Domain\Weeklies\Ai;

/**
 * Audio que acompaña a una petición a LlmClient (D-243): solo para transcribir el dictado de la
 * weekly, como hacía WeeklySync. Viaja en la petición (`inlineData`) y no se guarda en Google.
 */
final readonly class LlmAudio
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
    ) {}
}
