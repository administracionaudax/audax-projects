<?php

namespace App\Domain\Gantt;

use App\Domain\Projects\ProjectFilters;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filtros del Gantt multiproyecto (SPEC §6.1, D-060), en la URL y en español:
 * ?cliente=3&departamento=2&responsable=5&estado=active.
 *
 * - estado: por defecto, los proyectos activos; «sin-archivar», todos menos los archivados;
 *   «todos», también los archivados.
 * - departamento implicado: la misma definición que el listado de proyectos (D-037,
 *   ProjectFilters::involvingDepartment).
 * - responsable: el gestor principal del proyecto (D-032).
 */
final class GanttFilters
{
    public const string DEFAULT_STATUS = 'active';

    public const string NOT_ARCHIVED = 'sin-archivar';

    public const string ALL = 'todos';

    public function __construct(private readonly ProjectFilters $projectFilters) {}

    /**
     * @return array{cliente: int|null, departamento: int|null, responsable: int|null, estado: string}
     */
    public function fromRequest(Request $request): array
    {
        $status = $request->query('estado');

        return [
            'cliente' => $this->positiveInt($request->query('cliente')),
            'departamento' => $this->positiveInt($request->query('departamento')),
            'responsable' => $this->positiveInt($request->query('responsable')),
            'estado' => is_string($status) && in_array($status, [...ProjectStatus::values(), self::NOT_ARCHIVED, self::ALL], true)
                ? $status
                : self::DEFAULT_STATUS,
        ];
    }

    /**
     * @param  Builder<Project>  $query
     * @param  array{cliente: int|null, departamento: int|null, responsable: int|null, estado: string}  $filters
     */
    public function apply(Builder $query, array $filters): void
    {
        if ($filters['estado'] === self::NOT_ARCHIVED) {
            $query->where('projects.status', '!=', ProjectStatus::Archived->value);
        } elseif ($filters['estado'] !== self::ALL) {
            $query->where('projects.status', $filters['estado']);
        }

        if ($filters['cliente'] !== null) {
            $query->where('projects.client_id', $filters['cliente']);
        }

        if ($filters['responsable'] !== null) {
            $query->where('projects.owner_user_id', $filters['responsable']);
        }

        if ($filters['departamento'] !== null) {
            $this->projectFilters->involvingDepartment($query, $filters['departamento']);
        }
    }

    /**
     * Opciones de los selectores: clientes, responsables (gestores principales) y departamentos.
     *
     * @return array{clients: list<array{id: int, name: string}>, owners: list<array{id: int, name: string}>, departments: list<array{id: int, name: string}>}
     */
    public function options(): array
    {
        return [
            'clients' => array_values(Client::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])->all()),
            'owners' => array_values(User::query()
                ->whereIn('id', Project::query()->select('owner_user_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $owner): array => ['id' => $owner->id, 'name' => $owner->name])->all()),
            'departments' => array_values(Department::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->all()),
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
