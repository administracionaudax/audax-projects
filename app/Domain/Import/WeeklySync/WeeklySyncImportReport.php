<?php

namespace App\Domain\Import\WeeklySync;

/**
 * Informe de la importación de WeeklySync (D-214): recuentos por tipo (creados, actualizados, sin
 * cambios y omitidos), filas leídas de cada tabla frente al manifiesto del volcado, ficheros,
 * motivos de lo omitido y avisos.
 */
final class WeeklySyncImportReport
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    public const string SKIPPED = 'skipped';

    /** Tipos, en el orden en que salen en el informe. */
    public const array TYPES = [
        'people' => 'Personas',
        'clients' => 'Clientes',
        'cycles' => 'Semanas',
        'submissions' => 'Envíos',
        'drafts' => 'Borradores',
        'entries' => 'Apuntes por cliente',
        'general_entries' => 'Apuntes «General»',
        'draft_entries' => 'Apuntes de borradores',
        'exemptions' => 'Exenciones',
        'satisfaction' => 'Satisfacción por semana',
        'satisfaction_now' => 'Satisfacción actual',
        'audio' => 'Audios del informe',
        'tasks' => 'Tareas',
        'reminder_rules' => 'Reglas de recordatorio',
        'templates' => 'Plantillas de correo',
        'reminder_logs' => 'Registro de envíos',
        'help_settings' => 'Manual y soporte',
        'help_releases' => 'Novedades: versiones',
        'help_release_changes' => 'Novedades: cambios',
        'help_manual_updates' => 'Novedades: actualizaciones',
        'help_likes' => 'Novedades: «me gusta»',
        'help_tutorials' => 'Tutoriales',
        'help_faq_sections' => 'Secciones de preguntas',
        'help_faqs' => 'Preguntas frecuentes',
        'suggestion_boards' => 'Sugerencias: tableros',
        'suggestion_categories' => 'Sugerencias: categorías',
        'suggestion_posts' => 'Sugerencias',
        'suggestion_votes' => 'Sugerencias: votos',
        'suggestion_comments' => 'Sugerencias: comentarios',
        'suggestion_reactions' => 'Sugerencias: reacciones',
        'suggestion_events' => 'Sugerencias: cambios de estado',
        'suggestion_attachments' => 'Sugerencias: adjuntos',
        'ai_usage' => 'Uso de IA',
    ];

    /**
     * Tabla del volcado → tipo del informe con el que se cuentan sus filas (diferencias).
     */
    public const array TABLE_TYPES = [
        'users' => 'people',
        'clients' => 'clients',
        'week_cycles' => 'cycles',
        'weekly_submissions' => 'submissions',
        'client_report_entries' => 'entries',
        'weekly_submission_drafts' => 'drafts',
        'weekly_submission_draft_entries' => 'draft_entries',
        'tasks' => 'tasks',
        'email_log' => 'reminder_logs',
        'help_releases' => 'help_releases',
        'help_release_changes' => 'help_release_changes',
        'help_manual_updates' => 'help_manual_updates',
        'help_update_likes' => 'help_likes',
        'help_tutorials' => 'help_tutorials',
        'help_faq_sections' => 'help_faq_sections',
        'help_faqs' => 'help_faqs',
        'suggestion_boards' => 'suggestion_boards',
        'suggestion_categories' => 'suggestion_categories',
        'suggestion_posts' => 'suggestion_posts',
        'suggestion_votes' => 'suggestion_votes',
        'suggestion_comments' => 'suggestion_comments',
        'suggestion_comment_reactions' => 'suggestion_reactions',
        'suggestion_status_events' => 'suggestion_events',
        'ai_usage_events' => 'ai_usage',
    ];

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var array<string, int> tabla => filas leídas */
    private array $read = [];

    /** @var array<string, int|null> tabla => filas según el manifiesto */
    private array $manifest = [];

    /** @var array<string, int> motivo => veces */
    private array $skipped = [];

    /** @var array<string, int> aviso => veces */
    private array $warnings = [];

    public int $filesCopied = 0;

    public int $filesUnchanged = 0;

    public int $filesMissing = 0;

    public int $bytesCopied = 0;

    public bool $dryRun = false;

    public float $seconds = 0.0;

    public int $peakMemoryBytes = 0;

    public ?string $dumpedAt = null;

    public function count(string $type, string $outcome, int $times = 1): void
    {
        $this->counts[$type][$outcome] = ($this->counts[$type][$outcome] ?? 0) + $times;
    }

    public function get(string $type, string $outcome): int
    {
        return $this->counts[$type][$outcome] ?? 0;
    }

    /**
     * Creados, actualizados o sin cambios: lo que está en Audax tras la importación.
     */
    public function imported(string $type): int
    {
        return $this->get($type, self::CREATED) + $this->get($type, self::UPDATED) + $this->get($type, self::UNCHANGED);
    }

    public function read(string $table, int $rows, ?int $manifestRows): void
    {
        $this->read[$table] = $rows;
        $this->manifest[$table] = $manifestRows;
    }

    /**
     * Algo que no se importa, con su motivo (cuenta como omitido de su tipo).
     */
    public function skip(string $type, string $reason, int $times = 1): void
    {
        $this->count($type, self::SKIPPED, $times);
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + $times;
    }

    public function warn(string $message, int $times = 1): void
    {
        $this->warnings[$message] = ($this->warnings[$message] ?? 0) + $times;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * @return array<string, int>
     */
    public function skipped(): array
    {
        $skipped = $this->skipped;
        arsort($skipped);

        return $skipped;
    }

    /**
     * @return array<string, int>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Recuentos como filas de tabla: tipo, creados, actualizados, sin cambios y omitidos (solo los
     * tipos con algo).
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach (self::TYPES as $type => $label) {
            if (! isset($this->counts[$type])) {
                continue;
            }

            $rows[] = [
                $label,
                $this->get($type, self::CREATED),
                $this->get($type, self::UPDATED),
                $this->get($type, self::UNCHANGED),
                $this->get($type, self::SKIPPED),
            ];
        }

        return $rows;
    }

    /**
     * Cada tabla frente al manifiesto: filas del volcado, leídas, importadas (o fusionadas) y
     * omitidas, y si cuadran (volcado = leídas = importadas + omitidas).
     *
     * @return list<array{table: string, manifest: int|null, read: int, imported: int, skipped: int, ok: bool}>
     */
    public function differences(): array
    {
        $rows = [];

        foreach ($this->read as $table => $read) {
            $type = self::TABLE_TYPES[$table] ?? null;
            $imported = $type !== null ? $this->imported($type) : $read;
            $skipped = $type !== null ? $this->get($type, self::SKIPPED) : 0;
            $manifest = $this->manifest[$table] ?? null;

            $rows[] = [
                'table' => $table,
                'manifest' => $manifest,
                'read' => $read,
                'imported' => $imported,
                'skipped' => $skipped,
                'ok' => ($manifest ?? 0) === $read && ($type === null || $imported + $skipped === $read),
            ];
        }

        return $rows;
    }

    public function hasDifferences(): bool
    {
        foreach ($this->differences() as $row) {
            if (! $row['ok']) {
                return true;
            }
        }

        return false;
    }
}
