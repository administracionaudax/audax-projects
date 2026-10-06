<?php

namespace App\Domain\Reports;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Qué horas y qué personas entran en un informe para quien lo mira (D-021, D-044) con unos
 * filtros (ReportFilters). Todas las métricas parten de aquí: nunca se consulta time_entries sin
 * pasar por entries().
 */
final class ReportScope
{
    private ?bool $financials = null;

    /** @var Collection<int, User>|null */
    private ?Collection $people = null;

    public function __construct(
        public readonly User $viewer,
        public readonly ReportFilters $filters,
    ) {}

    public function withFilters(ReportFilters $filters): self
    {
        return new self($this->viewer, $filters);
    }

    /**
     * El mismo alcance sin datos económicos aunque quien mira pueda verlos: Metrics no calcula
     * ingreso, coste ni margen (p. ej. «Mis indicadores» de Inicio o los días sin imputar, que
     * nunca los enseñan). No amplía nada: solo quita. withFilters() vuelve a mirar el permiso.
     * Añadido por R1.
     */
    public function withoutFinancials(): self
    {
        $scope = new self($this->viewer, $this->filters);
        $scope->financials = false;

        return $scope;
    }

    public function canSeeFinancials(): bool
    {
        return $this->financials ??= Gate::forUser($this->viewer)->allows('view-financials');
    }

    /**
     * Entradas del periodo visibles (TimeEntry::visibleTo) que cumplen los filtros.
     *
     * @return Builder<TimeEntry>
     */
    public function entries(): Builder
    {
        $f = $this->filters;
        $query = TimeEntry::query()
            ->visibleTo($this->viewer)
            ->whereBetween('time_entries.date', [$f->from->toDateString(), $f->to->toDateString()]);

        if ($f->userIds !== []) {
            $query->whereIn('time_entries.user_id', $f->userIds);
        }
        if ($f->departmentIds !== []) {
            $query->whereIn('time_entries.user_id', User::query()->select('id')->whereIn('department_id', $f->departmentIds));
        }
        if ($f->clientIds !== []) {
            $query->whereIn('time_entries.project_id', Project::query()->withTrashed()->select('id')->whereIn('client_id', $f->clientIds));
        }
        if ($f->projectIds !== []) {
            $query->whereIn('time_entries.project_id', $f->projectIds);
        }
        if ($f->bankIds !== []) {
            $query->whereIn('time_entries.hour_bank_id', $f->bankIds);
        }
        if ($f->taskTypeIds !== []) {
            $query->whereIn('time_entries.task_id', Task::query()->withTrashed()->select('id')->whereIn('task_type_id', $f->taskTypeIds));
        }
        if ($f->billable !== null) {
            $query->where('time_entries.is_billable', $f->billable);
        }
        if ($f->statuses !== []) {
            $query->whereIn('time_entries.status', $f->statuses);
        }

        return $query;
    }

    /**
     * Personas cuya capacidad cuenta en la ocupación (D-044):
     * - admin: todas las internas; responsable: su equipo y él mismo; el resto: solo él mismo,
     * - con los filtros de persona y departamento aplicados,
     * - las personas desactivadas solo si tienen horas en el periodo (su capacidad acaba en su
     *   última entrada: ver Metrics::capacity).
     *
     * @return Collection<int, User>
     */
    public function people(): Collection
    {
        if ($this->people !== null) {
            return $this->people;
        }

        $f = $this->filters;
        $query = User::query()->internal()->orderBy('name');

        if (! $this->viewer->isAdmin()) {
            $departmentIds = $this->viewer->managedDepartmentIds();
            $viewerId = $this->viewer->id;
            $query->where(fn (Builder $scope) => $scope
                ->where('id', $viewerId)
                ->when($departmentIds !== [], fn (Builder $team) => $team->orWhereIn('department_id', $departmentIds)));
        }

        if ($f->userIds !== []) {
            $query->whereIn('id', $f->userIds);
        }
        if ($f->departmentIds !== []) {
            $query->whereIn('department_id', $f->departmentIds);
        }

        $query->where(fn (Builder $active) => $active
            ->where('is_active', true)
            ->orWhereIn('id', TimeEntry::query()->select('user_id')
                ->whereBetween('date', [$f->from->toDateString(), $f->to->toDateString()])));

        return $this->people = $query->get(['id', 'name', 'department_id', 'is_active', 'created_at', 'hourly_cost', 'default_hourly_rate']);
    }
}
