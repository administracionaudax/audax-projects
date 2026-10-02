<?php

namespace App\Domain\Chat\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * whisper.cpp en el propio servidor (whisper-server en el contenedor audax-whisper, arrancado con
 * --convert para que ffmpeg acepte los formatos del navegador). Una petición cada vez: el servidor
 * procesa de una en una y el worker de transcripciones tiene un solo proceso.
 * El campo `duration` de /inference (milisegundos; whisper.cpp v1.9.4, examples/server/server.cpp,
 * get_req_parameters → wparams.duration_ms) limita el audio que se procesa al máximo de los
 * audios; la respuesta sigue dando la duración real del fichero (D-116).
 */
final class WhisperServerTranscriber implements TranscriptionService
{
    public function __construct(
        private readonly string $url,
        private readonly string $model,
        private readonly int $timeout,
    ) {}

    public function transcribe(string $path, string $language, ?int $maxDurationMs = null): TranscriptionResult
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new TranscriptionFailed("No se puede leer el audio {$path}");
        }

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(5)
                ->attach('file', $handle, basename($path))
                ->post(rtrim($this->url, '/').'/inference', array_filter([
                    'language' => $language,
                    'response_format' => 'verbose_json',
                    'temperature' => '0.0',
                    'temperature_inc' => '0.2',
                    'duration' => $maxDurationMs !== null && $maxDurationMs > 0 ? (string) $maxDurationMs : null,
                ], fn (?string $value): bool => $value !== null));
        } catch (ConnectionException $e) {
            throw new TranscriptionFailed('El transcriptor no responde: '.$e->getMessage(), previous: $e);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! $response->successful()) {
            throw new TranscriptionFailed("El transcriptor respondió {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data['text']) || ! is_string($data['text'])) {
            throw new TranscriptionFailed('Respuesta del transcriptor sin texto');
        }

        // whisper-server corta los segmentos por tiempo, a veces a mitad de palabra («fer» +
        // «retería»): el texto se une tal cual, sin separadores, y después se normalizan espacios.
        $segments = is_array($data['segments'] ?? null) ? $data['segments'] : [];
        $joined = '';
        $lastEnd = null;
        foreach ($segments as $segment) {
            if (is_array($segment) && is_string($segment['text'] ?? null)) {
                $joined .= $segment['text'];
                $lastEnd = is_numeric($segment['end'] ?? null) ? (float) $segment['end'] : $lastEnd;
            }
        }
        $text = $segments === [] ? $data['text'] : $joined;

        $seconds = is_numeric($data['duration'] ?? null) ? (float) $data['duration'] : $lastEnd;

        return new TranscriptionResult(
            text: trim(preg_replace('/\s+/u', ' ', $text) ?? ''),
            language: is_string($data['language'] ?? null) ? self::languageCode($data['language'], $language) : $language,
            durationMs: $seconds === null ? null : (int) round($seconds * 1000),
        );
    }

    /**
     * whisper-server devuelve el idioma por su nombre en inglés («spanish»).
     */
    private static function languageCode(string $language, string $requested): string
    {
        return match (mb_strtolower($language)) {
            'spanish', 'es' => 'es',
            'english', 'en' => 'en',
            'catalan', 'ca' => 'ca',
            default => mb_strlen($language) <= 3 ? mb_strtolower($language) : $requested,
        };
    }

    public function engine(): string
    {
        return 'whisper.cpp';
    }

    public function model(): string
    {
        return $this->model;
    }
}
