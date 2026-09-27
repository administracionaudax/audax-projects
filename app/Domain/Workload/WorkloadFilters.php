<?php

namespace App\Domain\Workload;

use Carbon\CarbonImmutable;

/**
 * Filtros de la vista Carga (SPEC §9, D-052), leídos de la URL para poder compartirlos:
 *   horizonte=semana-actual|semana-que-viene|4-semanas|3-meses (por defecto, semana-que-viene)
 *   departamento[] · persona[] · cliente[] · proyecto[] · celda=persona:AAAA-MM-DD (panel abierto)
 * Los valores no válidos se ignoran (nunca error). Departamento y persona acotan las filas; cliente
 * y proyecto, las tareas que cuentan (WorkloadPlanner).
 */
final readonly class WorkloadFilters
{
    public const int MAX_IDS = 50;

    /**
     * @param  list<int>  $departmentIds
     * @param  list<int>  $userIds
     * @param  list<int>  $clientIds
     * @param  list<int>  $projectIds
     */
    public function __construct(
        public WorkloadHorizon $horizon,
        public array $departmentIds = [],
        public array $userIds = [],
        public array $clientIds = [],
        public array $projectIds = [],
        public ?int $cellUserId = null,
        public ?string $cellDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $cellUserId = null;
        $cellDate = null;
        $cell = $query['celda'] ?? null;

        if (is_string($cell) && preg_match('/^(\d{1,10}):(\d{4}-\d{2}-\d{2})$/', $cell, $match) === 1 && self::validDate($match[2])) {
            $cellUserId = (int) $match[1];
            $cellDate = $match[2];
        }

        return new self(
            horizon: WorkloadHorizon::fromQuery($query['horizonte'] ?? null),
            departmentIds: self::ids($query['departamento'] ?? null),
            userIds: self::ids($query['persona'] ?? null),
            clientIds: self::ids($query['cliente'] ?? null),
            projectIds: self::ids($query['proyecto'] ?? null),
            cellUserId: $cellUserId,
            cellDate: $cellDate,
        );
    }

    /**
     * Sin los filtros de persona y departamento (quien solo ve su fila no los tiene, D-052).
     */
    public function withoutPeopleFilters(): self
    {
        return new self($this->horizon, [], [], $this->clientIds, $this->projectIds, $this->cellUserId, $this->cellDate);
    }

    public function hasCell(): bool
    {
        return $this->cellUserId !== null && $this->cellDate !== null;
    }

    /**
     * Filtros de las tareas para WorkloadPlanner.
     *
     * @return array{project_ids?: list<int>, client_ids?: list<int>}
     */
    public function plannerFilters(): array
    {
        return array_filter([
            'project_ids' => $this->projectIds,
            'client_ids' => $this->clientIds,
        ], fn (array $ids): bool => $ids !== []);
    }

    /**
     * Parámetros de URL equivalentes, sin la celda (para el frontend y los enlaces).
     *
     * @return array<string, string|list<int>>
     */
    public function toQuery(): array
    {
        $query = ['horizonte' => $this->horizon->value];

        foreach (['departamento' => $this->departmentIds, 'persona' => $this->userIds, 'cliente' => $this->clientIds, 'proyecto' => $this->projectIds] as $key => $ids) {
            if ($ids !== []) {
                $query[$key] = $ids;
            }
        }

        return $query;
    }

    private static function validDate(string $value): bool
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->toDateString() === $value;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $values = is_array($value) ? $value : (is_string($value) && $value !== '' ? explode(',', $value) : []);
        $ids = [];

        foreach ($values as $item) {
            if ((is_int($item) || (is_string($item) && ctype_digit($item))) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return array_slice($ids, 0, self::MAX_IDS);
    }
}
