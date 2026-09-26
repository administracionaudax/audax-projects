<?php

namespace App\Domain\Projects;

use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Filtros del listado de proyectos (SPEC §6, D-032, D-037). Los parámetros van en la URL, en
 * español, para poder compartirla: ?cliente=3&estado=active&tipo=hour_bank&responsable=5
 * &departamento=2&buscar=acme&mios=1.
 *
 * - estado vacío: todos menos los archivados; «todos»: también los archivados.
 * - responsable: el gestor principal (owner, D-032).
 * - departamento implicado (D-037): el proyecto tiene un miembro, una bolsa o una tarea con un tipo
 *   de ese departamento.
 * - buscar: nombre o código, sin mayúsculas (y sin acentos en PostgreSQL con unaccent).
 * - mios: proyectos de los que soy miembro.
 */
final class ProjectFilters
{
    public const string ALL_STATUSES = 'todos';

    /**
     * @var array<string, array{name: literal-string, code: literal-string}>
     */
    private const array EXPRESSIONS = [
        'pgsql_unaccent' => [
            'name' => "unaccent(projects.name) ILIKE unaccent(CAST(? AS text)) ESCAPE '\\'",
            'code' => "projects.code ILIKE ? ESCAPE '\\'",
        ],
        'pgsql' => [
            'name' => "projects.name ILIKE ? ESCAPE '\\'",
            'code' => "projects.code ILIKE ? ESCAPE '\\'",
        ],
        'default' => [
            'name' => "LOWER(projects.name) LIKE ? ESCAPE '\\'",
            'code' => "LOWER(projects.code) LIKE ? ESCAPE '\\'",
        ],
    ];

    private static ?bool $unaccentAvailable = null;

    /**
     * Valores saneados de la petición (los desconocidos se ignoran).
     *
     * @return array{cliente: int|null, estado: string, tipo: string|null, responsable: int|null, departamento: int|null, buscar: string, mios: bool}
     */
    public function fromRequest(Request $request): array
    {
        $status = (string) $request->query('estado', '');
        $type = (string) $request->query('tipo', '');

        return [
            'cliente' => $this->positiveInt($request->query('cliente')),
            'estado' => in_array($status, [...ProjectStatus::values(), self::ALL_STATUSES], true) ? $status : '',
            'tipo' => in_array($type, BillingType::values(), true) ? $type : null,
            'responsable' => $this->positiveInt($request->query('responsable')),
            'departamento' => $this->positiveInt($request->query('departamento')),
            'buscar' => Str::limit(trim((string) $request->query('buscar', '')), 100, ''),
            'mios' => $request->boolean('mios'),
        ];
    }

    /**
     * @param  Builder<Project>  $query
     * @param  array{cliente: int|null, estado: string, tipo: string|null, responsable: int|null, departamento: int|null, buscar: string, mios: bool}  $filters
     */
    public function apply(Builder $query, array $filters, User $user): void
    {
        if ($filters['estado'] === '') {
            $query->where('projects.status', '!=', ProjectStatus::Archived->value);
        } elseif ($filters['estado'] !== self::ALL_STATUSES) {
            $query->where('projects.status', $filters['estado']);
        }

        if ($filters['cliente'] !== null) {
            $query->where('projects.client_id', $filters['cliente']);
        }

        if ($filters['tipo'] !== null) {
            $query->where('projects.billing_type', $filters['tipo']);
        }

        if ($filters['responsable'] !== null) {
            $query->where('projects.owner_user_id', $filters['responsable']);
        }

        if ($filters['departamento'] !== null) {
            $this->involvingDepartment($query, $filters['departamento']);
        }

        if ($filters['buscar'] !== '') {
            $this->search($query, $filters['buscar']);
        }

        if ($filters['mios']) {
            $query->withMember($user);
        }
    }

    /**
     * Departamento implicado (D-037): un miembro, una bolsa o un tipo de tarea de ese departamento.
     *
     * @param  Builder<Project>  $query
     */
    public function involvingDepartment(Builder $query, int $departmentId): void
    {
        $query->where(function (Builder $where) use ($departmentId): void {
            $where->whereHas('members', fn (Builder $members) => $members->where('users.department_id', $departmentId))
                ->orWhereHas('hourBanks', fn (Builder $banks) => $banks->where('hour_banks.department_id', $departmentId))
                ->orWhereHas('tasks', fn (Builder $tasks) => $tasks->whereIn(
                    'tasks.task_type_id',
                    TaskType::query()->withTrashed()->select('id')->where('department_id', $departmentId),
                ));
        });
    }

    /**
     * @param  Builder<Project>  $query
     */
    private function search(Builder $query, string $text): void
    {
        $like = '%'.$this->escapeLike(Str::lower($text)).'%';
        $expressions = self::EXPRESSIONS[$this->mode()];

        $query->where(function (Builder $where) use ($like, $expressions): void {
            $where->whereRaw($expressions['name'], [$like])
                ->orWhereRaw($expressions['code'], [$like]);
        });
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function mode(): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 'default';
        }

        return $this->unaccentAvailable() ? 'pgsql_unaccent' : 'pgsql';
    }

    private function unaccentAvailable(): bool
    {
        if (self::$unaccentAvailable === null) {
            try {
                self::$unaccentAvailable = DB::table('pg_extension')->where('extname', 'unaccent')->exists();
            } catch (Throwable) {
                self::$unaccentAvailable = false;
            }
        }

        return self::$unaccentAvailable;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
