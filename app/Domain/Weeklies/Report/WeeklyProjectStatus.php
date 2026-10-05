<?php

namespace App\Domain\Weeklies\Report;

use App\Enums\BillingType;
use App\Enums\HourBankStatus;
use App\Models\Holiday;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\WeeklyCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Estado de los proyectos de cada cliente para el informe de la weekly (F-074, F-075 y D-148, con
 * los datos reales de Audax en lugar de las capturas de WeeklySync; D-188). Por proyecto no
 * archivado del cliente, en minutos:
 * - bolsa de horas: la bolsa en curso (activa o agotada, la más reciente que ha empezado): su total y
 *   su consumo, que mantiene HourBankLedger,
 * - fee mensual: un proyecto «Por horas» cuya descripción empieza por «Fee mensual» (así llegan los
 *   FE de ClickUp, D-135): el presupuesto es budget_minutes o las horas de la descripción («Fee
 *   mensual de 20 h.»), el consumo es el del mes hasta la fecha de referencia y lo esperado se reparte
 *   por los días laborables del mes, sin fines de semana ni festivos de Audax (D-148),
 * - cualquier otro con presupuesto (budget_minutes): todas sus horas frente al presupuesto,
 * - los demás solo salen si tienen horas esa semana (sin presupuesto).
 * La fecha de referencia es el viernes de la semana o hoy, si aún no ha llegado. Las horas de la
 * semana van de lunes a domingo, como los clientes propuestos de «Mi weekly» (D-157).
 *
 * Cuatro consultas para todos los clientes: proyectos, bolsas, horas y festivos.
 */
final class WeeklyProjectStatus
{
    public const string KIND_HOUR_BANK = 'hour_bank';

    public const string KIND_MONTHLY_FEE = 'monthly_fee';

    public const string KIND_FIXED_PRICE = 'fixed_price';

    public const string KIND_HOURLY = 'time_and_materials';

    /**
     * @param  list<int>  $clientIds
     * @return array<int, list<WeeklyProjectSnapshot>> cliente → proyectos (por código)
     */
    public function forCycle(WeeklyCycle $cycle, array $clientIds, ?CarbonImmutable $today = null): array
    {
        return $this->forClients(
            $clientIds,
            CarbonImmutable::parse($cycle->start_date->toDateString()),
            CarbonImmutable::parse($cycle->end_date->toDateString()),
            $today,
        );
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, list<WeeklyProjectSnapshot>>
     */
    public function forClients(array $clientIds, CarbonImmutable $weekStart, CarbonImmutable $weekEnd, ?CarbonImmutable $today = null): array
    {
        if ($clientIds === []) {
            return [];
        }

        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $reference = $weekEnd->startOfDay()->min($today)->max($weekStart->startOfDay());
        $monthStart = $reference->startOfMonth();

        $projects = Project::query()
            ->notArchived()
            ->whereIn('client_id', $clientIds)
            ->orderBy('code')
            ->get(['id', 'client_id', 'code', 'name', 'billing_type', 'budget_minutes', 'description']);

        if ($projects->isEmpty()) {
            return [];
        }

        $banks = $this->currentBanks(array_values(array_map(intval(...), $projects->where('billing_type', BillingType::HourBank)->modelKeys())), $reference);
        $minutes = $this->minutes(array_values(array_map(intval(...), $projects->modelKeys())), $weekStart, $monthStart, $reference);
        $workingDays = null;
        $result = [];

        foreach ($projects as $project) {
            $sums = $minutes[$project->id] ?? ['week' => 0, 'month' => 0, 'total' => 0];
            $kind = $this->kind($project);
            $budget = null;
            $consumed = 0;
            $expected = null;
            $name = $project->name;

            if ($kind === self::KIND_HOUR_BANK) {
                $bank = $banks[$project->id] ?? null;

                if ($bank !== null) {
                    $budget = $bank->total_minutes;
                    $consumed = $bank->consumed_minutes;
                    $name = $project->name.' · '.$bank->name;
                }
            } elseif ($kind === self::KIND_MONTHLY_FEE) {
                $budget = self::feeBudget($project);
                $consumed = $sums['month'];

                if ($budget !== null) {
                    $workingDays ??= $this->workingDays($monthStart, $reference);
                    $expected = $workingDays['total'] > 0 ? (int) round($budget * $workingDays['elapsed'] / $workingDays['total']) : 0;
                }
            } elseif ($project->budget_minutes !== null) {
                $budget = $project->budget_minutes;
                $consumed = $sums['total'];
            }

            if ($budget === null && $sums['week'] === 0) {
                continue;
            }

            $result[(int) $project->client_id][] = new WeeklyProjectSnapshot(
                projectId: $project->id,
                code: $project->code,
                name: $name,
                billingType: $kind,
                budgetMinutes: $budget,
                consumedMinutes: $budget === null ? $sums['week'] : $consumed,
                expectedMinutes: $expected,
                weekMinutes: $sums['week'],
            );
        }

        return $result;
    }

    /** Tipo de la vista del proyecto (ver la cabecera). */
    public function kind(Project $project): string
    {
        return match (true) {
            $project->billing_type === BillingType::HourBank => self::KIND_HOUR_BANK,
            $project->billing_type === BillingType::TimeAndMaterials && self::isMonthlyFee($project) => self::KIND_MONTHLY_FEE,
            $project->billing_type === BillingType::FixedPrice => self::KIND_FIXED_PRICE,
            default => self::KIND_HOURLY,
        };
    }

    public static function isMonthlyFee(Project $project): bool
    {
        return preg_match('/^\s*fee\s+mensual/iu', (string) $project->description) === 1;
    }

    /** Minutos al mes del fee: budget_minutes o las horas de «Fee mensual de 20 h.» (D-135). */
    public static function feeBudget(Project $project): ?int
    {
        if ($project->budget_minutes !== null) {
            return $project->budget_minutes;
        }

        if (preg_match('/fee\s+mensual\s+de\s+(\d+(?:[.,]\d+)?)\s*h/iu', (string) $project->description, $match) === 1) {
            $hours = (float) str_replace(',', '.', $match[1]);

            return $hours > 0 ? (int) round($hours * 60) : null;
        }

        return null;
    }

    /**
     * Días laborables del mes de $reference (lunes a viernes, sin festivos de Audax): los del mes
     * entero y los que han pasado hasta $reference incluido.
     *
     * @return array{total: int, elapsed: int}
     */
    public function workingDays(CarbonImmutable $monthStart, CarbonImmutable $reference): array
    {
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $holidays = Holiday::query()
            ->where('date', '>=', $monthStart->toDateString())
            ->where('date', '<', $monthEnd->addDay()->toDateString())
            ->pluck('date')
            ->map(fn (mixed $date): string => substr($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date, 0, 10))
            ->flip()
            ->all();

        return self::countWorkingDays($monthStart, $monthEnd, $reference, $holidays);
    }

    /**
     * La regla pura de workingDays() (sin base de datos).
     *
     * @param  array<string, mixed>  $holidays  fecha "Y-m-d" → lo que sea
     * @return array{total: int, elapsed: int}
     */
    public static function countWorkingDays(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $reference, array $holidays): array
    {
        $total = 0;
        $elapsed = 0;

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if ($day->isWeekend() || isset($holidays[$day->toDateString()])) {
                continue;
            }

            $total++;

            if ($day->lessThanOrEqualTo($reference)) {
                $elapsed++;
            }
        }

        return ['total' => $total, 'elapsed' => $elapsed];
    }

    /**
     * La bolsa en curso de cada proyecto: activa o agotada, la más reciente que ya ha empezado (o la
     * primera, si ninguna ha empezado aún).
     *
     * @param  list<int>  $projectIds
     * @return array<int, HourBank>
     */
    private function currentBanks(array $projectIds, CarbonImmutable $reference): array
    {
        if ($projectIds === []) {
            return [];
        }

        $banks = HourBank::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('status', [HourBankStatus::Active->value, HourBankStatus::Exhausted->value])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'name', 'total_minutes', 'consumed_minutes', 'overage_minutes', 'start_date', 'status']);

        $current = [];

        foreach ($banks as $bank) {
            $started = $bank->start_date->toDateString() <= $reference->toDateString();

            if (! isset($current[$bank->project_id]) || $started) {
                $current[$bank->project_id] = $bank;
            }
        }

        return $current;
    }

    /**
     * Minutos por proyecto: de la semana (lunes a domingo), del mes hasta la referencia y de siempre.
     * Las fechas se comparan con un límite superior exclusivo (vale para date y datetime).
     *
     * @param  list<int>  $projectIds
     * @return array<int, array{week: int, month: int, total: int}>
     */
    private function minutes(array $projectIds, CarbonImmutable $weekStart, CarbonImmutable $monthStart, CarbonImmutable $reference): array
    {
        $weekFrom = $weekStart->toDateString();
        $weekTo = $weekStart->addDays(7)->toDateString();
        $monthFrom = $monthStart->toDateString();
        $monthTo = $reference->addDay()->toDateString();

        return DB::table('time_entries')
            ->whereIn('project_id', $projectIds)
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(CASE WHEN date >= ? AND date < ? THEN minutes ELSE 0 END) as week_minutes', [$weekFrom, $weekTo])
            ->selectRaw('SUM(CASE WHEN date >= ? AND date < ? THEN minutes ELSE 0 END) as month_minutes', [$monthFrom, $monthTo])
            ->selectRaw('SUM(minutes) as total_minutes')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->project_id => [
                'week' => (int) $row->week_minutes,
                'month' => (int) $row->month_minutes,
                'total' => (int) $row->total_minutes,
            ]])
            ->all();
    }
}
