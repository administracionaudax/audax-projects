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
     * @param  int|null  $maxDurationMs  procesa como mucho este tiempo de audio (el máximo de los
     *                                   audios): un fichero más largo que el que declaró el
     *                                   navegador no bloquea el único proceso de transcripción
     *                                   (D-116). El resultado lleva la duración REAL del audio.
     *
     * @throws TranscriptionFailed si el motor no responde o no puede transcribirlo
     */
    public function transcribe(string $path, string $language, ?int $maxDurationMs = null): TranscriptionResult;

    public function engine(): string;

    public function model(): string;
}
