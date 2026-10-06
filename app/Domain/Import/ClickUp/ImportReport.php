<?php

namespace App\Domain\Import\ClickUp;

/**
 * Informe de una importación: recuentos por tipo (creados, actualizados, sin cambios y omitidos),
 * minutos importados por persona, registros descartados por motivo y avisos agrupados.
 */
final class ImportReport
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    public const string SKIPPED = 'skipped';

    /** Tipos, en el orden en que salen en el informe. */
    public const array TYPES = [
        'people' => 'Personas',
        'departments' => 'Departamentos',
        'task_types' => 'Tipos de tarea',
        'clients' => 'Clientes',
        'projects' => 'Proyectos',
        'hour_banks' => 'Bolsas',
        'tasks' => 'Tareas',
        'time_entries' => 'Entradas de horas',
        'timesheet_periods' => 'Semanas aprobadas',
    ];

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var array<string, int> nombre => minutos */
    private array $minutesByPerson = [];

    /** @var array<string, int> motivo => registros */
    private array $discarded = [];

    /** @var array<string, int> aviso => veces */
    private array $warnings = [];

    public bool $dryRun = false;

    /**
     * @param  array<string, string>  $types  tipos del informe y su nombre (los de la importación de
     *                                        ClickUp por defecto; el chat tiene los suyos)
     */
    public function __construct(private readonly array $types = self::TYPES) {}

    public float $seconds = 0.0;

    public int $peakMemoryBytes = 0;

    public function count(string $type, string $outcome, int $times = 1): void
    {
        $this->counts[$type][$outcome] = ($this->counts[$type][$outcome] ?? 0) + $times;
    }

    public function get(string $type, string $outcome): int
    {
        return $this->counts[$type][$outcome] ?? 0;
    }

    public function addMinutes(string $person, int $minutes): void
    {
        $this->minutesByPerson[$person] = ($this->minutesByPerson[$person] ?? 0) + $minutes;
    }

    public function discard(string $reason, int $times = 1): void
    {
        $this->discarded[$reason] = ($this->discarded[$reason] ?? 0) + $times;
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
    public function minutesByPerson(): array
    {
        $minutes = $this->minutesByPerson;
        arsort($minutes);

        return $minutes;
    }

    public function totalMinutes(): int
    {
        return array_sum($this->minutesByPerson);
    }

    /**
     * @return array<string, int>
     */
    public function discarded(): array
    {
        $discarded = $this->discarded;
        arsort($discarded);

        return $discarded;
    }

    /**
     * @return array<string, int>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Recuentos como filas de tabla: tipo, creados, actualizados, sin cambios y omitidos.
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->types as $type => $label) {
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
}
