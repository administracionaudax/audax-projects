<?php

namespace App\Domain\Workload;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Qué carga ve y reparte cada persona en la vista Carga (D-052, D-021, D-024):
 * - admin: todas las personas internas activas y todas las tareas «Sin asignar»,
 * - responsable (departamentos del pivote department_managers): las de sus departamentos y él
 *   mismo, y las «Sin asignar» de sus departamentos; reasigna la carga de su equipo,
 * - el resto: solo su fila (sin filtros de persona ni bandeja «Sin asignar»),
 * - un gestor reasigna además las tareas de SUS proyectos (entre los miembros del proyecto), sin
 *   ver la carga de personas de otros departamentos.
 * Es el mismo alcance por persona que las horas (User::canSeeHoursOf). Las reglas de edición de
 * cada tarea siguen siendo las de Tareas (TaskPolicy::update y TaskWriter): esto solo acota.
 */
final class WorkloadScope
{
    /** @var Collection<int, User>|null */
    private ?Collection $people = null;

    /** @var array<int, true>|null */
    private ?array $ids = null;

    public function __construct(public readonly User $viewer) {}

    public function isAdmin(): bool
    {
        return $this->viewer->isAdmin();
    }

    /**
     * Departamentos que dirige (D-024). Un admin los ve todos sin necesidad de dirigirlos.
     *
     * @return list<int>
     */
    public function managedDepartmentIds(): array
    {
        return $this->viewer->managedDepartmentIds();
    }

    /**
     * ¿Ve a más personas que a sí misma? Admin o responsable de algún departamento.
     */
    public function seesTeam(): bool
    {
        return $this->isAdmin() || $this->managedDepartmentIds() !== [];
    }

    /**
     * Personas visibles, internas y activas, por nombre (sin los filtros de la URL).
     *
     * @return Collection<int, User>
     */
    public function people(): Collection
    {
        if ($this->people !== null) {
            return $this->people;
        }

        $managed = $this->isAdmin() ? [] : $this->managedDepartmentIds();

        return $this->people = User::query()
            ->active()
            ->internal()
            ->when(! $this->isAdmin(), fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereKey($this->viewer->id)
                ->when($managed !== [], fn (Builder $team) => $team->orWhereIn('department_id', $managed))))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'department_id']);
    }

    /**
     * @return list<int>
     */
    public function peopleIds(): array
    {
        return array_keys($this->idIndex());
    }

    public function includes(?int $userId): bool
    {
        return $userId !== null && isset($this->idIndex()[$userId]);
    }

    /**
     * ¿Ve la bandeja «Sin asignar»? Quien reparte trabajo: admin y responsables.
     */
    public function seesUnassigned(): bool
    {
        return $this->seesTeam();
    }

    /**
     * ¿Ve las tareas «Sin asignar» de ese departamento (null = sin departamento)?
     */
    public function seesUnassignedOf(?int $departmentId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $departmentId !== null && in_array($departmentId, $this->managedDepartmentIds(), true);
    }

    /**
     * Departamento de una tarea (D-051): el de la bolsa o, si no, el del tipo. Necesita `hourBank`
     * y `type` cargados (como en WorkloadPlanner::openTasks()).
     */
    public static function departmentOf(Task $task): ?int
    {
        return $task->hourBank->department_id ?? $task->type->department_id ?? null;
    }

    /**
     * ¿La tarea está en su vista de carga? Asignada a alguien de su alcance, sin asignar en uno de
     * sus departamentos, o de un proyecto que gestiona.
     */
    public function reaches(Task $task): bool
    {
        if ($task->assignee_user_id !== null) {
            return $this->includes($task->assignee_user_id) || $this->viewer->isManagerOf($task->project_id);
        }

        return ($this->seesUnassigned() && $this->seesUnassignedOf(self::departmentOf($task)))
            || $this->viewer->isManagerOf($task->project_id);
    }

    /**
     * ¿Puede cambiar el responsable desde la vista Carga? El admin; un responsable, las tareas de su
     * equipo (y las suyas) y las «Sin asignar» de sus departamentos; un gestor, las de sus proyectos.
     * El resto cambia fechas y estimación de lo suyo, pero no reparte (D-052).
     */
    public function canReassign(Task $task): bool
    {
        if ($this->isAdmin() || $this->viewer->isManagerOf($task->project_id)) {
            return true;
        }

        if ($this->managedDepartmentIds() === []) {
            return false;
        }

        return $task->assignee_user_id === null
            ? $this->seesUnassignedOf(self::departmentOf($task))
            : $this->includes($task->assignee_user_id);
    }

    /**
     * A quién puede asignar la tarea: las personas de su alcance y, si gestiona el proyecto, sus
     * miembros activos. Vacío si no puede reasignarla.
     *
     * @param  list<int>  $projectMemberIds  miembros internos activos del proyecto de la tarea
     * @return list<int>
     */
    public function assigneeOptions(Task $task, array $projectMemberIds): array
    {
        if (! $this->canReassign($task)) {
            return [];
        }

        $ids = $this->peopleIds();

        if ($this->viewer->isManagerOf($task->project_id)) {
            array_push($ids, ...$projectMemberIds);
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, true>
     */
    private function idIndex(): array
    {
        return $this->ids ??= array_fill_keys(array_map(fn (User $person): int => $person->id, $this->people()->all()), true);
    }
}
