<?php

namespace App\Domain\Weeklies\Ai;

/**
 * Locución del informe (D-146, F-084): texto → MP3. Implementaciones: GoogleTtsSynthesizer (Google
 * Cloud Text-to-Speech, voz es-ES-Journey-F o la de GOOGLE_TTS_VOICE) y FakeSpeechSynthesizer.
 * Siempre desde un Job de la cola `ai`. Cada llamada queda en ai_usage (caracteres y coste).
 *
 * @throws LlmNotConfigured sin clave
 * @throws LlmUnavailable si el servicio falla
 * @throws LlmInvalidResponse si no devuelve audio
 */
interface SpeechSynthesizer
{
    public function synthesize(SpeechRequest $request): SynthesizedSpeech;

    public function voice(): string;
}
