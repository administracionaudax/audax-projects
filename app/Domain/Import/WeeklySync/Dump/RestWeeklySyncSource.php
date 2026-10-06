<?php

namespace App\Domain\Import\WeeklySync\Dump;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

/**
 * Origen de WeeklySync por la API REST de Supabase (PostgREST) con la clave secreta, en lugar de la
 * conexión directa a PostgreSQL (D-237): sirve cuando no se tiene la contraseña de la base. La
 * clave secreta salta las RLS, así que lee todas las filas. Solo GET: nunca escribe.
 *
 * Devuelve las mismas filas que {@see PostgresWeeklySyncSource} (`to_jsonb`): PostgREST serializa
 * igual (fechas ISO 8601 con zona, jsonb como objetos), ordenadas por `id` y paginadas.
 */
final class RestWeeklySyncSource implements WeeklySyncSource
{
    /** Por debajo del máximo de filas por respuesta de Supabase (1.000 por defecto). */
    public const int PAGE = 500;

    /** @var array<string, bool> */
    private array $exists = [];

    public function __construct(
        private readonly string $projectUrl,
        #[SensitiveParameter] private readonly string $serviceKey,
    ) {}

    public function has(string $table): bool
    {
        self::guard($table);

        if (! isset($this->exists[$table])) {
            $response = $this->send($table, ['select' => '*', 'limit' => 0]);

            if ($response->successful()) {
                $this->exists[$table] = true;
            } elseif ($response->status() === 404 || str_contains($response->body(), 'PGRST205') || str_contains($response->body(), '42P01')) {
                $this->exists[$table] = false;
            } else {
                throw new RuntimeException("La API de WeeklySync ha respondido {$response->status()} al comprobar la tabla {$table}. Revisa WEEKLYSYNC_URL y WEEKLYSYNC_SERVICE_KEY.");
            }
        }

        return $this->exists[$table];
    }

    public function rows(string $table): iterable
    {
        self::guard($table);

        for ($offset = 0; ; $offset += self::PAGE) {
            $response = $this->send($table, ['select' => '*', 'order' => 'id.asc', 'limit' => self::PAGE, 'offset' => $offset]);

            if (! $response->successful()) {
                throw new RuntimeException("No se ha podido leer la tabla {$table} por la API (HTTP {$response->status()}).");
            }

            $page = $response->json();

            if (! is_array($page)) {
                throw new RuntimeException("La API ha devuelto algo que no es una lista al leer la tabla {$table}.");
            }

            foreach ($page as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            if (count($page) < self::PAGE) {
                return;
            }
        }
    }

    public function close(): void
    {
        // Sin conexión abierta: cada página es una petición.
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function send(string $table, array $query): Response
    {
        try {
            return $this->client()->get(rtrim($this->projectUrl, '/').'/rest/v1/'.rawurlencode($table), $query);
        } catch (ConnectionException) {
            throw new RuntimeException('No se ha podido conectar con la API de WeeklySync. Revisa WEEKLYSYNC_URL y la conexión.');
        }
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'apikey' => $this->serviceKey,
            'Authorization' => 'Bearer '.$this->serviceKey,
            'Accept' => 'application/json',
        ])
            ->timeout(120)
            ->connectTimeout(20)
            ->retry(3, 2000, fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false);
    }

    private static function guard(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new RuntimeException("Nombre de tabla no válido: {$table}");
        }
    }
}
