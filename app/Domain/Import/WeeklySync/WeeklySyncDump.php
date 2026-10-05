<?php

namespace App\Domain\Import\WeeklySync;

use JsonException;
use RuntimeException;

/**
 * Volcado de WeeklySync en una carpeta (D-213): lo escribe `app:dump-weeklysync` en el Mac del
 * propietario y lo lee `app:import-weeklysync` en el servidor.
 *
 *   manifest.json              formato, fecha, recuentos y sha256 de cada tabla y de cada fichero
 *   tables/<tabla>.json        las filas de la tabla, tal cual las da PostgreSQL (to_jsonb)
 *   storage/<bucket>/<ruta>    los ficheros del Storage que usan las filas
 *
 * El importador comprueba el sha256 de cada tabla y de cada fichero contra el manifiesto antes de
 * usarlos: un volcado incompleto o tocado se detecta, no se importa a medias.
 */
final class WeeklySyncDump
{
    public const int FORMAT = 1;

    public const string SOURCE = 'weeklysync';

    /**
     * Tablas que se vuelcan, en el orden del volcado. Las de plataforma (multi-tenant), las bolsas y
     * la foto del estado de proyectos por OCR no se migran (D-148 y D-149), pero las filas actuales de
     * la foto ayudan a casar clientes por sus códigos (WEEKLY-INVENTARIO D.3).
     */
    public const array TABLES = [
        'users',
        'user_identities',
        'clients',
        'client_team_members',
        'project_status_entries',
        'week_cycles',
        'weekly_submissions',
        'client_report_entries',
        'weekly_submission_drafts',
        'weekly_submission_draft_entries',
        'tasks',
        'email_reminders',
        'web_notification_reminders',
        'email_templates',
        'email_log',
        'help_settings',
        'help_releases',
        'help_release_changes',
        'help_manual_updates',
        'help_update_likes',
        'help_tutorials',
        'help_faq_sections',
        'help_faqs',
        'suggestion_boards',
        'suggestion_categories',
        'suggestion_posts',
        'suggestion_post_attachments',
        'suggestion_votes',
        'suggestion_comments',
        'suggestion_comment_reactions',
        'suggestion_comment_attachments',
        'suggestion_status_events',
        'ai_usage_events',
    ];

    /** Buckets del Storage de Supabase que se descargan. */
    public const string AUDIO_BUCKET = 'audio-submissions';

    public const string HELP_BUCKET = 'help-content';

    public const string SUGGESTIONS_BUCKET = 'suggestion-attachments';

    /** @var array<string, mixed> */
    private array $manifest;

    /** @var array<string, list<array<string, mixed>>> */
    private array $cache = [];

    /** @var array<string, true> tablas ya verificadas */
    private array $verified = [];

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function __construct(public readonly string $directory, array $manifest)
    {
        $this->manifest = $manifest;
    }

    /**
     * @throws RuntimeException si no hay manifiesto o no es de este formato
     */
    public static function open(string $directory): self
    {
        $directory = rtrim($directory, '/');
        $path = $directory.'/manifest.json';

        if (! is_file($path)) {
            throw new RuntimeException("No hay manifest.json en {$directory}: no es un volcado de WeeklySync.");
        }

        /** @var array<string, mixed> $manifest */
        $manifest = self::decode($path);

        if (($manifest['source'] ?? null) !== self::SOURCE || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('El manifiesto no es de un volcado de WeeklySync de este formato ('.self::FORMAT.').');
        }

        return new self($directory, $manifest);
    }

    public function dumpedAt(): ?string
    {
        $value = $this->manifest['dumped_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Filas de una tabla (verificadas con su sha256). Una tabla que no estaba en el origen da [].
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException si falta el fichero o no cuadra con el manifiesto
     */
    public function rows(string $table): array
    {
        if (isset($this->cache[$table])) {
            return $this->cache[$table];
        }

        $entry = $this->tableEntry($table);

        if ($entry === null || ($entry['missing'] ?? false) === true) {
            return $this->cache[$table] = [];
        }

        $path = $this->directory.'/tables/'.$table.'.json';

        if (! is_file($path)) {
            throw new RuntimeException("Falta tables/{$table}.json en el volcado.");
        }

        if (! isset($this->verified[$table]) && hash_file('sha256', $path) !== ($entry['sha256'] ?? null)) {
            throw new RuntimeException("tables/{$table}.json no cuadra con el manifiesto (sha256): el volcado está incompleto o se ha modificado.");
        }
        $this->verified[$table] = true;

        $rows = self::decode($path);

        if (! array_is_list($rows)) {
            throw new RuntimeException("tables/{$table}.json no es una lista de filas.");
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($rows, is_array(...)));

        if (count($rows) !== (int) ($entry['rows'] ?? -1)) {
            throw new RuntimeException("tables/{$table}.json tiene ".count($rows)." filas y el manifiesto dice {$entry['rows']}.");
        }

        return $this->cache[$table] = $rows;
    }

    /**
     * Filas que dice el manifiesto (null si la tabla no estaba en el origen).
     */
    public function manifestRows(string $table): ?int
    {
        $entry = $this->tableEntry($table);

        return $entry === null || ($entry['missing'] ?? false) === true ? null : (int) ($entry['rows'] ?? 0);
    }

    /**
     * Ficheros del manifiesto: "bucket/ruta" => {bucket, path, size, sha256}.
     *
     * @return array<string, array{bucket: string, path: string, size: int, sha256: string}>
     */
    public function files(): array
    {
        $files = [];

        foreach ((array) ($this->manifest['files'] ?? []) as $key => $file) {
            if (is_array($file) && is_string($file['bucket'] ?? null) && is_string($file['path'] ?? null)) {
                $files[(string) $key] = [
                    'bucket' => $file['bucket'],
                    'path' => $file['path'],
                    'size' => (int) ($file['size'] ?? 0),
                    'sha256' => (string) ($file['sha256'] ?? ''),
                ];
            }
        }

        return $files;
    }

    /**
     * Ficheros que el volcador no pudo descargar ("bucket/ruta").
     *
     * @return list<string>
     */
    public function missingFiles(): array
    {
        return array_values(array_map('strval', (array) ($this->manifest['missing_files'] ?? [])));
    }

    /**
     * Ruta local de un fichero del Storage, comprobada contra el manifiesto, o null si no está.
     *
     * @return array{path: string, size: int, sha256: string}|null
     *
     * @throws RuntimeException si el fichero está pero no cuadra con el manifiesto
     */
    public function file(string $bucket, string $path): ?array
    {
        $path = self::cleanPath($path);

        if ($path === null) {
            return null;
        }

        $entry = $this->files()[$bucket.'/'.$path] ?? null;
        $local = $this->directory.'/storage/'.$bucket.'/'.$path;

        if ($entry === null || ! is_file($local)) {
            return null;
        }

        $sha = hash_file('sha256', $local);

        if ($sha !== $entry['sha256'] || filesize($local) !== $entry['size']) {
            throw new RuntimeException("storage/{$bucket}/{$path} no cuadra con el manifiesto (sha256 o tamaño).");
        }

        return ['path' => $local, 'size' => $entry['size'], 'sha256' => $sha];
    }

    /**
     * Ruta del Storage normalizada y segura (sin barras al principio, sin «..» ni segmentos vacíos),
     * o null si no vale. La usan el volcador (al escribir) y el importador (al leer).
     */
    public static function cleanPath(?string $path): ?string
    {
        $path = trim((string) $path);
        $path = (string) preg_replace('#^/+#', '', $path);

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    /**
     * Ruta dentro del bucket de un valor guardado en WeeklySync: normalmente ya es la ruta
     * («weekly-reports/…/x.mp3»); en datos antiguos puede ser una URL firmada o pública del Storage
     * («…/storage/v1/object/sign/<bucket>/<ruta>?token=…»), de la que se saca la ruta.
     */
    public static function storagePath(mixed $value, string $bucket): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('#^https?://#i', $value) === 1) {
            $pattern = '#/storage/v1/object/(?:sign/|public/|authenticated/)?'.preg_quote($bucket, '#').'/([^?\#]+)#';

            if (preg_match($pattern, $value, $match) !== 1) {
                return null;
            }

            $value = rawurldecode($match[1]);
        } elseif (str_starts_with($value, $bucket.'/')) {
            $value = substr($value, strlen($bucket) + 1);
        }

        return self::cleanPath($value);
    }

    /**
     * Ficheros del Storage que usan las filas de una tabla: [bucket, ruta].
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<array{0: string, 1: string}>
     */
    public static function references(string $table, iterable $rows): array
    {
        $files = [];
        $add = function (string $bucket, mixed $value) use (&$files): void {
            $path = self::storagePath($value, $bucket);
            if ($path !== null) {
                $files[$bucket.'/'.$path] = [$bucket, $path];
            }
        };

        foreach ($rows as $row) {
            match ($table) {
                'week_cycles' => (function () use ($row, $add): void {
                    $add(self::AUDIO_BUCKET, $row['final_report_audio_url'] ?? null);
                    $report = $row['structured_report'] ?? null;
                    $sections = is_array($report) && is_array($report['audioSections'] ?? null) ? $report['audioSections'] : [];
                    foreach ($sections as $section) {
                        if (is_array($section)) {
                            $add(self::AUDIO_BUCKET, $section['audioPath'] ?? $section['audioUrl'] ?? null);
                        }
                    }
                })(),
                'help_settings' => $add(self::HELP_BUCKET, $row['manual_path'] ?? null),
                'help_tutorials' => $add(self::HELP_BUCKET, $row['video_path'] ?? null),
                'suggestion_post_attachments', 'suggestion_comment_attachments' => $add(self::SUGGESTIONS_BUCKET, $row['storage_path'] ?? null),
                default => null,
            };
        }

        return array_values($files);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tableEntry(string $table): ?array
    {
        $entry = $this->manifest['tables'][$table] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $path): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(basename($path)." no es JSON válido: {$e->getMessage()}");
        }

        return is_array($data) ? $data : [];
    }
}
