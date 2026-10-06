<?php

namespace App\Domain\Weeklies\Dictation;

use App\Domain\Chat\Transcription\TranscriptionFailed;
use App\Domain\Chat\Transcription\TranscriptionResult;
use App\Domain\Weeklies\Ai\LlmAudio;
use App\Domain\Weeklies\Ai\LlmClient;
use App\Domain\Weeklies\Ai\LlmException;
use App\Domain\Weeklies\Ai\LlmRequest;
use App\Enums\AiFeature;
use App\Models\Dictation;

/**
 * Transcribe el dictado de la weekly con Gemini (D-243), como WeeklySync (`ws:transcribe-audio`,
 * primera pasada, con su prompt): en segundos, frente a los minutos de Whisper en la CPU del
 * servidor. Los audios del chat siguen con Whisper en el servidor.
 *
 * Devuelve el texto literal o vacío si no hay voz útil; la limpieza (DictationCleaner) va después.
 */
final class GeminiDictationTranscriber
{
    public const string ENGINE = 'gemini';

    /** Lo que admite Gemini en línea (20 MB por petición, con el base64 y el texto). */
    public const int MAX_BYTES = 14 * 1024 * 1024;

    public function __construct(private readonly LlmClient $llm) {}

    /**
     * Gemini con el ajuste `dictation_driver` y una clave (o el doble de pruebas); si no, Whisper.
     */
    public static function enabled(): bool
    {
        if (config('services.transcription.dictation_driver') !== self::ENGINE) {
            return false;
        }

        return config('services.gemini.driver') === 'fake' || filled(config('services.gemini.key'));
    }

    public function model(): string
    {
        return $this->llm->model();
    }

    /**
     * @throws TranscriptionFailed si Gemini no responde o el audio no se puede enviar
     */
    public function transcribe(Dictation $dictation, string $path): TranscriptionResult
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            throw new TranscriptionFailed('audio_unreadable');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new TranscriptionFailed('audio_too_large');
        }

        try {
            $response = $this->llm->generate(new LlmRequest(
                feature: AiFeature::DictationTranscription,
                prompt: self::PROMPT,
                json: true,
                temperature: 0.0,
                user: $dictation->user,
                subject: $dictation,
                operation: 'dictation_transcription',
                metadata: ['context' => $dictation->context->value, 'mime' => self::mime($dictation)],
                audio: new LlmAudio(self::mime($dictation), $bytes),
            ));
        } catch (LlmException $e) {
            throw new TranscriptionFailed($e->getMessage(), previous: $e);
        }

        $json = $response->json ?? [];
        $text = DictationText::cleanModelText(is_string($json['transcription'] ?? null) ? $json['transcription'] : '');

        return new TranscriptionResult(($json['hasMeaningfulSpeech'] ?? false) === true ? $text : '', 'es');
    }

    /** Tipo del audio para Gemini, sin parámetros (`audio/webm;codecs=opus` → `audio/webm`), como `ws`. */
    public static function mime(Dictation $dictation): string
    {
        $mime = mb_strtolower(trim(explode(';', (string) $dictation->mime)[0]));

        return match (true) {
            $mime === '' => 'audio/webm',
            $mime === 'video/webm' => 'audio/webm',
            $mime === 'video/mp4' => 'audio/mp4',
            default => $mime,
        };
    }

    public const string PROMPT = <<<'TXT'
        Eres un transcriptor de audio extremadamente conservador.

        Debes analizar un audio en español y devolver SOLO JSON con este formato exacto:
        {
          "hasMeaningfulSpeech": true,
          "transcription": "texto literal",
          "reason": "OK"
        }

        REGLAS CRITICAS:
        1. Si el audio contiene silencio, respiración, ruido, clics, manipulación del micrófono, audio accidental o voz ininteligible, devuelve:
           {"hasMeaningfulSpeech": false, "transcription": "", "reason": "NO_SPEECH"}
        2. Si el audio contiene solo muletillas o interjecciones aisladas como "uh", "eh", "mmm", devuelve NO_SPEECH.
        3. Si dudas, devuelve NO_SPEECH.
        4. No inventes palabras. No completes frases. No interpretes intenciones.
        5. Si sí hay voz útil, transcribe de forma literal lo que realmente se oye.
        TXT;
}
