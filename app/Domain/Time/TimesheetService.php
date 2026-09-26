<?php

namespace App\Domain\Time;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Hoja semanal (SPEC §7, D-036): filas por tarea, columnas de lunes a domingo, totales frente a
 * la capacidad del WorkSchedule vigente y la semana (TimesheetPeriod).
 *
 * Quién ve la hoja de quién (D-021): la propia; la de cualquiera si es admin; la de su equipo si
 * es responsable; y un gestor, la de los miembros de sus proyectos pero solo con las entradas de
 * esos proyectos (TimeEntry::visibleTo filtra siempre por quien mira).
 */
final class TimesheetService
{
    public function __construct(
        private readonly Capacity $capacity,
    ) {}

    /**
     * ¿Puede $viewer abrir la hoja de $owner? Con la política de la semana (TimesheetPeriodPolicy).
     * Con $period (la semana ya cargada por quien llama) no la vuelve a consultar.
     */
    public function canView(User $viewer, User $owner, Week $week, ?TimesheetPeriod $period = null): bool
    {
        $period ??= TimesheetPeriod::forUserOn($owner, $week->startString());
        $period->setRelation('user', $owner);

        $gate = Gate::forUser($viewer);

        return $gate->allows('view', $period) || $gate->allows('viewManagedProjects', $period);
    }

    /**
     * Entradas de la semana de $owner que $viewer puede ver, con tarea y proyecto.
     *
     * @return Collection<int, TimeEntry>
     */
    public function entries(User $viewer, User $owner, Week $week): Collection
    {
        return TimeEntry::query()
            ->where('time_entries.user_id', $owner->id)
            ->between($week->startString(), $week->endString())
            ->visibleTo($viewer)
            ->with([
                'task:id,title,project_id,hour_bank_id,is_milestone,is_billable,completed_at,deleted_at',
                'project:id,code,name,color,billing_type,status,client_id,deleted_at',
            ])
            ->orderBy('date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Filas de la hoja: una por tarea, ordenadas por proyecto y tarea, con las entradas de cada día.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return list<array{task: Task, project: Project, cells: list<list<TimeEntry>>, total: int}>
     */
    public function rows(Collection $entries, Week $week): array
    {
        $days = array_flip($week->days());
        $rows = [];

        foreach ($entries as $entry) {
            $rows[$entry->task_id] ??= [
                'task' => $entry->task,
                'project' => $entry->project,
                'cells' => array_fill(0, 7, []),
                'total' => 0,
            ];

            $rows[$entry->task_id]['cells'][$days[$entry->date->toDateString()]][] = $entry;
            $rows[$entry->task_id]['total'] += $entry->minutes;
        }

        usort($rows, fn (array $a, array $b): int => [$a['project']->code, mb_strtolower($a['task']->title)] <=> [$b['project']->code, mb_strtolower($b['task']->title)]);

        return array_map(fn (array $row): array => [...$row, 'cells' => array_values($row['cells'])], $rows);
    }

    /**
     * Minutos por día (Y-m-d) y de la semana.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return array{days: array<string, int>, week: int}
     */
    public function totals(Collection $entries, Week $week): array
    {
        $days = array_fill_keys($week->days(), 0);

        foreach ($entries as $entry) {
            $days[$entry->date->toDateString()] += $entry->minutes;
        }

        return ['days' => $days, 'week' => array_sum($days)];
    }

    /**
     * Capacidad por día y de la semana (WorkSchedule vigente o jornada por defecto, D-036).
     *
     * @return array{days: array<string, int>, week: int}
     */
    public function capacity(User $owner, Week $week): array
    {
        $days = $this->capacity->forRange($owner, $week->start, $week->end());

        return ['days' => $days, 'week' => array_sum($days)];
    }

    /**
     * Tareas con horas la semana anterior que aún admiten horas: «Copiar tareas de la semana
     * anterior» las añade como filas sin horas (D-036).
     *
     * @return Collection<int, Task>
     */
    public function previousWeekTasks(User $viewer, User $owner, Week $week): Collection
    {
        $previous = $week->previous();

        $taskIds = TimeEntry::query()
            ->where('time_entries.user_id', $owner->id)
            ->between($previous->startString(), $previous->endString())
            ->visibleTo($viewer)
            ->distinct()
            ->pluck('task_id');

        return $this->loggableTasks(Task::query()->whereKey($taskIds->all()))
            ->orderBy('title')
            ->get();
    }

    /**
     * Restringe a tareas que admiten horas: no borradas, no hitos y de proyectos no archivados.
     * El resto de reglas (bolsa, departamento, semana…) las aplica TimeEntryRules al guardar.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function loggableTasks(Builder $query): Builder
    {
        return $query
            ->select(['id', 'title', 'project_id', 'hour_bank_id', 'is_milestone', 'is_billable', 'completed_at'])
            ->where('is_milestone', false)
            ->whereHas('project', fn (Builder $project) => $project->notArchived())
            ->with([
                'project:id,code,name,color,billing_type,status,client_id',
                'hourBank:id,name,status',
            ]);
    }

    /**
     * Personas cuyas horas puede consultar $viewer (sin contarse a sí mismo): todas si es admin,
     * su equipo si es responsable y los miembros de los proyectos que gestiona.
     *
     * @return Collection<int, User>
     */
    public function visiblePeople(User $viewer): Collection
    {
        $query = User::query()->internal()->whereKeyNot($viewer->id)->orderBy('name');

        if (! $viewer->isAdmin()) {
            $departmentIds = $viewer->managedDepartmentIds();
            $projectIds = $viewer->managedProjectIds();

            if ($departmentIds === [] && $projectIds === []) {
                return new Collection;
            }

            $query->where(function (Builder $scope) use ($departmentIds, $projectIds): void {
                if ($departmentIds !== []) {
                    $scope->orWhereIn('department_id', $departmentIds);
                }

                if ($projectIds !== []) {
                    $scope->orWhereHas('projects', fn (Builder $projects) => $projects->whereIn('projects.id', $projectIds));
                }
            });
        }

        return $query->get(['id', 'name', 'avatar_path', 'department_id', 'is_active']);
    }
}
