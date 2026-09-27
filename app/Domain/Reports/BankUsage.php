<?php

namespace App\Domain\Reports;

/**
 * Horas imputadas en bolsas, por proyecto (R2): lo que va DENTRO de una bolsa de verdad (entradas
 * con bolsa, sin su exceso) y el exceso. Complementa Metrics, cuyo in_bank_minutes es «todo lo que
 * no es exceso» (también las horas de proyectos sin bolsa): en el informe de un cliente con
 * proyectos por horas y de bolsa, «dentro de bolsa» solo debe contar las de las bolsas, como la
 * exportación para facturar (BillingReport).
 *
 * Sale de ReportScope::entries() (D-044) con una consulta.
 */
final class BankUsage
{
    /**
     * Solo los proyectos con horas en alguna bolsa en el alcance.
     *
     * @return array<int, array{in_bank_minutes: int, overage_minutes: int}> Por id de proyecto.
     */
    public function byProject(ReportScope $scope): array
    {
        $rows = (clone $scope->entries())->toBase()
            ->whereNotNull('time_entries.hour_bank_id')
            ->selectRaw('time_entries.project_id as project_id, SUM(time_entries.minutes) as minutes, SUM(time_entries.overage_minutes) as overage')
            ->groupBy('time_entries.project_id')
            ->get();

        $usage = [];
        foreach ($rows as $row) {
            $usage[(int) $row->project_id] = [
                'in_bank_minutes' => (int) $row->minutes - (int) $row->overage,
                'overage_minutes' => (int) $row->overage,
            ];
        }

        return $usage;
    }
}
