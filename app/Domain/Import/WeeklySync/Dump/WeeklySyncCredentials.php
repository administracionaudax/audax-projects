<?php

namespace App\Domain\Import\WeeklySync\Dump;

use RuntimeException;
use SensitiveParameter;

/**
 * Credenciales del volcado (D-213), leídas de un fichero local fuera de Git (por defecto
 * ~/.config/audax/weeklysync.env) con permisos 600:
 *
 *   WEEKLYSYNC_DB_URL=postgresql://postgres.<ref>@aws-0-<región>.pooler.supabase.com:5432/postgres?sslmode=require
 *   WEEKLYSYNC_DB_PASSWORD=<contraseña de la base>   (opcional si ya va, codificada, en la URL)
 *   WEEKLYSYNC_URL=https://<ref>.supabase.co
 *   WEEKLYSYNC_SERVICE_KEY=<clave secreta: service_role o sb_secret_…>
 *
 * Los valores nunca se imprimen ni salen en los mensajes de error: solo los nombres de las claves.
 * __debugInfo() los oculta también en un volcado accidental (dd, dump, var_dump).
 */
final readonly class WeeklySyncCredentials
{
    public const string DEFAULT_PATH = '~/.config/audax/weeklysync.env';

    public function __construct(
        #[SensitiveParameter] public string $databaseUrl,
        #[SensitiveParameter] public ?string $databasePassword,
        public ?string $projectUrl,
        #[SensitiveParameter] public ?string $serviceKey,
    ) {}

    /**
     * @throws RuntimeException si el fichero no existe, lo pueden leer otros o le falta algo
     */
    public static function load(string $path, bool $needsStorage = true): self
    {
        $path = self::expand($path);

        if (! is_file($path)) {
            throw new RuntimeException("No existe el fichero de credenciales {$path}. Créalo como se explica en docs/PLAN-FASE-10.md (10.8).");
        }

        $permissions = fileperms($path);

        if ($permissions === false || ($permissions & 0o077) !== 0) {
            throw new RuntimeException("El fichero de credenciales {$path} lo pueden leer otras cuentas: déjalo solo para ti con «chmod 600 {$path}».");
        }

        $values = self::parse((string) file_get_contents($path));
        $databaseUrl = $values['WEEKLYSYNC_DB_URL'] ?? '';

        if ($databaseUrl === '') {
            throw new RuntimeException("Falta WEEKLYSYNC_DB_URL en {$path}.");
        }

        if (! preg_match('#^postgres(ql)?://#i', $databaseUrl)) {
            throw new RuntimeException('WEEKLYSYNC_DB_URL no es una URL de PostgreSQL (postgresql://…).');
        }

        $projectUrl = rtrim($values['WEEKLYSYNC_URL'] ?? '', '/');
        $serviceKey = $values['WEEKLYSYNC_SERVICE_KEY'] ?? '';

        if ($needsStorage) {
            if ($projectUrl === '' || ! preg_match('#^https://#i', $projectUrl)) {
                throw new RuntimeException("Falta WEEKLYSYNC_URL (https://<proyecto>.supabase.co) en {$path}.");
            }

            if ($serviceKey === '') {
                throw new RuntimeException("Falta WEEKLYSYNC_SERVICE_KEY en {$path}.");
            }
        }

        $password = $values['WEEKLYSYNC_DB_PASSWORD'] ?? '';

        return new self($databaseUrl, $password !== '' ? $password : null, $projectUrl !== '' ? $projectUrl : null, $serviceKey !== '' ? $serviceKey : null);
    }

    /**
     * KEY=valor por línea; admite comentarios (#), «export » y comillas simples o dobles.
     *
     * @return array<string, string>
     */
    public static function parse(#[SensitiveParameter] string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $equals = strpos($line, '=');

            if ($equals === false) {
                continue;
            }

            $key = trim(substr($line, 0, $equals));
            $value = trim(substr($line, $equals + 1));

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Sustituye «~» por la carpeta de la cuenta.
     */
    public static function expand(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');

            return (is_string($home) && $home !== '' ? rtrim($home, '/') : '').substr($path, 1);
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'databaseUrl' => '***',
            'databasePassword' => $this->databasePassword === null ? '' : '***',
            'projectUrl' => (string) $this->projectUrl,
            'serviceKey' => $this->serviceKey === null ? '' : '***',
        ];
    }
}
