<?php

namespace App\Domain\Billing\Holded;

use App\Enums\HoldedDocumentKind;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Cliente real de la API v2 de Holded (Fase 12, F1; D-384), solo lectura:
 * - `Authorization: Bearer <HOLDED_API_KEY>` (la clave nunca sale en mensajes ni registros),
 * - paginación por cursor (`?limit=&cursor=`): sigue el siguiente cursor de la respuesta
 *   (`meta.next_cursor`, `next_cursor`, `meta.cursor.next`, `pagination.next_cursor` o el `cursor`
 *   de `links.next`) hasta que no hay más, sin repetir un cursor,
 * - nunca más de `per_minute` peticiones por minuto (limitador compartido entre procesos; si se
 *   alcanza, espera a la ventana siguiente),
 * - 429: espera lo que diga `Retry-After` (como mucho `max_retry_after` segundos) y reintenta; 5xx y
 *   errores de conexión: reintenta con espera creciente; hasta `max_retries` veces,
 * - tiempo máximo por petición (`timeout`) y de conexión (`connect_timeout`),
 * - cualquier otro error, HoldedRequestFailed con un mensaje claro.
 */
final class HttpHoldedClient implements HoldedApi
{
    public const string LIMITER = 'holded-api';

    /** Páginas como mucho por listado: corta un cursor que nunca acaba. */
    private const int MAX_PAGES = 10_000;

    private int $requests = 0;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $key,
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        private readonly int $perMinute = 60,
        private readonly int $pageSize = 200,
        private readonly int $maxRetries = 5,
        private readonly int $maxRetryAfter = 120,
    ) {
        if (trim($this->key) === '') {
            throw HoldedRequestFailed::notConfigured();
        }
    }

    /** Con la configuración de config/services.php (holded). */
    public static function fromConfig(HttpFactory $http): self
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('services.holded', []);

        return new self(
            $http,
            (string) ($config['key'] ?? ''),
            rtrim((string) ($config['base_url'] ?? 'https://api.holded.com/api/v2'), '/'),
            max(1, (int) ($config['timeout'] ?? 30)),
            max(1, (int) ($config['connect_timeout'] ?? 10)),
            max(1, (int) ($config['per_minute'] ?? 60)),
            max(1, min(500, (int) ($config['page_size'] ?? 100))),
            max(0, (int) ($config['max_retries'] ?? 5)),
            max(1, (int) ($config['max_retry_after'] ?? 120)),
        );
    }

    public function contacts(): iterable
    {
        return $this->paginate('/contacts');
    }

    public function projects(): iterable
    {
        return $this->paginate('/projects');
    }

    public function invoices(): iterable
    {
        return $this->paginate('/invoices');
    }

    public function creditNotes(): iterable
    {
        return $this->paginate('/credit-notes');
    }

    public function creditNote(string $holdedId): array
    {
        $json = $this->send('/credit-notes/'.rawurlencode($holdedId), [])->json();
        $item = is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : $json;

        /** @var array<string, mixed> */
        return is_array($item) ? $item : [];
    }

    public function payments(): iterable
    {
        return $this->paginate('/payments');
    }

    public function pdf(string $holdedId, HoldedDocumentKind $kind): string
    {
        $path = ($kind === HoldedDocumentKind::CreditNote ? '/credit-notes/' : '/invoices/').rawurlencode($holdedId).'/pdf';
        $response = $this->send($path, [], 'application/pdf, application/json');
        $body = $response->body();

        if (str_starts_with($body, '%PDF')) {
            return $body;
        }

        // La v1 (y quizá la v2) lo manda en base64 dentro de un JSON.
        $json = $response->json();
        $encoded = is_array($json) ? ($json['data'] ?? $json['pdf'] ?? $json['content'] ?? null) : null;
        if (is_array($encoded)) {
            $encoded = $encoded['data'] ?? $encoded['pdf'] ?? $encoded['content'] ?? null;
        }
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;

        if (is_string($decoded) && str_starts_with($decoded, '%PDF')) {
            return $decoded;
        }

        throw HoldedRequestFailed::invalidPdf($path);
    }

    public function requestCount(): int
    {
        return $this->requests;
    }

    /**
     * Todos los objetos de un listado, página a página.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function paginate(string $path): Generator
    {
        $cursor = null;
        $seen = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['limit' => $this->pageSize] + ($cursor !== null ? ['cursor' => $cursor] : []);
            $json = $this->send($path, $query)->json();

            $items = self::items($json);
            foreach ($items as $item) {
                yield $item;
            }

            $cursor = self::nextCursor($json);
            if ($cursor === null || $items === [] || isset($seen[$cursor])) {
                return;
            }
            $seen[$cursor] = true;
        }
    }

    /**
     * Objetos de una página: `data` o la lista entera.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        $list = array_is_list($json) ? $json : ($json['data'] ?? $json['items'] ?? $json['results'] ?? []);

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** El siguiente cursor de una página, o null si es la última. */
    public static function nextCursor(mixed $json): ?string
    {
        if (! is_array($json) || array_is_list($json)) {
            return null;
        }

        // El formato real de la v2 (OpenAPI de Holded): {items, has_more, cursor}; cursor es null en la última.
        if (array_key_exists('has_more', $json) && $json['has_more'] === false) {
            return null;
        }

        $candidates = [
            $json['cursor'] ?? null,
            $json['meta']['next_cursor'] ?? null,
            $json['next_cursor'] ?? null,
            $json['meta']['cursor']['next'] ?? null,
            $json['pagination']['next_cursor'] ?? null,
            $json['meta']['pagination']['next_cursor'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        $next = $json['links']['next'] ?? null;
        if (is_string($next) && $next !== '') {
            parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
            $cursor = $query['cursor'] ?? null;

            return is_string($cursor) && $cursor !== '' ? $cursor : null;
        }

        return null;
    }

    /**
     * Una petición GET con el límite por minuto, los reintentos y los errores claros.
     *
     * @param  array<string, mixed>  $query
     */
    private function send(string $path, array $query = [], string $accept = 'application/json'): Response
    {
        for ($attempt = 0; ; $attempt++) {
            $this->throttle();
            $this->requests++;

            try {
                $response = $this->http
                    ->baseUrl($this->baseUrl)
                    ->withToken($this->key)
                    ->accept($accept)
                    ->withUserAgent('AudaxProyectos/1.0 (+https://projects.audaxstudio.com)')
                    ->timeout($this->timeout)
                    ->connectTimeout($this->connectTimeout)
                    ->get($path, $query);
            } catch (ConnectionException) {
                if ($attempt >= $this->maxRetries) {
                    throw HoldedRequestFailed::connection($path);
                }
                Sleep::for(self::backoff($attempt))->seconds();

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();

            if ($status === 429 && $attempt < $this->maxRetries) {
                Sleep::for($this->retryAfter($response, $attempt))->seconds();

                continue;
            }

            if ($status >= 500 && $attempt < min(2, $this->maxRetries)) {
                Sleep::for(self::backoff($attempt))->seconds();

                continue;
            }

            throw HoldedRequestFailed::forStatus($status, $path);
        }
    }

    /** Antes de cada petición: si ya van `per_minute` en la ventana, espera a la siguiente. */
    private function throttle(): void
    {
        while (RateLimiter::tooManyAttempts(self::LIMITER, $this->perMinute)) {
            Sleep::for(max(1, RateLimiter::availableIn(self::LIMITER)))->seconds();
        }

        RateLimiter::hit(self::LIMITER, 60);
    }

    /** Segundos de `Retry-After` (número o fecha HTTP), entre 1 y max_retry_after. */
    private function retryAfter(Response $response, int $attempt): int
    {
        $header = trim($response->header('Retry-After'));

        $seconds = match (true) {
            $header === '' => self::backoff($attempt),
            ctype_digit($header) => (int) $header,
            default => self::secondsUntil($header) ?? self::backoff($attempt),
        };

        return max(1, min($this->maxRetryAfter, $seconds));
    }

    private static function secondsUntil(string $date): ?int
    {
        try {
            return (int) max(1, Carbon::now()->diffInSeconds(Carbon::parse($date), false));
        } catch (\Throwable) {
            return null;
        }
    }

    /** 2, 4, 8… segundos (como mucho 60). */
    private static function backoff(int $attempt): int
    {
        return (int) min(60, 2 ** ($attempt + 1));
    }
}
