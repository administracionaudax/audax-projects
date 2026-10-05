<?php

namespace App\Domain\Import\WeeklySync;

use App\Domain\Import\ClickUp\ImportOutput;
use App\Domain\Import\ClickUp\ImportRefs;
use Carbon\CarbonImmutable;

/**
 * Estado de una ejecución de la importación, compartido por sus etapas: el volcado, las
 * correspondencias ya resueltas (WeeklySync → Audax) y el informe.
 */
final class WeeklySyncContext
{
    /** @var array<string, int|null> persona de WeeklySync => cuenta de Audax (null: fuera) */
    public array $users = [];

    /** @var array<string, int> correo (de WeeklySync o de Audax) => cuenta de Audax */
    public array $usersByEmail = [];

    /** @var array<string, int> cliente de WeeklySync => cliente de Audax */
    public array $clients = [];

    /** @var array<string, int> nombre normalizado de un cliente de WeeklySync => cliente de Audax */
    public array $clientsByName = [];

    /** @var array<string, int> semana de WeeklySync => semana de Audax */
    public array $cycles = [];

    /** @var array<string, int> «semana|persona» de Audax => envío (los enviados de WeeklySync) */
    public array $submitted = [];

    /** @var array<int, true> semanas de Audax que trae la importación (las de WeeklySync) */
    public array $importedCycles = [];

    /** @var array<string, int> tablero de WeeklySync => tablero de Audax */
    public array $boards = [];

    /** @var array<string, int> categoría de WeeklySync => categoría de Audax */
    public array $categories = [];

    /** @var array<string, int> sugerencia de WeeklySync => sugerencia de Audax */
    public array $posts = [];

    /** @var array<string, int> comentario de WeeklySync => comentario de Audax */
    public array $comments = [];

    /** @var array<string, int> versión de WeeklySync => versión de Audax */
    public array $releases = [];

    /** @var array<string, int> actualización de WeeklySync => actualización de Audax */
    public array $manualUpdates = [];

    public function __construct(
        public readonly WeeklySyncDump $dump,
        public readonly WeeklySyncMappings $mappings,
        public readonly ImportRefs $refs,
        public readonly WeeklySyncImportReport $report,
        public readonly WeeklySyncFiles $files,
        public readonly ImportOutput $output,
        public readonly bool $dryRun,
    ) {}

    /**
     * Filas de una tabla del volcado; deja en el informe cuántas se han leído.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table): array
    {
        $rows = $this->dump->rows($table);
        $this->report->read($table, count($rows), $this->dump->manifestRows($table));

        return $rows;
    }

    public function user(mixed $weeklySyncId): ?int
    {
        return is_string($weeklySyncId) ? ($this->users[strtolower($weeklySyncId)] ?? null) : null;
    }

    public function client(mixed $weeklySyncId): ?int
    {
        return is_string($weeklySyncId) ? ($this->clients[strtolower($weeklySyncId)] ?? null) : null;
    }

    public function cycle(mixed $weeklySyncId): ?int
    {
        return is_string($weeklySyncId) ? ($this->cycles[strtolower($weeklySyncId)] ?? null) : null;
    }

    public static function id(mixed $value): string
    {
        return is_scalar($value) ? strtolower(trim((string) $value)) : '';
    }

    public static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function nullableStr(mixed $value): ?string
    {
        $value = self::str($value);

        return $value === '' ? null : $value;
    }

    public static function instant(mixed $value): ?CarbonImmutable
    {
        $value = self::str($value);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function date(mixed $value): ?string
    {
        $value = self::str($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }
}
