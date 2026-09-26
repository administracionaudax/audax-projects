<?php

namespace App\Domain\Reports;

/**
 * Informe de horas detallado (SPEC §10.6): tabla dinámica por dos dimensiones cualesquiera con
 * subtotales. Medidas de horas (minutos): imputadas, facturables, dentro de bolsa y exceso.
 */
final class PivotReport
{
    public const int MAX_ROWS = 200;

    public const int MAX_COLUMNS = 60;

    public const array MEASURES = ['logged', 'billable', 'in_bank', 'overage'];

    public function __construct(private readonly Metrics $metrics) {}

    /**
     * @return array{rows: list<array{key: string|null, name: string}>, columns: list<array{key: string|null, name: string}>,
     *     cells: array<string, array<string, int>>, row_totals: array<string, int>, column_totals: array<string, int>,
     *     total: int, truncated: bool}
     */
    public function run(ReportScope $scope, Dimension $rowDimension, Dimension $columnDimension, string $measure = 'logged'): array
    {
        if (! in_array($measure, self::MEASURES, true)) {
            $measure = 'logged';
        }

        $query = clone $scope->entries();
        $rowDimension->join($query);
        $columnDimension->join($query);
        $rowExpression = $rowDimension->expression();
        $columnExpression = $columnDimension->expression();

        $value = match ($measure) {
            'billable' => 'SUM(CASE WHEN time_entries.is_billable THEN time_entries.minutes ELSE 0 END)',
            'in_bank' => 'SUM(time_entries.minutes - time_entries.overage_minutes)',
            'overage' => 'SUM(time_entries.overage_minutes)',
            default => 'SUM(time_entries.minutes)',
        };

        $rows = $query->toBase()
            ->selectRaw($rowExpression.' as row_key, '.$columnExpression.' as column_key, '.$value.' as value')
            ->groupByRaw($rowExpression.', '.$columnExpression)
            ->get();

        $cells = [];
        $rowTotals = [];
        $columnTotals = [];
        foreach ($rows as $row) {
            $r = self::key($row->row_key);
            $c = self::key($row->column_key);
            $v = (int) $row->value;
            $cells[$r][$c] = $v;
            $rowTotals[$r] = ($rowTotals[$r] ?? 0) + $v;
            $columnTotals[$c] = ($columnTotals[$c] ?? 0) + $v;
        }

        arsort($rowTotals);
        $truncated = count($rowTotals) > self::MAX_ROWS || count($columnTotals) > self::MAX_COLUMNS;
        $rowKeys = array_slice(array_keys($rowTotals), 0, self::MAX_ROWS);
        $columnKeys = array_keys($columnTotals);
        $columnDimension->isTime() ? sort($columnKeys) : usort($columnKeys, fn ($a, $b) => $columnTotals[$b] <=> $columnTotals[$a]);
        $columnKeys = array_slice($columnKeys, 0, self::MAX_COLUMNS);

        return [
            'rows' => $this->headers($rowDimension, $rowKeys),
            'columns' => $this->headers($columnDimension, $columnKeys),
            'cells' => $cells,
            'row_totals' => $rowTotals,
            'column_totals' => $columnTotals,
            'total' => array_sum($rowTotals),
            'truncated' => $truncated,
        ];
    }

    private static function key(mixed $value): string
    {
        return $value === null ? '' : substr((string) $value, 0, 64);
    }

    /**
     * @param  list<string|int>  $keys
     * @return list<array{key: string|null, name: string}>
     */
    private function headers(Dimension $dimension, array $keys): array
    {
        $labels = $dimension->isTime() ? [] : $this->metrics->labels($dimension, array_values(array_filter($keys, fn ($key) => $key !== '')));

        return array_map(fn ($key): array => [
            'key' => $key === '' ? null : (string) $key,
            'name' => $key === '' ? Metrics::emptyLabel($dimension) : ($labels[(string) $key]['name'] ?? (string) $key),
        ], $keys);
    }
}
