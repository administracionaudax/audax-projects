<?php

namespace App\Domain\Weeklies\Dictation;

/**
 * Reglas del dictado de la weekly (F-050, F-171 y F-172), portadas de WeeklySync
 * (`ws:src/lib/audioTranscription.js` y `ws:supabase/functions/transcribe-audio/index.ts`) y
 * adaptadas a Whisper, que no dice «no hay voz»: ante el silencio suele «alucinar» frases de
 * subtítulos. Lógica pura.
 *
 * - Un audio de menos de 0,7 s o de menos de 1,5 KB no se transcribe (too_short).
 * - De la transcripción se quitan las anotaciones entre corchetes o paréntesis («[Música]») y las
 *   frases típicas de alucinación de Whisper; lo que queda tiene que parecer voz útil: no vacío, no
 *   solo muletillas («eh», «mmm», «vale»…) ni una palabra suelta de menos de 4 letras (no_speech).
 * - La limpieza con IA (opcional) se salta en los textos muy cortos (4 palabras o menos, o menos de
 *   24 caracteres): no hay nada que corregir.
 */
final class DictationText
{
    public const int MIN_DURATION_MS = 700;

    public const int MIN_BYTES = 1536;

    public const string WARNING_TOO_SHORT = 'too_short';

    public const string WARNING_NO_SPEECH = 'no_speech';

    /** La limpieza con IA no ha respondido: el texto es la transcripción literal (D-227). */
    public const string WARNING_CLEANUP_FAILED = 'cleanup_failed';

    /** Muletillas e interjecciones sueltas (ws:transcribe-audio `fillerOnlyPattern`). */
    private const string FILLER_ONLY = '/^(uh+|um+|umm+|eh+|emm+|mmm+|hmm+|aj[áa]m+|ah+|ehm+|mm+hmm+|vale+|ok+|okay+)$/iu';

    /** Frases que Whisper inventa sobre el silencio o el ruido (en minúsculas y sin puntuación). */
    private const array HALLUCINATIONS = [
        'subtítulos realizados por la comunidad de amara org',
        'subtítulos por la comunidad de amara org',
        'subtitulado por la comunidad de amara org',
        'gracias por ver el vídeo',
        'gracias por ver',
        'gracias por vernos',
        'suscríbete al canal',
        'suscríbete',
        'música',
        'silencio',
        'aplausos',
        'risas',
        'gracias',
    ];

    public static function isTooShort(?int $durationMs, ?int $bytes): bool
    {
        return (int) $durationMs < self::MIN_DURATION_MS || (int) $bytes < self::MIN_BYTES;
    }

    /**
     * La transcripción sin anotaciones ni alucinaciones conocidas, con los espacios normalizados.
     */
    public static function strip(string $text): string
    {
        $text = (string) preg_replace('/\[[^\]]*\]|\([^)]*\)|\*[^*]*\*|♪+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return self::isHallucination($text) ? '' : $text;
    }

    /** ¿Parece voz útil? (ws:transcribe-audio `looksLikeMeaningfulSpeech`). */
    public static function isMeaningful(string $text): bool
    {
        $normalized = self::normalize($text);

        if ($normalized === '' || mb_strlen($normalized) < 3 || preg_match(self::FILLER_ONLY, $normalized) === 1) {
            return false;
        }

        $words = explode(' ', $normalized);

        if (count($words) === 1 && (mb_strlen($words[0]) < 4 || preg_match(self::FILLER_ONLY, $words[0]) === 1)) {
            return false;
        }

        // Solo muletillas, aunque sean varias («eh mmm vale»).
        if (array_filter($words, fn (string $word): bool => preg_match(self::FILLER_ONLY, $word) !== 1) === []) {
            return false;
        }

        return preg_match('/[\p{L}\p{N}]/u', $normalized) === 1;
    }

    /** ¿Tan corto que no merece la limpieza con IA? (ws:transcribe-audio `shouldSkipCleanup`). */
    public static function shouldSkipCleanup(string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return true;
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) <= 4 || mb_strlen($text) < 24;
    }

    /** Quita las vallas de código que a veces devuelve el modelo (ws `cleanModelText`). */
    public static function cleanModelText(string $text): string
    {
        $trimmed = trim($text);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = trim((string) preg_replace(['/^```[a-z]*\n?/i', '/```$/'], '', $trimmed));
        }

        return $trimmed;
    }

    private static function isHallucination(string $text): bool
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return true;
        }

        foreach (self::HALLUCINATIONS as $phrase) {
            if ($normalized === $phrase) {
                return true;
            }
        }

        return false;
    }

    /** Minúsculas, sin signos y con un espacio entre palabras. */
    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
