<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Metrics;
use App\Domain\Reports\Pdf\PdfFormat;

/**
 * Bloques que se repiten en los PDF de los informes (D-140): cifras clave a partir de
 * Metrics::summary, tablas de reparto (cliente, proyecto, departamento…) con su fila de totales y
 * las definiciones de las métricas que aparecen («Cómo se calculan las cifras»).
 *
 * @phpstan-import-type Table from PdfTable
 *
 * @phpstan-type Kpi array{label: string, value: string, detail: string|null}
 */
trait PdfPieces
{
    /** Métricas que solo se muestran con view-financials. */
    private const array MONEY_KPIS = ['income', 'cost', 'margin'];

    /**
     * Cifras clave del resumen en el orden pedido; las de dinero, solo con datos económicos, y las
     * que no aplican (null, p. ej. la ocupación sin capacidad) se omiten.
     *
     * @param  array<string, mixed>  $summary  Metrics::summary()
     * @param  list<string>  $keys
     * @return list<Kpi>
     */
    protected static function kpis(array $summary, array $keys, bool $financials): array
    {
        $kpis = [];
        $minutes = fn (string $key): int => (int) ($summary[$key] ?? 0);

        foreach ($keys as $key) {
            if (in_array($key, self::MONEY_KPIS, true) && ! $financials) {
                continue;
            }

            /** @var array{tasks?: int, accuracy?: float|null, deviation?: float|null} $estimation */
            $estimation = is_array($summary['estimation'] ?? null) ? $summary['estimation'] : [];

            $kpi = match ($key) {
                'logged' => [PdfFormat::minutes($minutes('logged_minutes')), null],
                'capacity' => [PdfFormat::minutes($minutes('capacity_minutes')), null],
                'billable' => [PdfFormat::minutes($minutes('billable_minutes')), null],
                'in_bank' => [PdfFormat::minutes($minutes('in_bank_minutes')), null],
                'overage' => [PdfFormat::overage($minutes('overage_minutes')), null],
                'occupancy', 'billability', 'billable_productivity' => isset($summary[$key]) && is_numeric($summary[$key])
                    ? [PdfFormat::percent((float) $summary[$key]), null] : null,
                'estimation' => ($estimation['accuracy'] ?? null) !== null
                    ? [PdfFormat::percent((float) $estimation['accuracy'], 0), trans_choice('report_pdf.kpi.tasks', (int) ($estimation['tasks'] ?? 0), ['count' => (int) ($estimation['tasks'] ?? 0)])]
                    : [self::t('report_pdf.kpi.no_data'), null],
                'income', 'cost' => [PdfFormat::money(is_string($summary[$key] ?? null) ? $summary[$key] : '0'), null],
                'margin' => [PdfFormat::money(is_string($summary['margin'] ?? null) ? $summary['margin'] : '0'),
                    is_numeric($summary['margin_pct'] ?? null) ? self::t('report_pdf.kpi.margin_pct', ['pct' => PdfFormat::percent((float) $summary['margin_pct'])]) : null],
                default => null,
            };

            if ($kpi !== null) {
                $kpis[] = ['label' => self::t('report_pdf.metrics.'.$key.'.label'), 'value' => $kpi[0], 'detail' => $kpi[1]];
            }
        }

        return $kpis;
    }

    /**
     * «Cómo se calculan las cifras»: la definición de cada métrica pedida (las de dinero, solo con
     * datos económicos).
     *
     * @param  list<string>  $keys
     * @return list<array{0: string, 1: string}>
     */
    protected static function definitions(array $keys, bool $financials): array
    {
        $definitions = [];

        foreach ($keys as $key) {
            if (in_array($key, self::MONEY_KPIS, true) && ! $financials) {
                continue;
            }

            $definitions[] = [self::t('report_pdf.metrics.'.$key.'.label'), self::t('report_pdf.metrics.'.$key.'.definition')];
        }

        return $definitions;
    }

    /**
     * Tabla de un reparto (Metrics::breakdown con margen): nombre, horas, facturables, % del total y
     * facturabilidad; con datos económicos, ingreso, coste y rentabilidad. Con «Otros» (el resto de
     * filas sumado) y la fila de totales.
     *
     * @param  list<array{name: string, logged_minutes: int, billable_minutes: int, income: string|null, cost: string|null, margin?: string|null}>  $rows
     * @param  array{count: int, logged_minutes: int, billable_minutes: int, income: string|null, cost: string|null, margin: string|null}|null  $others
     * @param  array{logged_minutes: int, billable_minutes: int, income?: string|null, cost?: string|null, margin?: string|null}|null  $total
     * @return Table
     */
    protected static function breakdownPdf(string $nameLabel, array $rows, ?array $others, ?array $total, bool $financials): array
    {
        $all = array_sum(array_column($rows, 'logged_minutes')) + ($others['logged_minutes'] ?? 0);
        $columns = [[$nameLabel], [self::t('report_pdf.columns.logged'), true], [self::t('report_pdf.columns.billable'), true],
            [self::t('report_pdf.columns.share'), true], [self::t('report_pdf.columns.billability'), true]];
        if ($financials) {
            array_push($columns, [self::t('report_pdf.columns.income'), true], [self::t('report_pdf.columns.cost'), true], [self::t('report_pdf.columns.margin'), true]);
        }

        $line = function (string $name, array $row) use ($all, $financials): array {
            $cells = [
                $name,
                PdfFormat::minutes((int) $row['logged_minutes']),
                PdfFormat::minutes((int) $row['billable_minutes']),
                PdfFormat::percent(Metrics::ratio((int) $row['logged_minutes'], $all)),
                PdfFormat::percent(Metrics::ratio((int) $row['billable_minutes'], (int) $row['logged_minutes'])),
            ];
            if ($financials) {
                array_push($cells, PdfFormat::money($row['income'] ?? null), PdfFormat::money($row['cost'] ?? null), PdfFormat::money($row['margin'] ?? null));
            }

            return $cells;
        };

        $lines = array_map(fn (array $row): array => $line($row['name'], $row), $rows);
        if ($others !== null) {
            $lines[] = $line(self::t('report_pdf.others', ['count' => (string) $others['count']]), $others);
        }

        return PdfTable::make($columns, $lines, $total === null ? null : $line(self::t('report_pdf.total'), $total), empty: self::t('report_pdf.no_hours'));
    }
}
