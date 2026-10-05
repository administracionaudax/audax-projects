<?php

namespace App\Domain\Import\WeeklySync\Dump;

use App\Domain\Import\WeeklySync\WeeklySyncDump;
use PDO;
use PDOException;
use RuntimeException;
use SensitiveParameter;

/**
 * La base de WeeklySync (Supabase) en SOLO LECTURA (D-213):
 *   - una sola transacción `READ ONLY` y `REPEATABLE READ`: PostgreSQL rechaza cualquier escritura y
 *     todas las tablas salen de la misma foto, aunque alguien siga usando WeeklySync,
 *   - solo `SELECT to_jsonb(t)` de las tablas de la lista (WeeklySyncDump::TABLES), nunca SQL libre,
 *   - límite de 2 minutos por consulta,
 *   - los errores no llevan la URL ni la contraseña: solo el código SQLSTATE.
 */
final class PostgresWeeklySyncSource implements WeeklySyncSource
{
    private PDO $pdo;

    /** @var array<string, bool> */
    private array $exists = [];

    public function __construct(#[SensitiveParameter] string $url, #[SensitiveParameter] ?string $password = null)
    {
        $parts = self::parse($url, $password);

        try {
            $this->pdo = new PDO($parts['dsn'], $parts['user'], $parts['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
                PDO::ATTR_TIMEOUT => 20,
            ]);
            $this->pdo->exec("SET statement_timeout = '120s'");
            $this->pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
            $this->pdo->exec('START TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        } catch (PDOException $e) {
            throw new RuntimeException('No se ha podido conectar a la base de WeeklySync (SQLSTATE '.self::state($e).'). Revisa la URL, la contraseña y que uses el «Session pooler».');
        }
    }

    public function has(string $table): bool
    {
        self::guard($table);

        if (! isset($this->exists[$table])) {
            $statement = $this->pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
            $statement->execute(['public.'.$table]);
            $this->exists[$table] = (bool) $statement->fetchColumn();
        }

        return $this->exists[$table];
    }

    public function rows(string $table): iterable
    {
        self::guard($table);

        try {
            $statement = $this->pdo->query("SELECT to_jsonb(t)::text FROM public.\"{$table}\" t ORDER BY t.id");
        } catch (PDOException $e) {
            throw new RuntimeException("No se ha podido leer la tabla {$table} (SQLSTATE ".self::state($e).').');
        }

        if ($statement === false) {
            return;
        }

        while (($json = $statement->fetchColumn()) !== false) {
            $row = json_decode((string) $json, true);

            if (is_array($row)) {
                yield $row;
            }
        }
    }

    public function close(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec('ROLLBACK');
            }
        } catch (PDOException) {
            // Nada que deshacer: era de solo lectura.
        }
    }

    /**
     * URL postgresql://usuario[:contraseña]@host[:puerto]/base[?sslmode=…] → DSN de PDO. La
     * contraseña de WEEKLYSYNC_DB_PASSWORD, si la hay, manda sobre la de la URL. Sin sslmode, se
     * exige TLS (`require`).
     *
     * @return array{dsn: string, user: string, password: string|null}
     */
    public static function parse(#[SensitiveParameter] string $url, #[SensitiveParameter] ?string $password = null): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'], $parts['user']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['postgres', 'postgresql'], true)) {
            throw new RuntimeException('WEEKLYSYNC_DB_URL no se entiende. Usa la forma postgresql://usuario@host:5432/postgres (con la contraseña aparte, en WEEKLYSYNC_DB_PASSWORD).');
        }

        parse_str($parts['query'] ?? '', $query);
        $sslmode = is_string($query['sslmode'] ?? null) && $query['sslmode'] !== '' ? $query['sslmode'] : 'require';
        $database = ltrim($parts['path'] ?? '', '/');

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $parts['host'],
            (int) ($parts['port'] ?? 5432),
            $database !== '' ? rawurldecode($database) : 'postgres',
            $sslmode,
        );

        $fromUrl = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;

        return [
            'dsn' => $dsn,
            'user' => rawurldecode($parts['user']),
            'password' => $password ?? $fromUrl,
        ];
    }

    /**
     * Solo las tablas de la lista: el nombre va dentro del SQL.
     */
    private static function guard(string $table): void
    {
        if (! in_array($table, WeeklySyncDump::TABLES, true)) {
            throw new RuntimeException("La tabla {$table} no está en la lista del volcado.");
        }
    }

    private static function state(PDOException $e): string
    {
        $info = $e->errorInfo;

        return is_array($info) && isset($info[0]) && is_string($info[0]) ? $info[0] : (string) $e->getCode();
    }
}
