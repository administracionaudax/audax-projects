<?php

namespace App\Domain\Calendar;

use App\Enums\TaskPriority;
use App\Support\LocalTime;
use App\Support\QueryParams;
use Illuminate\Http\Request;

/**
 * Vista, fecha y filtros del calendario del equipo (D-144), en la URL y en español:
 * ?vista=mes|semana|dia&personas=1&fecha=2026-10-05&persona=3,7&departamento=2&proyecto=&cliente=
 * &hechas=1&prioridad=high&tipo=&hitos=1&sin_asignar=1&mias=1&q=
 *
 * - vista: semana por defecto; «personas» (filas por persona) solo en la semana y el día,
 * - fecha: el día de referencia (por defecto, hoy en Madrid); el mes y la semana son los suyos,
 * - responsables: «mías» manda sobre todo; si no, las de las personas elegidas y, con
 *   «sin asignar», también las que no tienen responsable (solo esas si no se elige a nadie),
 * - departamento: las tareas de las personas de ese departamento (y solo sus filas).
 * Contrato con el frontend: resources/js/types/calendar.ts (CalendarFilters).
 */
final readonly class CalendarFilters
{
    public const string MONTH = 'month';

    public const string WEEK = 'week';

    public const string DAY = 'day';

    /** ?vista= → vista. */
    public const array VIEWS = ['mes' => self::MONTH, 'semana' => self::WEEK, 'dia' => self::DAY];

    /**
     * @param  self::MONTH|self::WEEK|self::DAY  $view
     * @param  list<int>  $persons
     * @param  list<int>  $projects
     * @param  list<int>  $clients
     * @param  list<int>  $types
     */
    public function __construct(
        public string $view,
        public bool $people,
        public string $date,
        public array $persons = [],
        public ?int $department = null,
        public array $projects = [],
        public array $clients = [],
        public bool $done = false,
        public ?string $priority = null,
        public array $types = [],
        public bool $milestones = false,
        public bool $unassigned = false,
        public bool $mine = false,
        public ?string $q = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        /** @var self::MONTH|self::WEEK|self::DAY $view */
        $view = QueryParams::choice($request->query('vista'), self::VIEWS) ?? self::WEEK;
        $priority = $request->query('prioridad');

        return new self(
            view: $view,
            people: $view !== self::MONTH && QueryParams::flag($request->query('personas')),
            date: QueryParams::date($request->query('fecha')) ?? LocalTime::todayString(),
            persons: QueryParams::ids($request->query('persona')),
            department: QueryParams::id($request->query('departamento')),
            projects: QueryParams::ids($request->query('proyecto')),
            clients: QueryParams::ids($request->query('cliente')),
            done: QueryParams::flag($request->query('hechas')),
            priority: TaskPriority::tryFrom(is_string($priority) ? $priority : '')?->value,
            types: QueryParams::ids($request->query('tipo')),
            milestones: QueryParams::flag($request->query('hitos')),
            unassigned: QueryParams::flag($request->query('sin_asignar')),
            mine: QueryParams::flag($request->query('mias')),
            q: QueryParams::text($request->query('q')),
        );
    }

    /**
     * El mismo filtro sin lo que no le corresponde a quien mira (un colaborador externo no filtra
     * por departamento: no los ve, D-134).
     */
    public function forViewer(bool $collaborator): self
    {
        if (! $collaborator || $this->department === null) {
            return $this;
        }

        return new self(
            $this->view, $this->people, $this->date, $this->persons, null, $this->projects, $this->clients,
            $this->done, $this->priority, $this->types, $this->milestones, $this->unassigned, $this->mine, $this->q,
        );
    }

    /**
     * @return array{view: string, people: bool, date: string, persons: list<int>, department: int|null, projects: list<int>, clients: list<int>, done: bool, priority: string|null, types: list<int>, milestones: bool, unassigned: bool, mine: bool, q: string|null}
     */
    public function toArray(): array
    {
        return [
            'view' => $this->view,
            'people' => $this->people,
            'date' => $this->date,
            'persons' => $this->persons,
            'department' => $this->department,
            'projects' => $this->projects,
            'clients' => $this->clients,
            'done' => $this->done,
            'priority' => $this->priority,
            'types' => $this->types,
            'milestones' => $this->milestones,
            'unassigned' => $this->unassigned,
            'mine' => $this->mine,
            'q' => $this->q,
        ];
    }
}
