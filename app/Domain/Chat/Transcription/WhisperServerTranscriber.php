<?php

namespace App\Domain\Chat\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * whisper.cpp en el propio servidor (whisper-server en el contenedor audax-whisper, arrancado con
 * --convert para que ffmpeg acepte los formatos del navegador). Una petición cada vez: el servidor
 * procesa de una en una y el worker de transcripciones tiene un solo proceso.
 */
final class WhisperServerTranscriber implements TranscriptionService
{
    public function __construct(
        private readonly string $url,
        private readonly string $model,
        private readonly int $timeout,
    ) {}

    public function transcribe(string $path, string $language): TranscriptionResult
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new TranscriptionFailed("No se puede leer el audio {$path}");
        }

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(5)
                ->attach('file', $handle, basename($path))
                ->post(rtrim($this->url, '/').'/inference', [
                    'language' => $language,
                    'response_format' => 'verbose_json',
                    'temperature' => '0.0',
                    'temperature_inc' => '0.2',
                ]);
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

        $duration = null;
        $segments = $data['segments'] ?? null;
        if (is_array($segments) && $segments !== []) {
            $last = end($segments);
            if (is_array($last) && is_numeric($last['end'] ?? null)) {
                $duration = (int) round(((float) $last['end']) * 1000);
            }
        }

        return new TranscriptionResult(
            text: trim(preg_replace('/\s+/u', ' ', $data['text']) ?? ''),
            language: is_string($data['language'] ?? null) ? $data['language'] : $language,
            durationMs: $duration,
        );
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
