<?php

namespace App\Domain\Weeklies\Ai;

use App\Enums\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Cloud Text-to-Speech (D-146, F-084), como `ws:generate-audio-tts`: voz es-ES-Journey-F,
 * MP3, velocidad 0,95 y +2 dB. El texto se parte en trozos de 4.500 bytes como mucho (por frases,
 * luego por palabras y, si hace falta, por caracteres) y los MP3 se unen quitando la etiqueta ID3
 * de los trozos siguientes. La clave va en la cabecera `x-goog-api-key`.
 *
 * Esqueleto del contrato 10.1: la llamada HTTP y el troceo están; el guion por secciones, el
 * almacenamiento y la duración llegan en 10.3.
 */
final class GoogleTtsSynthesizer implements SpeechSynthesizer
{
    public const int MAX_BYTES = 4500;

    public function __construct(
        private readonly AiUsageRecorder $usage,
        private readonly ?string $key,
        private readonly string $voice = 'es-ES-Journey-F',
        private readonly string $language = 'es-ES',
        private readonly string $baseUrl = 'https://texttospeech.googleapis.com/v1',
        private readonly int $timeout = 60,
    ) {}

    public static function fromConfig(AiUsageRecorder $usage): self
    {
        return new self(
            usage: $usage,
            key: is_string($key = config('services.google_tts.key')) && $key !== '' ? $key : null,
            voice: (string) config('services.google_tts.voice', 'es-ES-Journey-F'),
            language: (string) config('services.google_tts.language', 'es-ES'),
            baseUrl: rtrim((string) config('services.google_tts.base_url', 'https://texttospeech.googleapis.com/v1'), '/'),
            timeout: (int) config('services.google_tts.timeout', 60),
        );
    }

    public function voice(): string
    {
        return $this->voice;
    }

    public function synthesize(SpeechRequest $request): SynthesizedSpeech
    {
        if ($this->key === null) {
            throw new LlmNotConfigured('GOOGLE_TTS_API_KEY no está configurada.');
        }

        $chunks = self::chunks($request->text);
        $characters = mb_strlen($request->text);
        $started = hrtime(true);
        $audio = [];

        try {
            foreach ($chunks as $chunk) {
                $audio[] = $this->synthesizeChunk($chunk);
            }
        } catch (LlmException $e) {
            $this->record($request, $started, $characters, $e->getMessage());

            throw $e;
        }

        $this->record($request, $started, $characters, null, count($chunks));

        return new SynthesizedSpeech(self::mergeMp3($audio), $this->voice, $characters, count($chunks));
    }

    /**
     * Cuerpo de la petición de un trozo (público para los tests del contrato).
     *
     * @return array<string, mixed>
     */
    public function payload(string $text): array
    {
        return [
            'input' => ['text' => $text],
            'voice' => ['languageCode' => $this->language, 'name' => $this->voice],
            'audioConfig' => ['audioEncoding' => 'MP3', 'speakingRate' => 0.95, 'volumeGainDb' => 2.0],
        ];
    }

    /**
     * Trocea el texto en partes de MAX_BYTES bytes como mucho (chunkTextForTts del original).
     *
     * @return list<string>
     */
    public static function chunks(string $text, int $maxBytes = self::MAX_BYTES): array
    {
        $normalized = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text)));

        if ($normalized === '') {
            return [];
        }

        if (strlen($normalized) <= $maxBytes) {
            return [$normalized];
        }

        $segments = [];

        foreach (preg_split('/(?<=[.!?])\s+|\n{2,}/u', $normalized) ?: [] as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            array_push($segments, ...(strlen($segment) <= $maxBytes ? [$segment] : self::splitOversized($segment, $maxBytes)));
        }

        $chunks = [];
        $current = '';

        foreach ($segments as $segment) {
            $candidate = trim($current === '' ? $segment : "{$current} {$segment}");

            if ($candidate !== '' && strlen($candidate) <= $maxBytes) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            $current = $segment;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Une los MP3 quitando la etiqueta ID3v2 de todos menos el primero (mergeMp3Chunks).
     *
     * @param  list<string>  $parts  bytes de cada MP3
     */
    public static function mergeMp3(array $parts): string
    {
        $merged = '';

        foreach ($parts as $index => $bytes) {
            $merged .= $index === 0 ? $bytes : self::stripId3($bytes);
        }

        return $merged;
    }

    private static function stripId3(string $bytes): string
    {
        if (strlen($bytes) < 10 || substr($bytes, 0, 3) !== 'ID3') {
            return $bytes;
        }

        $size = ((ord($bytes[6]) & 0x7F) << 21) | ((ord($bytes[7]) & 0x7F) << 14) | ((ord($bytes[8]) & 0x7F) << 7) | (ord($bytes[9]) & 0x7F);
        $footer = (ord($bytes[5]) & 0x10) !== 0 ? 10 : 0;

        return substr($bytes, min(10 + $size + $footer, strlen($bytes)));
    }

    /**
     * @return list<string>
     */
    private static function splitOversized(string $segment, int $maxBytes): array
    {
        $pieces = [];
        $current = '';

        foreach (preg_split('/\s+/u', $segment, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";

            if (strlen($candidate) <= $maxBytes) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $pieces[] = $current;
            }

            if (strlen($word) <= $maxBytes) {
                $current = $word;

                continue;
            }

            $fragment = '';

            foreach (mb_str_split($word) as $char) {
                if (strlen($fragment.$char) <= $maxBytes) {
                    $fragment .= $char;

                    continue;
                }

                if ($fragment !== '') {
                    $pieces[] = $fragment;
                }

                $fragment = $char;
            }

            $current = $fragment;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    private function synthesizeChunk(string $text): string
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) $this->key])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout($this->timeout)
                ->retry(3, fn (int $attempt): int => 2000 * $attempt, fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->status() >= 500)), throw: false)
                ->post("{$this->baseUrl}/text:synthesize", $this->payload($text));
        } catch (ConnectionException $e) {
            throw new LlmUnavailable('Google TTS no responde.', previous: $e);
        }

        if ($response->failed()) {
            throw new LlmUnavailable("Google TTS respondió {$response->status()}.");
        }

        $audio = $response->json('audioContent');
        $bytes = is_string($audio) ? base64_decode($audio, true) : false;

        if ($bytes === false || $bytes === '') {
            throw new LlmInvalidResponse('Google TTS no devolvió audio.');
        }

        return $bytes;
    }

    private function record(SpeechRequest $request, int|float $started, int $characters, ?string $error, int $chunks = 0): void
    {
        $this->usage->record(
            provider: AiProvider::GoogleTts,
            model: $this->voice,
            feature: $request->feature,
            success: $error === null,
            operation: $request->operation,
            user: $request->user,
            subject: $request->subject,
            latencyMs: (int) round((hrtime(true) - $started) / 1_000_000),
            characterCount: $characters,
            estimatedCostUsd: $error === null ? AiPricing::speech($characters) : null,
            error: $error,
            metadata: [...$request->metadata, ...($chunks > 0 ? ['chunks' => $chunks] : [])],
        );
    }
}
