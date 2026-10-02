<?php

namespace App\Domain\Reports;

use App\Domain\HourBanks\HourBankCommitment;
use App\Domain\HourBanks\HourBankLedger;
use App\Enums\HourBankStatus;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bolsas en riesgo (SPEC §10.1 y D-047): abiertas (activas o agotadas), de proyectos no archivados,
 * con lo que va dentro de la bolsa en el primer umbral configurado o por encima (D-035: el mismo
 * criterio que «próximas a agotarse» y el color ámbar de la barra), o ya agotadas.
 *
 * Alcance (D-044): un admin ve todas; con el filtro de departamento (o, para un responsable, sus
 * departamentos, que se le imponen siempre), las bolsas de esos departamentos y las de los
 * proyectos donde ha imputado alguien de ellos. Con el filtro de personas, las de los proyectos
 * donde han imputado. Los filtros de cliente, proyecto y bolsa se aplican tal cual. Quien no es
 * admin ni dirige departamentos solo ve las de los proyectos que gestiona (D-035).
 * El consumo de las bolsas (en %) lo ven todos los internos (D-021): no hay datos por persona.
 * Añadido por R1 (dashboard de dirección).
 */
final class HourBanksAtRisk
{
    public function __construct(
        private readonly HourBankLedger $ledger,
        private readonly HourBankCommitment $commitment,
    ) {}

    public function threshold(): int
    {
        return $this->ledger->thresholds()[0] ?? 75;
    }

    /**
     * @return array{count: int, threshold: int, banks: list<array{id: int, name: string, status: string,
     *     project: array{id: int, code: string, name: string, color: string}, client: string|null,
     *     total_minutes: int, consumed_minutes: int, overage_minutes: int, committed_minutes: int, ratio: float}>}
     */
    public function forScope(ReportScope $scope, int $limit = 20): array
    {
        $threshold = $this->threshold();
        $query = $this->query($scope, $threshold);

        $count = (clone $query)->count();
        $banks = $count === 0 ? new Collection : (clone $query)
            ->with(['project' => fn ($project) => $project->select(['id', 'code', 'name', 'color', 'client_id'])->with('client:id,name')])
            ->orderByRaw('CASE WHEN total_minutes > 0 THEN (consumed_minutes - overage_minutes) * 1.0 / total_minutes ELSE 1 END DESC')
            ->orderByDesc('overage_minutes')
            ->orderBy('hour_banks.id')
            ->limit($limit)
            ->get(['hour_banks.id', 'hour_banks.name', 'hour_banks.status', 'hour_banks.project_id', 'hour_banks.total_minutes', 'hour_banks.consumed_minutes', 'hour_banks.overage_minutes']);

        $figures = $this->commitment->forBanks($banks->modelKeys());

        return [
            'count' => $count,
            'threshold' => $threshold,
            'banks' => array_values($banks->map(fn (HourBank $bank): array => [
                'id' => $bank->id,
                'name' => $bank->name,
                'status' => $bank->status->value,
                'project' => [
                    'id' => $bank->project->id,
                    'code' => $bank->project->code,
                    'name' => $bank->project->name,
                    'color' => $bank->project->color,
                ],
                'client' => $bank->project->client?->name,
                'total_minutes' => $bank->total_minutes,
                'consumed_minutes' => $bank->consumed_minutes,
                'overage_minutes' => $bank->overage_minutes,
                'committed_minutes' => $figures[$bank->id]['committed_minutes'] ?? 0,
                'ratio' => $bank->total_minutes > 0 ? round(($bank->consumed_minutes - $bank->overage_minutes) / $bank->total_minutes, 4) : 1.0,
            ])->all()),
        ];
    }

    /**
     * @return Builder<HourBank>
     */
    private function query(ReportScope $scope, int $threshold): Builder
    {
        $viewer = $scope->viewer;
        $f = $scope->filters;

        $query = HourBank::query()
            ->open()
            ->whereHas('project', fn (Builder $project) => $project->notArchived())
            ->where(fn (Builder $risk) => $risk
                ->where('hour_banks.status', HourBankStatus::Exhausted->value)
                ->orWhereRaw('(consumed_minutes - overage_minutes) * 100 >= ? * total_minutes', [$threshold]));

        $departmentIds = $f->departmentIds;
        if (! $viewer->isAdmin()) {
            $managed = $viewer->managedDepartmentIds();
            $departmentIds = array_values(array_intersect($departmentIds, $managed)) ?: $managed;

            if ($departmentIds === []) {
                $query->whereIn('hour_banks.project_id', $viewer->managedProjectIds());
            }
        }

        if ($departmentIds !== []) {
            $query->where(fn (Builder $scoped) => $scoped
                ->whereIn('hour_banks.department_id', $departmentIds)
                ->orWhereIn('hour_banks.project_id', TimeEntry::query()->select('project_id')
                    ->whereIn('user_id', User::query()->select('id')->whereIn('department_id', $departmentIds))));
        }

        if ($f->userIds !== []) {
            $query->whereIn('hour_banks.project_id', TimeEntry::query()->select('project_id')->whereIn('user_id', $f->userIds));
        }
        if ($f->clientIds !== []) {
            $query->whereIn('hour_banks.project_id', Project::query()->select('id')->whereIn('client_id', $f->clientIds));
        }
        if ($f->projectIds !== []) {
            $query->whereIn('hour_banks.project_id', $f->projectIds);
        }
        if ($f->bankIds !== []) {
            $query->whereIn('hour_banks.id', $f->bankIds);
        }

        return $query;
    }
}
