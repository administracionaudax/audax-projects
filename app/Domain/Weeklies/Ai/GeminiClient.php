<?php

namespace App\Domain\Weeklies\Ai;

use App\Domain\Weeklies\SatisfactionStabilizer;
use App\Enums\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Gemini por la API de AI Studio (D-146): POST {base}/models/{model}:generateContent con la
 * clave en la cabecera `x-goog-api-key` (nunca en la URL ni en los registros).
 *
 * - JSON con responseMimeType application/json y, si se da, responseSchema (F.1 del inventario),
 * - reintentos ante 429 (5, 10 y 15 s, como el original) y 5xx o red (2, 4 y 6 s); nunca ante 4xx,
 * - tiempo máximo por intento (services.gemini.timeout),
 * - cada llamada, con éxito o error, en ai_usage con los tokens y el coste estimado (F-173).
 *
 * El modelo sale de GEMINI_MODEL (F-174): se cambia en .env sin desplegar cuando Google retira uno.
 */
final class GeminiClient implements LlmClient
{
    public function __construct(
        private readonly AiUsageRecorder $usage,
        private readonly ?string $key,
        private readonly string $model,
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        private readonly int $timeout = 120,
        private readonly int $tries = 3,
    ) {}

    public static function fromConfig(AiUsageRecorder $usage): self
    {
        return new self(
            usage: $usage,
            key: is_string($key = config('services.gemini.key')) && $key !== '' ? $key : null,
            model: (string) config('services.gemini.model', 'gemini-2.5-flash'),
            baseUrl: rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
            timeout: (int) config('services.gemini.timeout', 120),
            tries: max(1, (int) config('services.gemini.tries', 3)),
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(LlmRequest $request): LlmResponse
    {
        if ($this->key === null) {
            throw new LlmNotConfigured('GEMINI_API_KEY no está configurada.');
        }

        $started = hrtime(true);

        try {
            $response = $this->send($request);
        } catch (ConnectionException $e) {
            $this->fail($request, $started, 'connection: '.$e->getMessage());

            throw new LlmUnavailable('Gemini no responde.', previous: $e);
        }

        if ($response->failed()) {
            $this->fail($request, $started, 'HTTP '.$response->status().' '.$this->errorMessage($response));

            throw new LlmUnavailable("Gemini respondió {$response->status()}.");
        }

        return $this->parse($request, $response, $started);
    }

    /**
     * Cuerpo de la petición (público para los tests del contrato).
     *
     * @return array<string, mixed>
     */
    public function payload(LlmRequest $request): array
    {
        $generation = array_filter([
            'temperature' => $request->temperature,
            'maxOutputTokens' => $request->maxOutputTokens,
            'responseMimeType' => $request->wantsJson() ? 'application/json' : null,
            'responseSchema' => $request->responseSchema,
        ], fn (mixed $value): bool => $value !== null);

        return array_filter([
            'contents' => [['role' => 'user', 'parts' => array_values(array_filter([
                $request->audio === null ? null : ['inlineData' => [
                    'mimeType' => $request->audio->mimeType,
                    'data' => base64_encode($request->audio->bytes),
                ]],
                ['text' => $request->prompt],
            ]))]],
            'systemInstruction' => $request->system === null ? null : ['parts' => [['text' => $request->system]]],
            'generationConfig' => $generation === [] ? null : $generation,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function send(LlmRequest $request): Response
    {
        return Http::withHeaders(['x-goog-api-key' => (string) $this->key])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout($this->timeout)
            ->retry(
                $this->tries,
                fn (int $attempt, Throwable $e): int => $this->backoff($attempt, $e),
                fn (Throwable $e): bool => $this->retryable($e),
                throw: false,
            )
            ->post("{$this->baseUrl}/models/{$this->model}:generateContent", $this->payload($request));
    }

    private function backoff(int $attempt, Throwable $e): int
    {
        $rateLimited = $e instanceof RequestException && $e->response->status() === 429;

        return ($rateLimited ? 5000 : 2000) * $attempt;
    }

    private function retryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    private function parse(LlmRequest $request, Response $response, int|float $started): LlmResponse
    {
        /** @var array<string, mixed> $body */
        $body = (array) $response->json();
        $candidate = $body['candidates'][0] ?? null;
        $parts = is_array($candidate) ? ($candidate['content']['parts'] ?? []) : [];
        $text = implode('', array_map(fn (mixed $part): string => is_array($part) && is_string($part['text'] ?? null) && ! ($part['thought'] ?? false) ? $part['text'] : '', is_array($parts) ? $parts : []));
        $finish = is_array($candidate) && is_string($candidate['finishReason'] ?? null) ? $candidate['finishReason'] : null;

        $usage = is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];
        $prompt = self::int($usage['promptTokenCount'] ?? null);
        $output = self::int($usage['candidatesTokenCount'] ?? null);
        $thoughts = self::int($usage['thoughtsTokenCount'] ?? null);
        $responseTokens = $output === null && $thoughts === null ? null : ($output ?? 0) + ($thoughts ?? 0);
        $total = self::int($usage['totalTokenCount'] ?? null);
        $model = is_string($body['modelVersion'] ?? null) ? $body['modelVersion'] : $this->model;
        $latency = (int) round((hrtime(true) - $started) / 1_000_000);

        $json = null;
        $error = null;

        if (trim($text) === '') {
            $error = 'empty response'.($finish !== null ? " ({$finish})" : '');
        } elseif ($request->wantsJson()) {
            $decoded = json_decode(SatisfactionStabilizer::cleanJsonResponse($text), true);
            $json = is_array($decoded) ? $decoded : null;
            $error = $json === null ? 'invalid JSON'.($finish !== null ? " ({$finish})" : '') : null;
        }

        $this->usage->record(
            provider: AiProvider::Gemini,
            model: $model,
            feature: $request->feature,
            success: $error === null,
            operation: $request->operation,
            user: $request->user,
            subject: $request->subject,
            latencyMs: $latency,
            promptTokens: $prompt,
            responseTokens: $responseTokens,
            totalTokens: $total,
            estimatedCostUsd: AiPricing::gemini($this->model, $prompt, $responseTokens),
            error: $error,
            metadata: $request->metadata,
        );

        if ($error !== null) {
            throw new LlmInvalidResponse("Gemini: {$error}.");
        }

        return new LlmResponse($text, $json, $model, $prompt, $responseTokens, $total, $latency, $finish);
    }

    private function fail(LlmRequest $request, int|float $started, string $error): void
    {
        $this->usage->record(
            provider: AiProvider::Gemini,
            model: $this->model,
            feature: $request->feature,
            success: false,
            operation: $request->operation,
            user: $request->user,
            subject: $request->subject,
            latencyMs: (int) round((hrtime(true) - $started) / 1_000_000),
            error: $error,
            metadata: $request->metadata,
        );
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) ? mb_substr($message, 0, 300) : '';
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
