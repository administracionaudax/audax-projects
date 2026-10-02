<?php

namespace App\Domain\Portal;

use App\Domain\Reports\Dimension;
use App\Enums\HourBankStatus;
use App\Enums\TimeEntryStatus;
use App\Models\HourBank;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Cifras de una bolsa tal como las ve el cliente (SPEC §11, D-064, D-092): salen SOLO de las horas
 * que puede ver (por defecto aprobadas y bloqueadas), para que la barra, el listado, el detalle, el
 * estado, los avisos y el PDF cuadren siempre. Internamente la bolsa puede ir más avanzada
 * (borradores y semanas sin aprobar).
 *
 * Dentro de la bolsa y exceso (D-019, D-092): el exceso guardado en cada entrada (overage_minutes)
 * lo reparte HourBankLedger entre TODAS las horas de la bolsa, también las que el cliente no ve; con
 * horas ocultas de fecha anterior, horas que ve saldrían como exceso aunque le quede saldo. En el
 * portal, dentro y exceso se reparten SOLO entre las horas que ve, con la regla de HourBankLedger:
 * - las bloqueadas conservan su exceso fijo (D-019, D-053) y reservan lo que llevan dentro,
 * - el resto del total se reparte entre las demás en orden cronológico (fecha, created_at, id), y
 *   la que cruza el límite queda con la parte que no cabe como exceso.
 * En SQL, con una suma acumulada (SUM(...) OVER, en PostgreSQL y en SQLite ≥ 3.25): sin N+1.
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

        $rows = DB::query()
            ->fromSub(self::allocation($scope, $ids), 'portal_entries')
            ->selectRaw('portal_entries.hour_bank_id, SUM(portal_entries.minutes) as minutes_sum, SUM(portal_entries.overage_minutes) as overage_sum')
            ->groupBy('portal_entries.hour_bank_id')
            ->get()
            ->keyBy('hour_bank_id');

        $figures = [];
        foreach ($totals as $id => $total) {
            $row = $rows->get($id);
            $minutes = (int) ($row->minutes_sum ?? 0);
            $overage = (int) ($row->overage_sum ?? 0);
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

        $rows = DB::query()
            // Con el alias time_entries, la expresión del mes (Dimension) vale sobre el reparto.
            ->fromSub(self::allocation($scope, [$bank->id]), 'time_entries')
            ->selectRaw("{$month} as month, SUM(time_entries.minutes) as minutes_sum, SUM(time_entries.overage_minutes) as overage_sum")
            ->groupByRaw($month)
            ->orderByRaw($month)
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
     * Horas visibles de varias bolsas entre dos fechas (AAAA-MM-DD, incluidas) con su exceso tal
     * como lo ve el cliente: las «horas de este mes» del inicio del portal.
     *
     * @param  list<int>  $bankIds
     * @return array{minutes: int, overage_minutes: int}
     */
    public static function between(PortalScope $scope, array $bankIds, string $from, string $to): array
    {
        if ($bankIds === []) {
            return ['minutes' => 0, 'overage_minutes' => 0];
        }

        $row = DB::query()
            ->fromSub(self::allocation($scope, $bankIds), 'portal_entries')
            ->whereBetween('portal_entries.date', [$from, $to])
            ->selectRaw('COALESCE(SUM(portal_entries.minutes), 0) as minutes_sum, COALESCE(SUM(portal_entries.overage_minutes), 0) as overage_sum')
            ->first();

        return ['minutes' => (int) ($row->minutes_sum ?? 0), 'overage_minutes' => (int) ($row->overage_sum ?? 0)];
    }

    /**
     * Las horas visibles de la bolsa para listarlas (el detalle y el PDF): persona, tarea, fecha,
     * minutos, descripción y el exceso tal como lo ve el cliente (overage()), nunca el guardado.
     *
     * @return Builder<TimeEntry>
     */
    public static function entries(PortalScope $scope, HourBank $bank): Builder
    {
        return $scope->bankEntries($bank)
            ->joinSub(self::allocation($scope, [$bank->id]), 'portal_entries', 'portal_entries.id', '=', 'time_entries.id')
            ->select([
                'time_entries.id', 'time_entries.user_id', 'time_entries.task_id', 'time_entries.date',
                'time_entries.minutes', 'time_entries.description', 'portal_entries.overage_minutes as portal_overage_minutes',
            ]);
    }

    /**
     * El exceso de una entrada tal como lo ve el cliente (de entries()).
     */
    public static function overage(TimeEntry $entry): int
    {
        return (int) $entry->getAttribute('portal_overage_minutes');
    }

    /**
     * Reparto del portal (D-092): una fila por hora visible de las bolsas, con el exceso que ve el
     * cliente. Columnas: id, hour_bank_id, date, minutes y overage_minutes. Se usa como subconsulta
     * (fromSub o joinSub): la suma acumulada se calcula siempre sobre TODAS las horas visibles de la
     * bolsa, aunque luego se filtre por mes o se pagine.
     *
     * @param  list<int>  $bankIds
     */
    public static function allocation(PortalScope $scope, array $bankIds): QueryBuilder
    {
        $locked = "time_entries.status = '".TimeEntryStatus::Locked->value."'";
        // Exceso fijo de una bloqueada, siempre entre 0 y sus minutos (como HourBankLedger).
        $fixed = 'CASE WHEN time_entries.overage_minutes <= 0 THEN 0'
            .' WHEN time_entries.overage_minutes >= time_entries.minutes THEN time_entries.minutes'
            .' ELSE time_entries.overage_minutes END';

        $window = $scope->entries()
            ->join('hour_banks', 'hour_banks.id', '=', 'time_entries.hour_bank_id')
            ->whereIn('time_entries.hour_bank_id', $bankIds)
            ->toBase()
            ->select(['time_entries.id', 'time_entries.hour_bank_id', 'time_entries.date', 'time_entries.minutes', 'time_entries.status'])
            ->selectRaw("{$fixed} as fixed_overage")
            // Minutos de las no bloqueadas en orden cronológico, hasta esta incluida.
            ->selectRaw("SUM(CASE WHEN {$locked} THEN 0 ELSE time_entries.minutes END) OVER ("
                .'PARTITION BY time_entries.hour_bank_id ORDER BY time_entries.date, time_entries.created_at, time_entries.id '
                .'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running_minutes')
            // Lo que cabe para las no bloqueadas: el total menos lo que llevan dentro las bloqueadas.
            ->selectRaw("hour_banks.total_minutes - SUM(CASE WHEN {$locked} THEN time_entries.minutes - ({$fixed}) ELSE 0 END) OVER ("
                .'PARTITION BY time_entries.hour_bank_id) as capacity_minutes');

        // Exceso de una no bloqueada: lo acumulado que no cabe, entre 0 y sus minutos.
        $excess = 'portal_window.running_minutes - (CASE WHEN portal_window.capacity_minutes > 0 THEN portal_window.capacity_minutes ELSE 0 END)';

        return DB::query()
            ->fromSub($window, 'portal_window')
            ->select(['portal_window.id', 'portal_window.hour_bank_id', 'portal_window.date', 'portal_window.minutes'])
            ->selectRaw("CASE WHEN portal_window.status = '".TimeEntryStatus::Locked->value."' THEN portal_window.fixed_overage"
                ." WHEN {$excess} <= 0 THEN 0"
                ." WHEN {$excess} >= portal_window.minutes THEN portal_window.minutes"
                ." ELSE {$excess} END as overage_minutes");
    }

    /**
     * Estado de la bolsa tal como lo ve el cliente (D-093): las cerradas y renovadas, tal cual; una
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
