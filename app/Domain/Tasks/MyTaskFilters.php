<?php

namespace App\Domain\Tasks;

use App\Enums\TaskPriority;
use App\Support\QueryParams;
use Illuminate\Http\Request;

/**
 * Filtros y orden de Mis tareas (D-143), en la URL y en español:
 * ?q=&proyecto=3,7&cliente=2&estado=1,4&hechas=1&prioridad=high&tipo=5&vence=rango&desde=&hasta=&orden=
 *
 * - orden: imputadas (por defecto), vencimiento, prioridad, proyecto, creacion o actualizacion,
 * - vence: vencidas, hoy, semana (de hoy al domingo), sin_fecha o rango (desde/hasta, por la
 *   fecha de entrega; cualquiera de las dos puede faltar),
 * - hechas=1 incluye las completadas (también si se elige un estado de la categoría «hecha»).
 * Contrato con el frontend: resources/js/types/my-tasks.ts (MyTaskFilters).
 */
final readonly class MyTaskFilters
{
    public const string SORT_LOGGED = 'logged';

    public const string SORT_DUE = 'due';

    public const string SORT_PRIORITY = 'priority';

    public const string SORT_PROJECT = 'project';

    public const string SORT_CREATED = 'created';

    public const string SORT_UPDATED = 'updated';

    /** ?orden= → orden. */
    public const array SORTS = [
        'imputadas' => self::SORT_LOGGED,
        'vencimiento' => self::SORT_DUE,
        'prioridad' => self::SORT_PRIORITY,
        'proyecto' => self::SORT_PROJECT,
        'creacion' => self::SORT_CREATED,
        'actualizacion' => self::SORT_UPDATED,
    ];

    /** ?vence= → filtro de vencimiento. */
    public const array DUE = [
        'vencidas' => 'overdue',
        'hoy' => 'today',
        'semana' => 'week',
        'sin_fecha' => 'none',
        'rango' => 'range',
    ];

    /**
     * @param  list<int>  $projects
     * @param  list<int>  $clients
     * @param  list<int>  $statuses
     * @param  list<int>  $types
     * @param  'overdue'|'today'|'week'|'none'|'range'|null  $due
     */
    public function __construct(
        public ?string $q = null,
        public array $projects = [],
        public array $clients = [],
        public array $statuses = [],
        public bool $done = false,
        public ?string $priority = null,
        public array $types = [],
        public ?string $due = null,
        public ?string $from = null,
        public ?string $to = null,
        public string $sort = self::SORT_LOGGED,
    ) {}

    public static function fromRequest(Request $request): self
    {
        /** @var 'overdue'|'today'|'week'|'none'|'range'|null $due */
        $due = QueryParams::choice($request->query('vence'), self::DUE);
        $from = $due === 'range' ? QueryParams::date($request->query('desde')) : null;
        $to = $due === 'range' ? QueryParams::date($request->query('hasta')) : null;

        if ($from !== null && $to !== null && $to < $from) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            q: QueryParams::text($request->query('q')),
            projects: QueryParams::ids($request->query('proyecto')),
            clients: QueryParams::ids($request->query('cliente')),
            statuses: QueryParams::ids($request->query('estado')),
            done: QueryParams::flag($request->query('hechas')),
            priority: TaskPriority::tryFrom(is_string($request->query('prioridad')) ? $request->query('prioridad') : '')?->value,
            types: QueryParams::ids($request->query('tipo')),
            due: $due,
            from: $from,
            to: $to,
            sort: QueryParams::choice($request->query('orden'), self::SORTS) ?? self::SORT_LOGGED,
        );
    }

    /**
     * ¿Hay algún filtro (sin contar el orden)?
     */
    public function filtered(): bool
    {
        return $this->q !== null || $this->projects !== [] || $this->clients !== [] || $this->statuses !== []
            || $this->done || $this->priority !== null || $this->types !== [] || $this->due !== null;
    }

    /**
     * @return array{q: string|null, projects: list<int>, clients: list<int>, statuses: list<int>, done: bool, priority: string|null, types: list<int>, due: string|null, from: string|null, to: string|null, sort: string}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->q,
            'projects' => $this->projects,
            'clients' => $this->clients,
            'statuses' => $this->statuses,
            'done' => $this->done,
            'priority' => $this->priority,
            'types' => $this->types,
            'due' => $this->due,
            'from' => $this->from,
            'to' => $this->to,
            'sort' => $this->sort,
        ];
    }
}
