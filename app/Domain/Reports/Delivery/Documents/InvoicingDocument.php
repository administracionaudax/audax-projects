<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Billing\InvoicingQuery;
use App\Domain\Billing\InvoicingReport;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Enums\BillingService;
use App\Models\User;

/**
 * Informe de facturación (D-400) en PDF, Excel y CSV, con el patrón de los demás informes (D-139,
 * D-140): los mismos filtros de la URL y los permisos de quien lo pide (view-billing).
 * - Excel: un libro con el resumen, los meses, los servicios, los clientes, la antigüedad y las
 *   facturas vencidas.
 * - CSV (una sola tabla): la de ?tabla=meses|servicios|clientes|antiguedad|vencidas (por defecto,
 *   los meses).
 *
 * @phpstan-import-type InvoicingData from InvoicingReport
 */
final class InvoicingDocument extends BaseDocument
{
    /** Tablas del informe, en el orden del libro de Excel. */
    public const array TABLES = ['resumen', 'meses', 'servicios', 'clientes', 'antiguedad', 'vencidas'];

    public function __construct(private readonly InvoicingReport $report) {}

    /**
     * El informe de la página (las mismas cifras que el fichero).
     *
     * @return InvoicingData
     */
    public function data(InvoicingQuery $query): array
    {
        return $this->report->report($query);
    }

    public function title(ReportRequest $request, User $as): string
    {
        $query = $this->authorized($request, $as);

        return self::joinTitle(self::t('billing.invoicing.title'), PdfFormat::period($query->filters));
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        $query = $this->authorized($request, $as);
        $data = $this->data($query);
        $tables = $this->tables($data);
        $requested = self::queryString($request, 'tabla');
        $main = in_array($requested, self::TABLES, true) ? $requested : 'meses';

        $sheets = [];
        foreach (self::TABLES as $name) {
            $sheets[] = new ExportSheet(self::t('billing.invoicing.sheets.'.$name), $tables[$name][0], $tables[$name][1]);
        }

        return new ExportTable(
            self::filename(self::t('billing.invoicing.export_name'), $main === 'meses' ? '' : $main),
            $tables[$main][0],
            $tables[$main][1],
            $this->title($request, $as),
            $requested === null || $requested === '' ? $sheets : [],
        );
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        $query = $this->authorized($request, $as);
        $data = $this->data($query);
        $k = $data['kpis'];
        $compare = (bool) $data['compare'];
        $c = fn (string $key): string => self::t('billing.invoicing.columns.'.$key);

        $kpis = [
            ['label' => self::t('billing.invoicing.kpis.invoiced'), 'value' => PdfFormat::money($k['invoiced']), 'detail' => self::variation($k['variation_pct'], $k['previous_invoiced'])],
            ['label' => self::t('billing.invoicing.kpis.collected'), 'value' => PdfFormat::money($k['collected']), 'detail' => self::t('billing.invoicing.with_vat')],
            ['label' => self::t('billing.invoicing.kpis.outstanding'), 'value' => PdfFormat::money($k['outstanding']), 'detail' => self::t('billing.invoicing.with_vat')],
            ['label' => self::t('billing.invoicing.kpis.overdue'), 'value' => PdfFormat::money($k['overdue']), 'detail' => self::t('billing.invoicing.with_vat')],
            ['label' => self::t('billing.invoicing.kpis.planned'), 'value' => PdfFormat::money($k['planned']), 'detail' => trans_choice('billing.invoicing.drafts', (int) $k['planned_count'], ['count' => (int) $k['planned_count']])],
            ['label' => self::t('billing.invoicing.kpis.count'), 'value' => PdfFormat::number((int) $k['count']), 'detail' => null],
            ['label' => self::t('billing.invoicing.kpis.average'), 'value' => $k['average'] === null ? '—' : PdfFormat::money($k['average']), 'detail' => null],
        ];

        $months = array_map(fn (array $month): array => [
            PdfFormat::month($month['month'].'-01'),
            PdfFormat::money($month['invoiced']),
            ...($compare ? [PdfFormat::money($month['previous'])] : []),
            PdfFormat::money($month['planned']),
            PdfFormat::number((int) $month['count']),
        ], $data['months']);
        $monthColumns = [[$c('month')], [$c('invoiced'), true], ...($compare ? [[$c('previous'), true]] : []), [$c('planned'), true], [$c('count'), true]];
        $monthSum = [self::t('report_pdf.total'), PdfFormat::money($k['invoiced']), ...($compare ? [PdfFormat::money($k['previous_invoiced'])] : []), PdfFormat::money($k['planned']), PdfFormat::number((int) $k['count'])];

        $services = array_map(fn (array $row): array => [self::serviceLabel($row['key']), PdfFormat::money($row['amount']), self::share($row['share'])], $data['services']);

        $clients = [];
        foreach ($data['clients']['top'] as $index => $client) {
            $clients[] = [($index + 1).'. '.$client['name'], PdfFormat::money($client['amount']), self::share($client['share']), PdfFormat::number((int) $client['count'])];
        }
        if ($data['clients']['rest'] !== null) {
            $rest = $data['clients']['rest'];
            $clients[] = [PdfTable::cell(trans_choice('billing.invoicing.rest', (int) $rest['clients'], ['count' => (int) $rest['clients']]), 'muted'), PdfFormat::money($rest['amount']), self::share($rest['share']), PdfFormat::number((int) $rest['count'])];
        }
        if ($data['clients']['unmatched'] !== null) {
            $unmatched = $data['clients']['unmatched'];
            $clients[] = [PdfTable::cell(self::t('billing.invoicing.unmatched'), 'muted'), PdfFormat::money($unmatched['amount']), self::share($unmatched['share']), PdfFormat::number((int) $unmatched['count'])];
        }

        $aging = array_map(fn (array $bucket): array => [self::t('billing.invoicing.aging.'.$bucket['key']), PdfFormat::money($bucket['amount']), PdfFormat::number((int) $bucket['count'])], $data['aging']);

        $overdue = [];
        foreach ($data['overdue']['clients'] as $group) {
            foreach ($group['invoices'] as $invoice) {
                $overdue[] = [
                    $group['client']['name'] ?? ($group['contact_name'] ?? self::t('billing.invoicing.unmatched')),
                    (string) ($invoice['number'] ?? '—'),
                    PdfFormat::date($invoice['due_on']),
                    PdfFormat::number((int) $invoice['days']),
                    PdfFormat::money($invoice['pending']),
                ];
            }
        }

        $facts = self::filterFacts($query->filters, ['persona', 'departamento', 'proyecto', 'bolsa', 'tipo', 'facturable']);
        if ($query->services !== []) {
            $facts[] = [self::t('billing.invoicing.filters.service'), implode(', ', array_map(fn (BillingService $service): string => $service->label(), $query->services))];
        }
        if ($compare) {
            $facts[] = [self::t('billing.invoicing.filters.compare'), self::t('report_pdf.period.range', ['from' => PdfFormat::date($data['previous_from']), 'to' => PdfFormat::date($data['previous_to'])])];
        }

        return new ReportPdf(
            view: 'reports.pdf.invoicing',
            title: $this->title($request, $as),
            filename: self::filename(self::t('billing.invoicing.export_name'), PdfFormat::periodSlug($query->filters)),
            data: [
                'cover' => self::cover(self::t('billing.invoicing.kicker'), self::t('billing.invoicing.title'), PdfFormat::period($query->filters), $facts, $as, true),
                'kpis' => $kpis,
                'months' => PdfTable::make($monthColumns, $months, $monthSum, compact: true),
                'services' => PdfTable::make([[$c('service')], [$c('invoiced'), true], [$c('share'), true]], $services, null, compact: true, empty: self::t('billing.invoicing.empty')),
                'clients' => PdfTable::make([[$c('client')], [$c('invoiced'), true], [$c('share'), true], [$c('count'), true]], $clients, null, compact: true, empty: self::t('billing.invoicing.empty')),
                'aging' => PdfTable::make([[$c('bucket')], [$c('outstanding'), true], [$c('count'), true]], $aging, [self::t('report_pdf.total'), PdfFormat::money($k['outstanding']), ''], compact: true),
                'overdue' => PdfTable::make([[$c('client')], [$c('number')], [$c('due_on')], [$c('days'), true], [$c('outstanding'), true]], $overdue, null, compact: true, empty: self::t('billing.invoicing.no_overdue')),
                'overdue_more' => max(0, (int) $data['overdue']['total'] - (int) $data['overdue']['shown']),
                'services_filtered' => $query->services !== [],
                'definitions' => [
                    [self::t('billing.invoicing.kpis.invoiced'), self::t('billing.invoicing.definitions.invoiced')],
                    [self::t('billing.invoicing.kpis.collected'), self::t('billing.invoicing.definitions.collected')],
                    [self::t('billing.invoicing.kpis.outstanding'), self::t('billing.invoicing.definitions.outstanding')],
                    [self::t('billing.invoicing.kpis.planned'), self::t('billing.invoicing.definitions.planned')],
                    [self::t('billing.invoicing.kpis.average'), self::t('billing.invoicing.definitions.average')],
                    [self::t('billing.invoicing.charts.services'), self::t('billing.invoicing.definitions.services')],
                ],
            ],
        );
    }

    /**
     * Las tablas del libro: [cabecera, filas] de cada una.
     *
     * @param  InvoicingData  $data
     * @return array<string, array{0: list<string>, 1: list<list<string|int|float|null>>}>
     */
    private function tables(array $data): array
    {
        $c = fn (string $key): string => self::t('billing.invoicing.columns.'.$key);
        $k = $data['kpis'];
        $compare = (bool) $data['compare'];
        $money = fn (?string $amount): ?float => TableExporter::money($amount);
        $pct = fn (?string $share): ?float => $share === null ? null : (float) $share;

        $summary = [
            [self::t('billing.invoicing.kpis.invoiced'), $money($k['invoiced'])],
            [self::t('billing.invoicing.kpis.previous_invoiced'), $money($k['previous_invoiced'])],
            [self::t('billing.invoicing.kpis.variation'), $pct($k['variation_pct'])],
            [self::t('billing.invoicing.kpis.credit_notes'), $money($k['credit_notes'])],
            [self::t('billing.invoicing.kpis.collected'), $money($k['collected'])],
            [self::t('billing.invoicing.kpis.outstanding'), $money($k['outstanding'])],
            [self::t('billing.invoicing.kpis.overdue'), $money($k['overdue'])],
            [self::t('billing.invoicing.kpis.planned'), $money($k['planned'])],
            [self::t('billing.invoicing.kpis.count'), (int) $k['count']],
            [self::t('billing.invoicing.kpis.average'), $money($k['average'])],
        ];

        $months = array_map(fn (array $month): array => [
            $month['month'],
            $money($month['invoiced']),
            ...($compare ? [$money($month['previous'])] : []),
            $money($month['planned']),
            (int) $month['count'],
        ], $data['months']);
        $months[] = [self::t('reports.r2.total'), $money($k['invoiced']), ...($compare ? [$money($k['previous_invoiced'])] : []), $money($k['planned']), (int) $k['count']];

        $clients = array_map(fn (array $client): array => [$client['name'], $money($client['amount']), $pct($client['share']), (int) $client['count']], $data['clients']['top']);
        if ($data['clients']['rest'] !== null) {
            $rest = $data['clients']['rest'];
            $clients[] = [trans_choice('billing.invoicing.rest', (int) $rest['clients'], ['count' => (int) $rest['clients']]), $money($rest['amount']), $pct($rest['share']), (int) $rest['count']];
        }
        if ($data['clients']['unmatched'] !== null) {
            $unmatched = $data['clients']['unmatched'];
            $clients[] = [self::t('billing.invoicing.unmatched'), $money($unmatched['amount']), $pct($unmatched['share']), (int) $unmatched['count']];
        }

        $overdue = [];
        foreach ($data['overdue']['clients'] as $group) {
            foreach ($group['invoices'] as $invoice) {
                $overdue[] = [$group['client']['name'] ?? ($group['contact_name'] ?? self::t('billing.invoicing.unmatched')), $invoice['number'], $invoice['issued_on'], $invoice['due_on'], (int) $invoice['days'], $money($invoice['pending'])];
            }
        }

        return [
            'resumen' => [[$c('figure'), $c('value')], $summary],
            'meses' => [[$c('month'), $c('invoiced'), ...($compare ? [$c('previous')] : []), $c('planned'), $c('count')], $months],
            'servicios' => [[$c('service'), $c('invoiced'), $c('share')], array_map(fn (array $row): array => [self::serviceLabel($row['key']), $money($row['amount']), $pct($row['share'])], $data['services'])],
            'clientes' => [[$c('client'), $c('invoiced'), $c('share'), $c('count')], $clients],
            'antiguedad' => [[$c('bucket'), $c('outstanding'), $c('count')], array_map(fn (array $bucket): array => [self::t('billing.invoicing.aging.'.$bucket['key']), $money($bucket['amount']), (int) $bucket['count']], $data['aging'])],
            'vencidas' => [[$c('client'), $c('number'), $c('issued_on'), $c('due_on'), $c('days'), $c('outstanding')], $overdue],
        ];
    }

    private static function serviceLabel(string $key): string
    {
        return BillingService::tryFrom($key)?->label() ?? self::t('billing.invoicing.services.'.$key);
    }

    private static function share(?string $share): string
    {
        return $share === null ? '—' : PdfFormat::number((float) $share, 1).' %';
    }

    private static function variation(?string $pct, string $previous): string
    {
        if ($pct === null) {
            return self::t('billing.invoicing.no_previous');
        }

        return self::t('billing.invoicing.variation', [
            'pct' => ((float) $pct > 0 ? '+' : '').PdfFormat::number((float) $pct, 1).' %',
            'previous' => PdfFormat::money($previous),
        ]);
    }

    private function authorized(ReportRequest $request, User $as): InvoicingQuery
    {
        self::authorizeFor($as, 'view-billing', []);

        return InvoicingQuery::fromQuery($request->query);
    }
}
