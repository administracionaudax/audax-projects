<?php

namespace App\Domain\Weeklies\ProjectStatus;

use App\Domain\Weeklies\Report\WeeklyProjectSnapshot;
use App\Domain\Weeklies\Report\WeeklyProjectStatus;
use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Estado de proyectos» con datos reales (F-119 a F-121, D-148 y D-194): la cartera actual que en
 * WeeklySync salía de capturas pasadas por OCR, calculada con los proyectos, las bolsas
 * (HourBankLedger, a través de hour_banks.consumed_minutes) y las horas de Audax, en minutos.
 *
 * Entran los proyectos abiertos (planificados, activos y en pausa) de los clientes activos. Por
 * proyecto, con las mismas reglas que el informe (WeeklyProjectStatus, D-188), a fecha de hoy:
 * - bolsa de horas: la bolsa en curso, con su total y su consumo,
 * - fee mensual: el presupuesto del mes, lo consumido en el mes hasta hoy y lo esperado por los días
 *   laborables que han pasado, sin fines de semana ni festivos de Audax (F-121),
 * - con presupuesto: todas sus horas frente al presupuesto,
 * - sin presupuesto: todas sus horas («Sin horas asignadas»).
 * Además, las horas de esta semana (de lunes a domingo) y el tipo de WeeklySync (ProjectKindCode).
 *
 * Cinco consultas como mucho, sea cual sea la cartera: clientes, proyectos, bolsas, horas y festivos.
 */
final class ProjectStatusBoard
{
    /** Estados que cuentan como «en curso». */
    public const array OPEN_STATUSES = [ProjectStatus::Planned, ProjectStatus::Active, ProjectStatus::OnHold];

    public function __construct(private readonly WeeklyProjectStatus $status) {}

    /**
     * La cartera agrupada por cliente (por nombre), con los proyectos en el orden de WeeklySync (grupo
     * y código) y las insignias del cliente.
     *
     * @param  list<int>|null  $clientIds  null = todos los clientes activos
     * @return list<array{client: array{id: int, name: string, icon: string|null}, badges: list<array{tag: string, count: int}>, projects: list<array<string, mixed>>}>
     */
    public function build(?CarbonImmutable $today = null, ?array $clientIds = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        $clients = Client::query()
            ->where('is_active', true)
            ->when($clientIds !== null, fn (Builder $query) => $query->whereKey($clientIds))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'icon']);

        if ($clients->isEmpty()) {
            return [];
        }

        $projects = $this->openProjects(array_values(array_map(intval(...), $clients->modelKeys())))
            ->get(['id', 'client_id', 'code', 'name', 'billing_type', 'budget_minutes', 'monthly_minutes', 'description', 'status']);

        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $monthStart = $today->startOfMonth();
        $banks = $this->status->currentBanks(array_values(array_map(intval(...), $projects->where('billing_type', BillingType::HourBank)->modelKeys())), $today);
        $minutes = $projects->isEmpty() ? [] : $this->status->minutes(array_values(array_map(intval(...), $projects->modelKeys())), $weekStart, $monthStart, $today);
        $workingDays = null;
        $byClient = [];

        foreach ($projects as $project) {
            $sums = $minutes[$project->id] ?? ['week' => 0, 'month' => 0, 'total' => 0];
            $kind = $this->status->kind($project);
            $budget = null;
            $consumed = $sums['total'];
            $expected = null;
            $name = $project->name;

            if ($kind === WeeklyProjectStatus::KIND_HOUR_BANK) {
                $bank = $banks[$project->id] ?? null;

                if ($bank !== null) {
                    $budget = $bank->total_minutes;
                    $consumed = $bank->consumed_minutes;
                    $name = $project->name.' · '.$bank->name;
                }
            } elseif ($kind === WeeklyProjectStatus::KIND_MONTHLY_FEE) {
                $budget = WeeklyProjectStatus::feeBudget($project);
                $consumed = $sums['month'];

                if ($budget !== null) {
                    $workingDays ??= $this->status->workingDays($monthStart, $today);
                    $expected = $workingDays['total'] > 0 ? (int) round($budget * $workingDays['elapsed'] / $workingDays['total']) : 0;
                }
            } elseif ($project->budget_minutes !== null) {
                $budget = $project->budget_minutes;
            }

            $snapshot = new WeeklyProjectSnapshot(
                projectId: $project->id,
                code: $project->code,
                name: $name,
                billingType: $kind,
                budgetMinutes: $budget,
                consumedMinutes: $consumed,
                expectedMinutes: $expected,
                weekMinutes: $sums['week'],
            );

            $byClient[(int) $project->client_id][] = [
                ...$snapshot->toArray(),
                'kind_code' => ProjectKindCode::for($project->code, $kind),
                'project_status' => $project->status->value,
            ];
        }

        $result = [];

        foreach ($clients as $client) {
            $rows = $byClient[$client->id] ?? [];
            usort($rows, fn (array $a, array $b): int => [ProjectKindCode::tagIndex($a['kind_code']), $a['code']] <=> [ProjectKindCode::tagIndex($b['kind_code']), $b['code']]);

            $result[] = [
                'client' => ['id' => $client->id, 'name' => $client->name, 'icon' => $client->icon],
                'badges' => ProjectKindCode::badges(array_column($rows, 'kind_code')),
                'projects' => $rows,
            ];
        }

        return $result;
    }

    /**
     * Tipo de WeeklySync de los proyectos abiertos de cada cliente (para las insignias y el filtro por
     * tipo de la lista de clientes, F-120 y F-123). Una consulta.
     *
     * @param  list<int>|null  $clientIds  null = todos
     * @return array<int, list<string>> cliente → prefijos
     */
    public function prefixesByClient(?array $clientIds = null): array
    {
        if ($clientIds === []) {
            return [];
        }

        $projects = $this->openProjects($clientIds)->get(['id', 'client_id', 'code', 'billing_type', 'description']);
        $result = [];

        foreach ($projects as $project) {
            $result[(int) $project->client_id][] = ProjectKindCode::for($project->code, $this->status->kind($project));
        }

        return $result;
    }

    /**
     * @param  list<int>|null  $clientIds
     * @return Builder<Project>
     */
    private function openProjects(?array $clientIds): Builder
    {
        return Project::query()
            ->whereNotNull('client_id')
            ->whereIn('status', array_map(fn (ProjectStatus $status): string => $status->value, self::OPEN_STATUSES))
            ->when($clientIds !== null, fn (Builder $query) => $query->whereIn('client_id', $clientIds))
            ->orderBy('code')
            ->orderBy('id');
    }
}
