<?php

namespace App\Http\Controllers\HourBanks;

use App\Domain\HourBanks\HourBankCommitment;
use App\Domain\HourBanks\HourBankLedger;
use App\Enums\HourBankStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\HourBanks\HourBankCardResource;
use App\Http\Resources\Projects\Paginated;
use App\Http\Resources\Projects\ResourceData;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vista global de bolsas (/bolsas, SPEC §8 UI): las bolsas abiertas (activas y agotadas) de todos
 * los clientes, ordenadas por % de consumo, para anticipar renovaciones y facturación.
 * - Responsables y admins ven todas; un gestor, solo las de sus proyectos (D-035).
 * - Filtros en la URL: ?cliente=&departamento=&estado=&proximas=1 (estado vacío = abiertas;
 *   «todas» incluye cerradas y renovadas).
 */
class HourBankOverviewController extends Controller
{
    use AuthorizesRequests;

    public const int PER_PAGE = 25;

    public const string ALL = 'todas';

    public function __invoke(Request $request, HourBankCommitment $commitment, HourBankLedger $ledger): Response
    {
        $this->authorize('viewAny', HourBank::class);

        /** @var User $user */
        $user = $request->user();
        $filters = $this->filters($request);
        $threshold = $ledger->thresholds()[0] ?? 75;

        $visible = HourBank::query()->whereHas('project');
        $this->scopeToViewer($visible, $user);

        $query = (clone $visible)
            ->with([
                'department:id,name,color',
                'project' => fn ($project) => $project->select(['id', 'code', 'name', 'color', 'client_id'])->with('client:id,name'),
            ]);

        $this->applyFilters($query, $filters, $threshold);

        $paginator = $query
            ->orderByRaw('CASE WHEN total_minutes > 0 THEN consumed_minutes * 1.0 / total_minutes ELSE 0 END DESC')
            ->orderBy('hour_banks.id')
            ->paginate(self::PER_PAGE, pageName: 'pagina')
            ->withQueryString();

        /** @var list<HourBank> $banks */
        $banks = $paginator->items();
        $figures = $commitment->forBanks(array_map(fn (HourBank $bank): int => $bank->id, $banks));

        $items = [];
        foreach ($banks as $bank) {
            $items[] = ResourceData::of(new HourBankCardResource($bank, $figures[$bank->id] ?? null), $request);
        }

        return Inertia::render('hour-banks/index', [
            'banks' => Paginated::props($paginator, $items),
            'filters' => $filters,
            'stats' => $this->stats($visible, $threshold),
            'threshold' => $threshold,
            'scope' => $this->seesAll($user) ? 'all' : 'managed',
            'options' => [
                'clients' => Client::query()
                    ->whereIn('id', (clone $visible)->join('projects', 'projects.id', '=', 'hour_banks.project_id')->select('projects.client_id'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->name])
                    ->all(),
                'departments' => Department::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array{cliente: int|null, departamento: int|null, estado: string, proximas: bool}
     */
    private function filters(Request $request): array
    {
        $status = (string) $request->query('estado', '');

        return [
            'cliente' => $this->positiveInt($request->query('cliente')),
            'departamento' => $this->positiveInt($request->query('departamento')),
            'estado' => in_array($status, [...HourBankStatus::values(), self::ALL], true) ? $status : '',
            'proximas' => $request->boolean('proximas'),
        ];
    }

    /**
     * @param  Builder<HourBank>  $query
     * @param  array{cliente: int|null, departamento: int|null, estado: string, proximas: bool}  $filters
     */
    private function applyFilters(Builder $query, array $filters, int $threshold): void
    {
        if ($filters['estado'] === '') {
            $query->open();
        } elseif ($filters['estado'] !== self::ALL) {
            $query->where('hour_banks.status', $filters['estado']);
        }

        if ($filters['cliente'] !== null) {
            $query->whereHas('project', fn (Builder $project) => $project->where('client_id', $filters['cliente']));
        }

        if ($filters['departamento'] !== null) {
            $query->where('hour_banks.department_id', $filters['departamento']);
        }

        if ($filters['proximas']) {
            // «Próxima a agotarse» (D-035): desde el primer umbral configurado; incluye las agotadas.
            $query->whereRaw('consumed_minutes * 100 >= ? * total_minutes', [$threshold]);
        }
    }

    /**
     * @param  Builder<HourBank>  $query
     */
    private function scopeToViewer(Builder $query, User $user): void
    {
        if (! $this->seesAll($user)) {
            $query->whereIn('hour_banks.project_id', $user->managedProjectIds());
        }
    }

    private function seesAll(User $user): bool
    {
        return $user->hasAnyRole([Role::Admin->value, Role::DepartmentManager->value]);
    }

    /**
     * Resumen de las bolsas abiertas visibles: cuántas hay, cuántas agotadas y cuántas cerca.
     *
     * @param  Builder<HourBank>  $visible
     * @return array{open: int, exhausted: int, near: int}
     */
    private function stats(Builder $visible, int $threshold): array
    {
        $row = (clone $visible)
            ->open()
            ->selectRaw(
                'COUNT(*) AS open_count, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS exhausted_count, '
                .'SUM(CASE WHEN status = ? AND consumed_minutes * 100 >= ? * total_minutes THEN 1 ELSE 0 END) AS near_count',
                [HourBankStatus::Exhausted->value, HourBankStatus::Active->value, $threshold],
            )
            ->toBase()
            ->first();

        return [
            'open' => (int) ($row->open_count ?? 0),
            'exhausted' => (int) ($row->exhausted_count ?? 0),
            'near' => (int) ($row->near_count ?? 0),
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
