<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Money;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\Project\ProjectReportSections;
use App\Domain\Reports\ReportScope;
use App\Enums\BillingType;
use App\Enums\HourBankStatus;
use App\Models\Client;
use App\Models\Project;
use Generator;

/**
 * Secciones del informe interno y completo de un proyecto (D-240), en PDF (PdfTable) y en Excel
 * (filas de ExportSheet): resumen del proyecto, matriz tarea × persona, meses, bolsas, entradas y
 * costes y margen. Las cifras salen de ProjectDocument::data() y de ProjectReportSections; aquí
 * solo se formatean. Los importes, solo con view-financials.
 *
 * @phpstan-import-type Matrix from ProjectReportSections
 * @phpstan-import-type MonthRow from ProjectReportSections
 * @phpstan-import-type BankRow from ProjectReportSections
 * @phpstan-import-type EntryRow from ProjectReportSections
 * @phpstan-import-type Table from PdfTable
 */
trait ProjectFullPieces
{
    /** Personas de la matriz en el PDF (las de más horas; el resto, en «Otros»). */
    public const int PDF_MATRIX_PEOPLE = 8;

    /** Tareas de la matriz en el PDF (las de más horas; la matriz entera, en Excel). */
    public const int PDF_MATRIX_TASKS = 150;

    /** Entradas del listado en el PDF (el listado entero, en Excel). */
    public const int PDF_ENTRIES = 1500;

    /**
     * Resumen del proyecto: datos, horas del periodo y de toda la vida, estimado frente a real,
     * consumo del presupuesto y, con datos económicos, precio, tarifa, ingreso, coste y margen.
     * Cada fila con el texto del PDF y el valor de la celda de Excel (horas en decimal).
     *
     * @param  array<string, mixed>  $summary  Metrics::summary()
     * @param  array<string, int>  $totals  EstimateComparison::forProject()['totals']
     * @param  list<BankRow>  $banks
     * @return list<array{0: string, 1: string, 2: string|int|float|null}>
     */
    private static function overview(Project $project, ?Client $client, ReportScope $scope, array $summary, array $totals, array $banks, bool $financials): array
    {
        $hours = fn (string $label, int $minutes): array => [$label, PdfFormat::minutes($minutes), TableExporter::hours($minutes)];
        $percent = fn (string $label, ?float $ratio): array => [$label, PdfFormat::percent($ratio), $ratio === null ? null : round($ratio * 100, 1)];
        $o = fn (string $key): string => self::t('report_pdf.project.overview.'.$key);

        $lifetime = (int) $totals['actual_minutes'];
        $estimated = (int) $totals['estimated_minutes'];
        $rows = [
            [$o('project'), $project->code.' · '.$project->name, $project->code.' · '.$project->name],
            [$o('client'), $client->name ?? self::t('report_pdf.internal_project'), $client->name ?? self::t('report_pdf.internal_project')],
            [$o('status'), $project->status->label(), $project->status->label()],
            [$o('billing'), $project->billing_type->label(), $project->billing_type->label()],
            [$o('start'), PdfFormat::date($project->start_date?->toDateString()) ?: '—', $project->start_date?->toDateString()],
            [$o('due'), PdfFormat::date($project->due_date?->toDateString()) ?: '—', $project->due_date?->toDateString()],
        ];

        if ($project->budget_minutes !== null) {
            $rows[] = $hours($o('budget'), $project->budget_minutes);
        }
        if ($financials) {
            if ($project->billing_type === BillingType::FixedPrice) {
                $rows[] = [$o('fixed_price'), PdfFormat::money($project->fixed_price_amount) ?: '—', TableExporter::money($project->fixed_price_amount)];
            }
            $rate = $project->hourly_rate ?? $client?->default_hourly_rate;
            $rows[] = [$o($project->hourly_rate === null && $rate !== null ? 'client_rate' : 'rate'), $rate === null ? '—' : PdfFormat::money($rate).'/h', TableExporter::money($rate)];
            if ($banks !== []) {
                $price = Money::round(Money::add('0', ...array_map(fn (array $bank): string => (string) ($bank['price_amount'] ?? '0'), $banks)));
                $rows[] = [$o('banks_price'), PdfFormat::money($price), TableExporter::money($price)];
            }
        }

        $rows[] = [$o('period'), PdfFormat::text('report_pdf.period.range', ['from' => $scope->filters->from->format('d/m/Y'), 'to' => $scope->filters->to->format('d/m/Y')]),
            $scope->filters->from->toDateString().' – '.$scope->filters->to->toDateString()];
        $rows[] = $hours($o('period_logged'), (int) $summary['logged_minutes']);
        $rows[] = $hours($o('period_billable'), (int) $summary['billable_minutes']);
        if ($banks !== []) {
            $rows[] = $hours($o('period_in_bank'), (int) $summary['in_bank_minutes']);
            $rows[] = $hours($o('period_overage'), (int) $summary['overage_minutes']);
        }
        $rows[] = $hours($o('lifetime'), $lifetime);
        $rows[] = $hours($o('estimated'), $estimated);
        $rows[] = $percent($o('deviation'), $estimated > 0 ? ($lifetime - $estimated) / $estimated : null);
        if ($project->budget_minutes !== null && $project->budget_minutes > 0) {
            $rows[] = $percent($o('budget_used'), $lifetime / $project->budget_minutes);
        }
        if ($banks !== []) {
            $contracted = array_sum(array_column($banks, 'total_minutes'));
            $consumed = array_sum(array_column($banks, 'consumed_minutes'));
            $rows[] = $hours($o('banks_contracted'), $contracted);
            $rows[] = $hours($o('banks_consumed'), $consumed);
            $rows[] = $percent($o('banks_used'), $contracted > 0 ? $consumed / $contracted : null);
        }
        if ($financials) {
            foreach (['income', 'cost', 'margin'] as $key) {
                $amount = is_string($summary[$key] ?? null) ? $summary[$key] : '0.00';
                $rows[] = [$o($key), PdfFormat::money($amount), TableExporter::money($amount)];
            }
            $rows[] = $percent($o('margin_pct'), is_numeric($summary['margin_pct'] ?? null) ? (float) $summary['margin_pct'] : null);
        }

        return $rows;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|int|float|null}>  $overview
     * @return Table
     */
    private static function overviewPdf(array $overview): array
    {
        return PdfTable::make([[self::t('report_pdf.project.overview.concept')], [self::t('report_pdf.project.overview.value')]],
            array_map(fn (array $row): array => [$row[0], $row[1]], $overview), compact: true);
    }

    /**
     * Matriz tarea principal × persona en el PDF: las personas con más horas en columnas (el resto,
     * en «Otros») y las tareas con más horas en filas, con los totales.
     *
     * @param  Matrix  $matrix
     * @return Table
     */
    private static function matrixPdf(array $matrix): array
    {
        $people = array_slice($matrix['people'], 0, self::PDF_MATRIX_PEOPLE);
        $others = array_slice($matrix['people'], self::PDF_MATRIX_PEOPLE);
        $otherIds = array_column($others, 'id');
        $cell = fn (int $minutes): string => $minutes > 0 ? PdfFormat::minutes($minutes) : '';

        $columns = [[self::t('report_pdf.columns.task')], ...array_map(fn (array $person): array => [$person['name'], true], $people),
            ...($others !== [] ? [[self::t('report_pdf.others', ['count' => (string) count($others)]), true]] : []), [self::t('report_pdf.total'), true]];

        $rows = array_map(function (array $task) use ($matrix, $people, $otherIds, $others, $cell): array {
            $cells = $matrix['cells'][$task['id']] ?? [];

            return [
                $task['title'],
                ...array_map(fn (array $person): string => $cell($cells[$person['id']] ?? 0), $people),
                ...($others !== [] ? [$cell(array_sum(array_intersect_key($cells, array_flip($otherIds))))] : []),
                PdfFormat::minutes($task['total']),
            ];
        }, array_slice($matrix['tasks'], 0, self::PDF_MATRIX_TASKS));

        $sum = $matrix['tasks'] === [] ? null : [
            self::t('report_pdf.total'),
            ...array_map(fn (array $person): string => PdfFormat::minutes($person['total']), $people),
            ...($others !== [] ? [PdfFormat::minutes(array_sum(array_column($others, 'total')))] : []),
            PdfFormat::minutes(array_sum(array_column($matrix['tasks'], 'total'))),
        ];

        return PdfTable::make($columns, $rows, $sum, compact: true, empty: self::t('report_pdf.no_hours'));
    }

    /**
     * La matriz entera en Excel: una columna por persona (horas en decimal) y el total.
     *
     * @param  Matrix  $matrix
     * @return array{0: list<string>, 1: list<list<string|float>>}
     */
    private static function matrixSheet(array $matrix): array
    {
        $headers = [self::t('reports.r2.project.columns.task'), ...array_column($matrix['people'], 'name'), self::t('reports.r2.project.columns.total_hours')];
        $rows = array_map(fn (array $task): array => [
            $task['title'],
            ...array_map(fn (array $person): ?float => ($matrix['cells'][$task['id']][$person['id']] ?? 0) > 0
                ? TableExporter::hours($matrix['cells'][$task['id']][$person['id']]) : null, $matrix['people']),
            TableExporter::hours($task['total']),
        ], $matrix['tasks']);

        if ($matrix['tasks'] !== []) {
            $rows[] = [self::t('reports.r2.total'), ...array_map(fn (array $person): float => TableExporter::hours($person['total']), $matrix['people']),
                TableExporter::hours(array_sum(array_column($matrix['tasks'], 'total')))];
        }

        return [$headers, $rows];
    }

    /**
     * @param  list<MonthRow>  $months
     * @return Table
     */
    private static function monthsPdf(array $months, bool $banks, bool $financials): array
    {
        $columns = [[self::t('report_pdf.columns.month')], [self::t('report_pdf.columns.logged'), true], [self::t('report_pdf.columns.billable'), true],
            ...($banks ? [[self::t('report_pdf.columns.in_bank'), true], [self::t('report_pdf.columns.overage'), true]] : []),
            ...($financials ? [[self::t('report_pdf.columns.income'), true], [self::t('report_pdf.columns.cost'), true]] : [])];
        $line = fn (string $label, array $row): array => [
            $label,
            PdfFormat::minutes((int) $row['logged_minutes']),
            PdfFormat::minutes((int) $row['billable_minutes']),
            ...($banks ? [PdfFormat::minutes((int) $row['in_bank_minutes']),
                (int) $row['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage((int) $row['overage_minutes']), 'overage') : PdfFormat::overage(0)] : []),
            ...($financials ? [PdfFormat::money($row['income'] ?? '0.00'), PdfFormat::money($row['cost'] ?? '0.00')] : []),
        ];

        return PdfTable::make($columns, array_map(fn (array $month): array => $line(ucfirst(PdfFormat::month($month['month'])), $month), $months),
            $months === [] ? null : $line(self::t('report_pdf.total'), self::sumMonths($months)));
    }

    /**
     * @param  list<MonthRow>  $months
     * @return array{0: list<string>, 1: list<list<string|float|null>>}
     */
    private static function monthsSheet(array $months, bool $banks, bool $financials): array
    {
        $c = fn (string $key): string => self::t('reports.r2.project.columns.'.$key);
        $headers = [$c('month'), $c('logged'), $c('billable'), ...($banks ? [$c('in_bank'), $c('overage')] : []), ...($financials ? [$c('income'), $c('cost')] : [])];
        $line = fn (string $label, array $row): array => [
            $label,
            TableExporter::hours((int) $row['logged_minutes']),
            TableExporter::hours((int) $row['billable_minutes']),
            ...($banks ? [TableExporter::hours((int) $row['in_bank_minutes']), TableExporter::hours((int) $row['overage_minutes'])] : []),
            ...($financials ? [TableExporter::money($row['income'] ?? '0.00'), TableExporter::money($row['cost'] ?? '0.00')] : []),
        ];

        $rows = array_map(fn (array $month): array => $line(substr($month['month'], 0, 7), $month), $months);
        if ($months !== []) {
            $rows[] = $line(self::t('reports.r2.total'), self::sumMonths($months));
        }

        return [$headers, $rows];
    }

    /**
     * @param  list<MonthRow>  $months
     * @return array{logged_minutes: int, billable_minutes: int, in_bank_minutes: int, overage_minutes: int, income: string, cost: string}
     */
    private static function sumMonths(array $months): array
    {
        $money = fn (string $key): string => Money::round(Money::add('0', ...array_map(fn (array $month): string => (string) ($month[$key] ?? '0'), $months)));

        return [
            'logged_minutes' => array_sum(array_column($months, 'logged_minutes')),
            'billable_minutes' => array_sum(array_column($months, 'billable_minutes')),
            'in_bank_minutes' => array_sum(array_column($months, 'in_bank_minutes')),
            'overage_minutes' => array_sum(array_column($months, 'overage_minutes')),
            'income' => $money('income'),
            'cost' => $money('cost'),
        ];
    }

    /**
     * Bolsas del proyecto con su consumo de toda la vida (HourBankLedger) y lo del periodo.
     *
     * @param  list<BankRow>  $banks
     * @return Table
     */
    private static function banksPdf(array $banks, bool $financials): array
    {
        return PdfTable::make(
            [[self::t('report_pdf.columns.bank')], [self::t('report_pdf.columns.status')], [self::t('report_pdf.columns.validity')],
                [self::t('report_pdf.columns.contracted'), true], [self::t('report_pdf.columns.in_bank'), true], [self::t('report_pdf.columns.overage'), true],
                [self::t('report_pdf.columns.remaining'), true], [self::t('report_pdf.columns.consumed_pct'), true], [self::t('report_pdf.columns.period_hours'), true],
                ...($financials ? [[self::t('report_pdf.columns.price'), true]] : [])],
            array_map(fn (array $bank): array => [
                $bank['name'],
                HourBankStatus::from($bank['status'])->label(),
                PdfFormat::date($bank['start_date']).($bank['end_date'] !== null ? ' – '.PdfFormat::date($bank['end_date']) : ''),
                PdfFormat::minutes($bank['total_minutes']),
                PdfFormat::minutes($bank['in_bank_minutes']),
                $bank['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage($bank['overage_minutes']), 'overage') : PdfFormat::overage(0),
                PdfFormat::minutes($bank['remaining_minutes']),
                PdfFormat::percent($bank['consumed_ratio']),
                PdfFormat::minutes($bank['period_minutes']),
                ...($financials ? [$bank['price_amount'] !== null ? PdfFormat::money($bank['price_amount']) : '—'] : []),
            ], $banks),
            empty: self::t('report_pdf.client.no_banks'),
        );
    }

    /**
     * @param  list<BankRow>  $banks
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private static function banksSheet(array $banks, bool $financials): array
    {
        $c = fn (string $key): string => self::t('reports.r2.client.columns.'.$key);
        $p = fn (string $key): string => self::t('reports.r2.project.columns.'.$key);

        return [
            [$c('bank'), $c('status'), $c('start'), $c('end'), $c('total'), $c('consumed'), $c('bank_in'), $c('bank_overage'), $c('remaining'),
                $p('consumed_pct'), $p('period_hours'), ...($financials ? [$p('bank_price'), $p('bank_rate')] : [])],
            array_map(fn (array $bank): array => [
                $bank['name'],
                HourBankStatus::from($bank['status'])->label(),
                $bank['start_date'],
                $bank['end_date'],
                TableExporter::hours($bank['total_minutes']),
                TableExporter::hours($bank['consumed_minutes']),
                TableExporter::hours($bank['in_bank_minutes']),
                TableExporter::hours($bank['overage_minutes']),
                TableExporter::hours($bank['remaining_minutes']),
                round($bank['consumed_ratio'] * 100, 1),
                TableExporter::hours($bank['period_minutes']),
                ...($financials ? [TableExporter::money($bank['price_amount']), TableExporter::money($bank['hourly_rate'])] : []),
            ], $banks),
        ];
    }

    /**
     * Costes y margen por persona (D-240, solo con view-financials): horas, coste medio por hora (de
     * las instantáneas de coste, Valuation), coste, ingreso estimado (según la facturación del
     * proyecto, RevenueCalculator) y rentabilidad, con el total del resumen.
     *
     * @param  list<array<string, mixed>>  $people  Metrics::breakdown(Person)
     * @param  array<string, mixed>  $summary
     * @return list<array{name: string, minutes: int, cost: string, income: string, margin: string, margin_pct: float|null, hourly_cost: string|null}>
     */
    private static function costs(array $people, array $summary): array
    {
        $line = function (string $name, int $minutes, string $cost, string $income): array {
            $margin = Money::round(Money::sub($income, $cost));

            return [
                'name' => $name,
                'minutes' => $minutes,
                'cost' => Money::round($cost),
                'income' => Money::round($income),
                'margin' => $margin,
                'margin_pct' => Money::isZero($income) ? null : round((float) Money::div($margin, $income), 4),
                'hourly_cost' => $minutes > 0 ? Money::round(Money::div(Money::mul($cost, '60'), (string) $minutes)) : null,
            ];
        };

        $rows = array_map(fn (array $row): array => $line((string) $row['name'], (int) $row['logged_minutes'],
            is_string($row['cost'] ?? null) ? $row['cost'] : '0', is_string($row['income'] ?? null) ? $row['income'] : '0'), $people);

        $rows[] = $line(self::t('report_pdf.total'), (int) $summary['logged_minutes'],
            is_string($summary['cost'] ?? null) ? $summary['cost'] : '0', is_string($summary['income'] ?? null) ? $summary['income'] : '0');

        return $rows;
    }

    /**
     * @param  list<array{name: string, minutes: int, cost: string, income: string, margin: string, margin_pct: float|null, hourly_cost: string|null}>  $costs
     * @return Table
     */
    private static function costsPdf(array $costs): array
    {
        $line = fn (array $row): array => [
            $row['name'],
            PdfFormat::minutes($row['minutes']),
            $row['hourly_cost'] === null ? '—' : PdfFormat::money($row['hourly_cost']),
            PdfFormat::money($row['cost']),
            PdfFormat::money($row['income']),
            str_starts_with($row['margin'], '-') ? PdfTable::cell(PdfFormat::money($row['margin']), 'overage') : PdfFormat::money($row['margin']),
            PdfFormat::percent($row['margin_pct']),
        ];
        $total = array_pop($costs);

        return PdfTable::make(
            [[self::t('report_pdf.columns.person')], [self::t('report_pdf.columns.hours'), true], [self::t('report_pdf.columns.hourly_cost'), true],
                [self::t('report_pdf.columns.cost'), true], [self::t('report_pdf.columns.income'), true], [self::t('report_pdf.columns.margin'), true],
                [self::t('report_pdf.columns.margin_pct'), true]],
            array_map($line, $costs),
            $costs === [] || $total === null ? null : $line($total),
            empty: self::t('report_pdf.no_hours'),
        );
    }

    /**
     * @param  list<array{name: string, minutes: int, cost: string, income: string, margin: string, margin_pct: float|null, hourly_cost: string|null}>  $costs
     * @return array{0: list<string>, 1: list<list<string|float|null>>}
     */
    private static function costsSheet(array $costs): array
    {
        $c = fn (string $key): string => self::t('reports.r2.project.columns.'.$key);

        return [
            [$c('person'), $c('logged'), $c('hourly_cost'), $c('cost'), $c('income'), $c('margin'), $c('margin_pct')],
            array_map(fn (array $row): array => [
                $row['name'],
                TableExporter::hours($row['minutes']),
                TableExporter::money($row['hourly_cost']),
                TableExporter::money($row['cost']),
                TableExporter::money($row['income']),
                TableExporter::money($row['margin']),
                $row['margin_pct'] === null ? null : round($row['margin_pct'] * 100, 1),
            ], $costs),
        ];
    }

    /**
     * Listado de entradas en el PDF: fecha, persona, tarea (con su tarea principal), franja,
     * duración, descripción y, en el interno, facturable y estado.
     *
     * @param  iterable<EntryRow>  $entries
     * @return Table
     */
    private static function entriesPdf(iterable $entries, bool $internal): array
    {
        $rows = [];
        $total = 0;
        foreach ($entries as $entry) {
            $total += $entry['minutes'];
            $rows[] = [
                PdfFormat::date($entry['date']),
                $entry['person'],
                self::taskLabel($entry),
                self::range($entry),
                PdfFormat::minutes($entry['minutes']),
                $entry['description'],
                ...($internal ? [$entry['billable'] ? self::t('report_pdf.yes') : self::t('report_pdf.no'), ProjectReportSections::statusLabel($entry['status'])] : []),
            ];
        }

        $columns = [[self::t('report_pdf.columns.date')], [self::t('report_pdf.columns.person')], [self::t('report_pdf.columns.task')],
            [self::t('report_pdf.columns.range')], [self::t('report_pdf.columns.hours'), true], [self::t('report_pdf.columns.description')],
            ...($internal ? [[self::t('report_pdf.columns.billable_short')], [self::t('report_pdf.columns.status')]] : [])];

        return PdfTable::make($columns, $rows, $rows === [] ? null : [self::t('report_pdf.total'), '', '', '', PdfFormat::minutes($total), '', ...($internal ? ['', ''] : [])],
            compact: true, empty: self::t('report_pdf.no_hours'));
    }

    /**
     * Cabecera de la hoja de entradas.
     *
     * @return list<string>
     */
    private static function entryHeaders(bool $internal): array
    {
        $c = fn (string $key): string => self::t('reports.r2.project.columns.'.$key);

        return [$c('date'), $c('person'), $c('task'), $c('parent'), $c('start'), $c('end'), $c('hours'), $c('minutes'), $c('description'),
            ...($internal ? [$c('billable_flag'), $c('status')] : [])];
    }

    /**
     * Filas de la hoja de entradas, en streaming; si hay más de las que caben, la última avisa.
     *
     * @param  iterable<EntryRow>  $entries
     * @return Generator<int, array<int, string|int|float|bool|null>>
     */
    private static function entrySheetRows(iterable $entries, int $count, int $limit, bool $internal): Generator
    {
        foreach ($entries as $entry) {
            yield [
                $entry['date'],
                $entry['person'],
                $entry['task'],
                $entry['parent'],
                $entry['start'],
                $entry['end'],
                TableExporter::hours($entry['minutes']),
                $entry['minutes'],
                $entry['description'],
                ...($internal ? [$entry['billable'], ProjectReportSections::statusLabel($entry['status'])] : []),
            ];
        }

        if ($count > $limit) {
            yield [self::t('reports.r3.hours.truncated', ['count' => $limit])];
        }
    }

    /**
     * @param  EntryRow  $entry
     */
    private static function taskLabel(array $entry): string
    {
        return $entry['parent'] !== null ? $entry['parent'].' › '.$entry['task'] : $entry['task'];
    }

    /**
     * Franja «09:00–11:30» (hora de Madrid, D-172), o «—» si la entrada no la tiene.
     *
     * @param  EntryRow  $entry
     */
    private static function range(array $entry): string
    {
        return $entry['start'] !== null && $entry['end'] !== null ? $entry['start'].'–'.$entry['end'] : '—';
    }
}
