<?php

namespace App\Domain\Calendar;

use App\Domain\Time\Capacity;
use App\Domain\Workload\WorkloadPlanner;
use App\Enums\AbsenceType;
use App\Models\Department;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Personas del calendario del equipo (D-144): las opciones del filtro y las filas de la vista
 * «Personas» (semana y día), con su capacidad, su carga planificada y sus ausencias.
 * - Quién sale: la plantilla ve a todas las personas internas activas (D-021); un colaborador
 *   externo, solo a las de sus proyectos (D-134).
 * - Carga (minutos planificados del día frente a la capacidad, D-051 y D-052): solo de quien
 *   quien mira puede ver la carga, como en /carga: la suya, la de su equipo si es responsable y la
 *   de todos si es admin. Un colaborador no ve cargas. Solo de hoy en adelante: el reparto
 *   (WorkloadPlanner) empieza hoy.
 * - Ausencias (D-049 y D-088): un día de ausencia aprobada sale como «Ausente» (o parte del día);
 *   el tipo, solo si quien mira puede verlo (User::canSeeAbsencesOf). Un colaborador no ve las
 *   ausencias de nadie. Los festivos (de toda la empresa), para todos.
 */
final class CalendarPeople
{
    public function __construct(
        private readonly Capacity $capacity,
        private readonly WorkloadPlanner $planner,
    ) {}

    /**
     * Personas internas activas que quien mira puede ver.
     *
     * @return Builder<User>
     */
    public function visible(User $viewer): Builder
    {
        $projects = $viewer->visibleProjectIds();

        return User::query()
            ->active()
            ->internal()
            ->when($projects !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereKey($viewer->id)
                ->orWhereHas('projects', fn (Builder $member) => $member->whereIn('projects.id', $projects ?? []))));
    }

    /**
     * Opciones de los filtros de persona y departamento (sin departamentos para un colaborador).
     *
     * @return array{people: list<array{id: int, name: string, avatar: string|null, department_id: int|null}>, departments: list<array{id: int, name: string}>}
     */
    public function options(User $viewer): array
    {
        $people = $this->visible($viewer)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'avatar_path', 'department_id']);

        $departments = $viewer->isCollaborator()
            ? []
            : array_values(Department::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])
                ->all());

        return [
            'people' => array_values($people->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar_url,
                'department_id' => $user->department_id,
            ])->all()),
            'departments' => $departments,
        ];
    }

    /**
     * Filas de la vista «Personas»: las personas que dejan los filtros (y «Sin asignar» si sus
     * tareas pueden salir), con lo de cada día del rango.
     *
     * @return list<array{person: array{id: int, name: string, avatar: string|null, department: array{id: int, name: string}|null}|null, show_load: bool, days: array<string, array{capacity: int|null, load: int|null, absence: array{partial: bool, label: string|null}|null, holiday: string|null}>}>
     */
    public function rows(User $viewer, CalendarFilters $filters, CalendarRange $range): array
    {
        $people = $this->people($viewer, $filters);
        $days = $range->days();
        $today = LocalTime::todayString();
        $collaborator = $viewer->isCollaborator();
        $managed = $viewer->isAdmin() || $collaborator ? [] : $viewer->managedDepartmentIds();

        $seesLoad = fn (User $person): bool => ! $collaborator && (
            $viewer->isAdmin()
            || $person->id === $viewer->id
            || ($person->department_id !== null && in_array($person->department_id, $managed, true))
        );

        $details = $people->isEmpty() ? [] : $this->capacity->detailsForRanges(array_values($people->map(fn (User $person): array => [
            'user_id' => $person->id,
            'from' => $range->from,
            'to' => $range->to,
        ])->all()));

        $loadIds = array_values($people->filter($seesLoad)->modelKeys());
        $plan = $loadIds === [] || $range->toString() < $today
            ? null
            : $this->planner->plan($loadIds, $range->from, $range->to);

        $rows = [];
        foreach ($people->values() as $index => $person) {
            $showLoad = $seesLoad($person);
            $showType = $viewer->canSeeAbsencesOf($person);
            $personDays = [];

            foreach ($days as $date) {
                $detail = $details[$index][$date] ?? null;
                $absence = $detail['absence'] ?? null;

                $personDays[$date] = [
                    'capacity' => $showLoad && $detail !== null ? $detail['minutes'] : null,
                    'load' => $showLoad && $plan !== null && $date >= $today ? $plan->loadOn($person->id, $date) : null,
                    'absence' => $collaborator || $absence === null ? null : [
                        'partial' => $absence['partial_minutes'] !== null,
                        'label' => $showType ? AbsenceType::tryFrom($absence['type'])?->label() : null,
                    ],
                    'holiday' => $detail['holiday'] ?? null,
                ];
            }

            $rows[] = [
                'person' => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'avatar' => $person->avatar_url,
                    'department' => $person->department === null ? null : ['id' => $person->department->id, 'name' => $person->department->name],
                ],
                'show_load' => $showLoad,
                'days' => $personDays,
            ];
        }

        // Las tareas sin responsable, en su propia fila (si los filtros las dejan salir).
        if (! $filters->mine && $filters->department === null && ($filters->persons === [] || $filters->unassigned)) {
            $rows[] = [
                'person' => null,
                'show_load' => false,
                'days' => array_fill_keys($days, ['capacity' => null, 'load' => null, 'absence' => null, 'holiday' => null]),
            ];
        }

        return $rows;
    }

    /**
     * Personas de las filas, por nombre: «mías» (solo quien mira), las elegidas o las del
     * departamento; si no hay filtro de persona, todas las visibles.
     *
     * @return Collection<int, User>
     */
    private function people(User $viewer, CalendarFilters $filters): Collection
    {
        if ($filters->mine) {
            return (new Collection([$viewer]))->load('department:id,name');
        }

        if ($filters->unassigned && $filters->persons === []) {
            return new Collection;
        }

        return $this->visible($viewer)
            ->when($filters->persons !== [], fn (Builder $query) => $query->whereIn('id', $filters->persons))
            ->when($filters->department !== null, fn (Builder $query) => $query->where('department_id', $filters->department))
            ->with('department:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'avatar_path', 'department_id', 'is_active']);
    }
}
