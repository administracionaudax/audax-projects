<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Billing\SoldVsActual;
use App\Domain\Billing\SoldVsActualQuery;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Enums\SaleKind;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * «Vendido frente a real» (Fase 12, F1; D-390) en PDF, Excel y CSV, con el mismo patrón que los demás
 * informes (D-139, D-140): los mismos filtros de la URL, los permisos de quien lo pide (view-sold-
 * vs-actual) y los importes solo con view-billing. Una fila por unidad de venta y la de totales.
 */
final class SoldVsActualDocument extends BaseDocument
{
    public function __construct(private readonly SoldVsActual $report) {}

    /**
     * El informe de la página (las mismas cifras que el fichero).
     *
     * @return array{units: list<array<string, mixed>>, totals: array<string, mixed>, financials: bool, from: string, to: string}
     */
    public function data(SoldVsActualQuery $query, User $as): array
    {
        return $this->report->report($query, $as, Gate::forUser($as)->allows('view-billing'));
    }

    public function title(ReportRequest $request, User $as): string
    {
        $query = $this->authorized($request, $as);

        return self::joinTitle(self::t('billing.report.title'), PdfFormat::period($query->filters));
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        $query = $this->authorized($request, $as);
        $data = $this->data($query, $as);
        $financials = $data['financials'];
        $c = fn (string $key): string => self::t('billing.report.columns.'.$key);

        $headers = [$c('kind'), $c('client'), $c('project'), $c('unit'), $c('manager'), $c('sold_hours'), $c('real_hours'), $c('pending_hours'), $c('deviation_hours'), $c('consumption'), $c('status')];
        if ($financials) {
            array_push($headers, $c('sold_amount'), $c('invoiced'), $c('invoiced_total'), $c('collected'), $c('outstanding'), $c('to_invoice'), $c('cost'), $c('margin'), $c('margin_pct'), $c('effective_rate'));
        }
        array_push($headers, $c('sold_minutes'), $c('real_minutes'), $c('pending_minutes'));

        $rows = [];
        foreach ($data['units'] as $unit) {
            $row = [
                SaleKind::from($unit['kind'])->label(),
                $unit['client']['name'] ?? '',
                $unit['project']['code'].' · '.$unit['project']['name'],
                $unit['name'],
                $unit['manager']['name'] ?? '',
                $unit['sold_minutes'] !== null ? TableExporter::hours($unit['sold_minutes']) : null,
                TableExporter::hours($unit['real_minutes']),
                TableExporter::hours($unit['pending_minutes']),
                $unit['deviation_minutes'] !== null ? TableExporter::hours($unit['deviation_minutes']) : null,
                $unit['consumption_pct'],
                self::t('billing.report.status.'.$unit['status']),
            ];
            if ($financials) {
                array_push($row,
                    TableExporter::money($unit['sold_amount'] ?? $unit['hours_value']),
                    TableExporter::money($unit['invoiced']),
                    TableExporter::money($unit['invoiced_total']),
                    TableExporter::money($unit['collected']),
                    TableExporter::money($unit['outstanding']),
                    TableExporter::money($unit['to_invoice']),
                    TableExporter::money($unit['cost']),
                    TableExporter::money($unit['margin']),
                    $unit['margin_pct'],
                    TableExporter::money($unit['effective_rate']),
                );
            }
            array_push($row, $unit['sold_minutes'], $unit['real_minutes'], $unit['pending_minutes']);
            $rows[] = $row;
        }

        $t = $data['totals'];
        $sum = [self::t('reports.r2.total'), '', '', '', '', TableExporter::hours($t['sold_minutes']), TableExporter::hours($t['real_minutes']), TableExporter::hours($t['pending_minutes']),
            TableExporter::hours($t['deviation_minutes']), $t['consumption_pct'], ''];
        if ($financials) {
            array_push($sum, TableExporter::money($t['income']), TableExporter::money($t['invoiced']), TableExporter::money($t['invoiced_total']), TableExporter::money($t['collected']),
                TableExporter::money($t['outstanding']), TableExporter::money($t['to_invoice']), TableExporter::money($t['cost']), TableExporter::money($t['margin']), $t['margin_pct'], null);
        }
        array_push($sum, $t['sold_minutes'], $t['real_minutes'], $t['pending_minutes']);
        $rows[] = $sum;

        return new ExportTable(self::t('billing.report.export_name'), $headers, $rows, $this->title($request, $as));
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        $query = $this->authorized($request, $as);
        $data = $this->data($query, $as);
        $financials = $data['financials'];
        $t = $data['totals'];
        $c = fn (string $key): string => self::t('billing.report.columns.'.$key);

        $columns = [[$c('unit')], [$c('kind')], [$c('sold_hours'), true], [$c('real_hours'), true], [$c('deviation_hours'), true], [$c('consumption'), true]];
        if ($financials) {
            array_push($columns, [$c('sold_amount'), true], [$c('invoiced'), true], [$c('collected'), true], [$c('outstanding'), true], [$c('margin'), true]);
        }

        $rows = array_map(function (array $unit) use ($financials): array {
            $name = $unit['project']['code'].' · '.$unit['name'].($unit['client'] !== null ? ' ('.$unit['client']['name'].')' : '');
            $deviation = $unit['deviation_minutes'];

            return [
                $name,
                SaleKind::from($unit['kind'])->label().($unit['months'] !== null ? ' · '.trans_choice('billing.report.months', $unit['months'], ['count' => $unit['months']]) : ''),
                $unit['sold_minutes'] !== null ? PdfFormat::minutes($unit['sold_minutes']) : '—',
                PdfFormat::minutes($unit['real_minutes']),
                $deviation === null ? '—' : ($deviation > 0 ? PdfTable::cell('+'.PdfFormat::minutes($deviation), 'overage') : PdfFormat::minutes($deviation)),
                $unit['consumption_pct'] === null ? '—' : PdfFormat::number($unit['consumption_pct'], 1).' %',
                ...($financials ? [
                    PdfFormat::money($unit['sold_amount'] ?? $unit['hours_value']),
                    PdfFormat::money($unit['invoiced']),
                    PdfFormat::money($unit['collected']),
                    PdfFormat::money($unit['outstanding']),
                    PdfFormat::money($unit['margin']),
                ] : []),
            ];
        }, $data['units']);

        $sum = [self::t('report_pdf.total'), '', PdfFormat::minutes($t['sold_minutes']), PdfFormat::minutes($t['real_minutes']),
            ($t['deviation_minutes'] > 0 ? '+' : '').PdfFormat::minutes($t['deviation_minutes']), $t['consumption_pct'] === null ? '—' : PdfFormat::number($t['consumption_pct'], 1).' %',
            ...($financials ? [PdfFormat::money($t['income']), PdfFormat::money($t['invoiced']), PdfFormat::money($t['collected']), PdfFormat::money($t['outstanding']), PdfFormat::money($t['margin'])] : [])];

        $kpis = [
            ['label' => self::t('billing.report.kpis.sold'), 'value' => PdfFormat::minutes($t['sold_minutes']), 'detail' => null],
            ['label' => self::t('billing.report.kpis.real'), 'value' => PdfFormat::minutes($t['real_of_sold_minutes']), 'detail' => $t['consumption_pct'] === null ? null : PdfFormat::number($t['consumption_pct'], 1).' %'],
            ['label' => self::t('billing.report.kpis.deviation'), 'value' => ($t['deviation_minutes'] > 0 ? '+' : '').PdfFormat::minutes($t['deviation_minutes']), 'detail' => null],
            ...($financials ? [
                ['label' => self::t('billing.report.kpis.invoiced'), 'value' => PdfFormat::money($t['invoiced']), 'detail' => null],
                ['label' => self::t('billing.report.kpis.outstanding'), 'value' => PdfFormat::money($t['outstanding']), 'detail' => null],
                ['label' => self::t('billing.report.kpis.margin'), 'value' => PdfFormat::money($t['margin']), 'detail' => $t['margin_pct'] === null ? null : PdfFormat::number($t['margin_pct'], 1).' %'],
            ] : []),
        ];

        $facts = self::filterFacts($query->filters, ['persona', 'departamento', 'proyecto', 'bolsa', 'tipo', 'facturable']);
        if ($query->kinds !== []) {
            $facts[] = [self::t('billing.report.filters.kind'), implode(', ', array_map(fn (SaleKind $kind): string => $kind->label(), $query->kinds))];
        }
        if ($query->managerId !== null) {
            $facts[] = [self::t('billing.report.filters.manager'), (string) (User::query()->whereKey($query->managerId)->value('name') ?? '—')];
        }

        return new ReportPdf(
            view: 'reports.pdf.sold-vs-actual',
            title: $this->title($request, $as),
            filename: self::filename(self::t('billing.report.export_name'), PdfFormat::periodSlug($query->filters)),
            data: [
                'cover' => self::cover(self::t('billing.report.kicker'), self::t('billing.report.title'), PdfFormat::period($query->filters), $facts, $as, $financials),
                'kpis' => $kpis,
                'summary' => PdfTable::make($columns, $rows, $rows === [] ? null : $sum, compact: true, empty: self::t('billing.report.empty')),
                'definitions' => [
                    [self::t('billing.report.kpis.sold'), self::t('billing.report.definitions.sold')],
                    [self::t('billing.report.kpis.real'), self::t('billing.report.definitions.real')],
                    [self::t('billing.report.kpis.deviation'), self::t('billing.report.definitions.deviation')],
                    ...($financials ? [
                        [self::t('billing.report.kpis.invoiced'), self::t('billing.report.definitions.invoiced')],
                        [self::t('billing.report.kpis.margin'), self::t('billing.report.definitions.margin')],
                    ] : []),
                ],
            ],
            landscape: true,
        );
    }

    private function authorized(ReportRequest $request, User $as): SoldVsActualQuery
    {
        self::authorizeFor($as, 'view-sold-vs-actual', []);

        return SoldVsActualQuery::fromQuery($request->query);
    }
}
