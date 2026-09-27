<?php

namespace App\Events\Chat;

use App\Models\AudioTranscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un audio ya tiene su texto. El tiempo real (Fase 6, C2) lo emite a la conversación para cambiar
 * «Transcribiendo…» por el texto; la búsqueda lo encuentra desde ese momento.
 */
final class AudioTranscribed
{
    use Dispatchable;

    public function __construct(public readonly AudioTranscription $transcription) {}
}
