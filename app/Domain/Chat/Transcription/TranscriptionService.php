<?php

namespace App\Domain\Chat\Transcription;

/**
 * Motor de transcripción de audios (SPEC §12): detrás de esta interfaz para poder cambiarlo sin
 * tocar el resto (whisper.cpp hoy; faster-whisper u otro mañana). Nunca sale del servidor.
 */
interface TranscriptionService
{
    /**
     * @param  string  $path  ruta absoluta del audio (webm/opus, ogg, mp4/aac, wav…)
     *
     * @throws TranscriptionFailed si el motor no responde o no puede transcribirlo
     */
    public function transcribe(string $path, string $language): TranscriptionResult;

    public function engine(): string;

    public function model(): string;
}
