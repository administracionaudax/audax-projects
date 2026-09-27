<?php

namespace App\Domain\Portal;

use App\Domain\Reports\Dimension;
use App\Enums\HourBankStatus;
use App\Models\HourBank;
use Illuminate\Support\Facades\DB;

/**
 * Cifras de una bolsa tal como las ve el cliente (SPEC §11, D-064): salen SOLO de las horas que
 * puede ver (por defecto aprobadas y bloqueadas), para que la barra, el listado y el PDF cuadren.
 * Dentro de la bolsa y exceso por separado (D-019): dentro = minutos − overage_minutes de cada
 * entrada. Internamente la bolsa puede ir más avanzada (borradores y semanas sin aprobar).
 */
final class PortalBankFigures
{
    /**
     * Cifras de varias bolsas con una sola consulta.
     *
     * @param  iterable<HourBank>  $banks
     * @return array<int, array{total_minutes: int, within_minutes: int, overage_minutes: int, remaining_minutes: int, percent: float}>
     */
    public static function many(PortalScope $scope, iterable $banks): array
    {
        $ids = [];
        $totals = [];
        foreach ($banks as $bank) {
            $ids[] = $bank->id;
            $totals[$bank->id] = (int) $bank->total_minutes;
        }

        if ($ids === []) {
            return [];
        }

        $rows = $scope->entries()
            ->whereIn('hour_bank_id', $ids)
            ->groupBy('hour_bank_id')
            ->get([
                'hour_bank_id',
                DB::raw('COALESCE(SUM(minutes), 0) as minutes_sum'),
                DB::raw('COALESCE(SUM(overage_minutes), 0) as overage_sum'),
            ])
            ->keyBy('hour_bank_id');

        $figures = [];
        foreach ($totals as $id => $total) {
            $row = $rows->get($id);
            $minutes = (int) ($row?->getAttribute('minutes_sum') ?? 0);
            $overage = (int) ($row?->getAttribute('overage_sum') ?? 0);
            $figures[$id] = self::figures($total, $minutes - $overage, $overage);
        }

        return $figures;
    }

    /**
     * @return array{total_minutes: int, within_minutes: int, overage_minutes: int, remaining_minutes: int, percent: float}
     */
    public static function one(PortalScope $scope, HourBank $bank): array
    {
        return self::many($scope, [$bank])[$bank->id];
    }

    /**
     * Consumo por mes (primer día del mes AAAA-MM-01), dentro y exceso por separado, en orden.
     *
     * @return list<array{month: string, within_minutes: int, overage_minutes: int}>
     */
    public static function byMonth(PortalScope $scope, HourBank $bank): array
    {
        $month = Dimension::Month->expression();

        $rows = $scope->bankEntries($bank)
            ->selectRaw("{$month} as month, COALESCE(SUM(minutes), 0) as minutes_sum, COALESCE(SUM(overage_minutes), 0) as overage_sum")
            ->groupByRaw($month)
            ->orderByRaw($month)
            ->toBase()
            ->get();

        $series = [];
        foreach ($rows as $row) {
            $minutes = (int) $row->minutes_sum;
            $overage = (int) $row->overage_sum;
            $series[] = ['month' => (string) $row->month, 'within_minutes' => $minutes - $overage, 'overage_minutes' => $overage];
        }

        return $series;
    }

    /**
     * Estado de la bolsa tal como lo ve el cliente (P1): las cerradas y renovadas, tal cual; una
     * abierta está agotada cuando lo que ve dentro de la bolsa llega al total (la regla de
     * HourBankLedger, con sus horas visibles), para que el estado cuadre con la barra, el listado
     * y el PDF. Por dentro puede ir más avanzada (D-064).
     *
     * @param  array{total_minutes: int, within_minutes: int, overage_minutes: int, remaining_minutes: int, percent: float}  $figures
     */
    public static function status(HourBank $bank, array $figures): HourBankStatus
    {
        if (! $bank->status->acceptsTime()) {
            return $bank->status;
        }

        return $figures['within_minutes'] >= $figures['total_minutes'] ? HourBankStatus::Exhausted : HourBankStatus::Active;
    }

    /**
     * @return array{total_minutes: int, within_minutes: int, overage_minutes: int, remaining_minutes: int, percent: float}
     */
    private static function figures(int $total, int $within, int $overage): array
    {
        return [
            'total_minutes' => $total,
            'within_minutes' => $within,
            'overage_minutes' => $overage,
            'remaining_minutes' => max($total - $within, 0),
            'percent' => $total > 0 ? round(($within + $overage) / $total, 4) : 0.0,
        ];
    }
}
