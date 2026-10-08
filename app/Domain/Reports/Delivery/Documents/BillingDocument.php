<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\BillingReport;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportScope;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Client;
use App\Models\User;
use Generator;

/**
 * Horas para facturar (/facturacion/horas-para-facturar, SPEC §10 «Exportación», D-045; R2): el cliente
 * (obligatorio, ?cliente[]=id), el detalle de cada entrada para Excel y CSV (422 si no cabe, D-045)
 * y el PDF con el resumen por proyecto y bolsa y el detalle. Quién: ClientPolicy::viewBilling.
 */
final class BillingDocument extends BaseDocument
{
    use BuildsReportScope;

    /** Entradas del detalle en el PDF (más, solo en Excel o CSV). */
    public const int PDF_ENTRIES = 1500;

    public function __construct(
        private readonly BillingReport $billing,
        private readonly ReportCache $cache,
        private readonly TableExporter $exporter,
    ) {}

    /**
     * Resumen por proyecto y bolsa (en caché), el de la página.
     *
     * @return array<string, mixed>
     */
    public function summary(ReportScope $scope, Client $client): array
    {
        return $this->cache->remember($scope, 'r2.billing.'.$client->id, fn (): array => $this->billing->summary($scope));
    }

    public function title(ReportRequest $request, User $as): string
    {
        [$client, $scope] = $this->authorized($request, $as);

        return $this->titleFor($client, $scope);
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        [$client, $scope] = $this->authorized($request, $as);

        $entries = (clone $scope->entries())->count();
        abort_if($entries + 1 > $this->exporter->maxRows(), 422, self::t('reports.r2.billing.too_many_rows', [
            'entries' => number_format($entries, 0, ',', '.'),
            'max' => number_format($this->exporter->maxRows() - 1, 0, ',', '.'),
        ]));

        $financials = $scope->canSeeFinancials();
        $c = fn (string $key): string => self::t('reports.r2.billing.columns.'.$key);

        $headers = [$c('date'), $c('person'), $c('project'), $c('bank'), $c('task'), $c('description'),
            $c('hours'), $c('in_bank'), $c('overage'), $c('billable'), $c('status')];
        if ($financials) {
            array_push($headers, $c('rate'), $c('amount'), $c('basis'));
        }
        // D-081: al final, los minutos (enteros) de las columnas de horas, que suman exacto su total.
        array_push($headers, $c('minutes'), $c('in_bank_minutes'), $c('overage_minutes'));

        return new ExportTable(self::t('reports.r2.billing.export_name', ['client' => $client->name]), $headers, $this->rows($scope, $financials), $this->titleFor($client, $scope));
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        [$client, $scope] = $this->authorized($request, $as);
        $financials = $scope->canSeeFinancials();
        /** @var array{rows: list<array<string, mixed>>, totals: array<string, mixed>} $summary */
        $summary = $this->summary($scope, $client);
        $totals = $summary['totals'];

        $columns = [[self::t('report_pdf.columns.project')], [self::t('report_pdf.columns.bank')], [self::t('report_pdf.columns.logged'), true],
            [self::t('report_pdf.columns.in_bank'), true], [self::t('report_pdf.columns.overage'), true], [self::t('report_pdf.columns.billable'), true],
            [self::t('report_pdf.columns.pending'), true]];
        if ($financials) {
            array_push($columns, [self::t('report_pdf.columns.pricing')], [self::t('report_pdf.columns.income'), true]);
        }

        $rows = array_map(fn (array $row): array => [
            $row['project']['code'].' · '.$row['project']['name'],
            $row['bank']['name'] ?? self::t('reports.r2.no_bank'),
            PdfFormat::minutes((int) $row['logged_minutes']),
            $row['bank'] !== null ? PdfFormat::minutes((int) $row['in_bank_minutes']) : '—',
            (int) $row['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage((int) $row['overage_minutes']), 'overage') : PdfFormat::overage(0),
            PdfFormat::minutes((int) $row['billable_minutes']),
            PdfFormat::minutes((int) $row['pending_minutes']),
            ...($financials ? [self::pricing($row), PdfFormat::money($row['income'])] : []),
        ], $summary['rows']);

        $sum = [self::t('report_pdf.total'), '', PdfFormat::minutes((int) $totals['logged_minutes']), PdfFormat::minutes((int) $totals['in_bank_minutes']),
            PdfFormat::overage((int) $totals['overage_minutes']), PdfFormat::minutes((int) $totals['billable_minutes']), PdfFormat::minutes((int) $totals['pending_minutes']),
            ...($financials ? ['', PdfFormat::money($totals['income'])] : [])];

        $kpis = [
            ['label' => self::t('report_pdf.metrics.logged.label'), 'value' => PdfFormat::minutes((int) $totals['logged_minutes']), 'detail' => self::t('report_pdf.billing.entries', ['count' => PdfFormat::number((int) $totals['entries'])])],
            ['label' => self::t('report_pdf.metrics.billable.label'), 'value' => PdfFormat::minutes((int) $totals['billable_minutes']), 'detail' => null],
            ['label' => self::t('report_pdf.metrics.overage.label'), 'value' => PdfFormat::overage((int) $totals['overage_minutes']), 'detail' => null],
            ['label' => self::t('report_pdf.columns.pending'), 'value' => PdfFormat::minutes((int) $totals['pending_minutes']), 'detail' => null],
            ...($financials ? [['label' => self::t('report_pdf.metrics.income.label'), 'value' => PdfFormat::money($totals['income']), 'detail' => null]] : []),
        ];

        return new ReportPdf(
            view: 'reports.pdf.billing',
            title: $this->titleFor($client, $scope),
            filename: self::filename(self::t('report_pdf.files.billing'), $client->name, PdfFormat::periodSlug($scope->filters)),
            data: [
                'cover' => self::cover(self::t('report_pdf.kinds.billing'), $client->name, PdfFormat::period($scope->filters),
                    self::filterFacts($scope->filters, ['cliente']), $as, $financials, ! $as->isAdmin() ? self::t('report_pdf.team_only') : null),
                'kpis' => $kpis,
                'summary' => PdfTable::make($columns, $rows, $rows === [] ? null : $sum, empty: self::t('report_pdf.no_hours')),
                'entries' => $this->entriesPdf($scope, $financials),
                'entries_more' => max((int) $totals['entries'] - self::PDF_ENTRIES, 0),
                'definitions' => self::definitionsFor($financials),
            ],
            landscape: true,
        );
    }

    /**
     * @return array{0: Client, 1: ReportScope}
     */
    private function authorized(ReportRequest $request, User $as): array
    {
        self::authorizeFor($as, 'viewBilling', Client::class);

        $filters = ReportFilters::fromQuery($request->query)->withoutComparison();
        $client = $filters->clientIds === [] ? null : Client::query()->find($filters->clientIds[0], ['id', 'name', 'is_active']);
        abort_if($client === null, 422, self::t('reports.r2.billing.client_required'));

        return [$client, $this->scopeFor($as, $request->query, ['clientIds' => [$client->id]])];
    }

    private function titleFor(Client $client, ReportScope $scope): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.billing'), $client->name, PdfFormat::period($scope->filters));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function pricing(array $row): string
    {
        $pricing = is_string($row['pricing'] ?? null) ? $row['pricing'] : null;
        $label = $pricing === null ? '' : self::t('report_pdf.billing.pricing.'.$pricing);

        return match (true) {
            is_string($row['price_amount'] ?? null) => $label.' · '.PdfFormat::money($row['price_amount']),
            is_string($row['rate'] ?? null) => $label.' · '.PdfFormat::money($row['rate']).'/h',
            default => $label,
        };
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function definitionsFor(bool $financials): array
    {
        return [
            [self::t('report_pdf.metrics.in_bank.label'), self::t('report_pdf.metrics.in_bank.definition')],
            [self::t('report_pdf.metrics.overage.label'), self::t('report_pdf.metrics.overage.definition')],
            [self::t('report_pdf.columns.pending'), self::t('report_pdf.billing.pending_definition')],
            ...($financials ? [[self::t('report_pdf.metrics.income.label'), self::t('report_pdf.metrics.income.definition')]] : []),
        ];
    }

    /**
     * Detalle de las entradas (las primeras PDF_ENTRIES), con el importe si hay datos económicos.
     *
     * @return array<string, mixed>
     */
    private function entriesPdf(ReportScope $scope, bool $financials): array
    {
        $columns = [[self::t('report_pdf.columns.date')], [self::t('report_pdf.columns.person')], [self::t('report_pdf.columns.project')],
            [self::t('report_pdf.columns.task')], [self::t('report_pdf.columns.description')], [self::t('report_pdf.columns.hours'), true],
            [self::t('report_pdf.columns.overage'), true], [self::t('report_pdf.columns.status')],
            ...($financials ? [[self::t('report_pdf.columns.amount'), true]] : [])];
        $rows = [];

        foreach ($this->billing->entries($scope) as ['entry' => $entry, 'amount' => $amount]) {
            if (count($rows) === self::PDF_ENTRIES) {
                break;
            }

            $rows[] = [
                PdfFormat::date($entry['date']),
                $entry['person'],
                $entry['project_code'].($entry['bank'] !== null ? ' · '.$entry['bank'] : ''),
                $entry['task'],
                mb_strimwidth($entry['description'], 0, 300, '…'),
                PdfFormat::minutes($entry['minutes']),
                $entry['overage_minutes'] > 0 ? PdfTable::cell(PdfFormat::overage($entry['overage_minutes']), 'overage') : '',
                $entry['status']->label().($entry['is_billable'] ? '' : ' · '.self::t('report_pdf.not_billable')),
                ...($financials ? [PdfFormat::money($amount)] : []),
            ];
        }

        return PdfTable::make($columns, $rows, compact: true, empty: self::t('report_pdf.no_hours'));
    }

    /**
     * Una fila por entrada y una de totales al final. El total del importe sale de las mismas
     * entradas que se exportan (la suma de sus importes, que es su total canónico redondeado: con
     * los mismos datos, el de la página) y sus importes en céntimos suman exactamente ese total.
     * Las horas van en decimal para leerlas y, al final, en minutos enteros, que suman exactamente
     * los totales (D-081: las horas redondeadas a 2 decimales no siempre suman su total).
     *
     * @return Generator<int, array<int, string|int|float|bool|null>>
     */
    private function rows(ReportScope $scope, bool $financials): Generator
    {
        $minutes = 0;
        $inBank = 0;
        $overage = 0;
        $amount = '0.00';
        $noBank = self::t('reports.r2.no_bank');
        /** @var array<string, string> $labels textos de estado y de valoración, traducidos una vez */
        $labels = [];

        foreach ($this->billing->entries($scope) as ['entry' => $entry, 'valuation' => $valuation, 'amount' => $line]) {
            $inside = $entry['bank'] !== null;
            $minutes += $entry['minutes'];
            $inBank += $inside ? $entry['minutes'] - $entry['overage_minutes'] : 0;
            $overage += $entry['overage_minutes'];

            $row = [
                $entry['date'],
                $entry['person'],
                $entry['project_code'].' · '.$entry['project_name'],
                $entry['bank'] ?? $noBank,
                $entry['task'],
                $entry['description'],
                TableExporter::hours($entry['minutes']),
                $inside ? TableExporter::hours($entry['minutes'] - $entry['overage_minutes']) : null,
                $inside ? TableExporter::hours($entry['overage_minutes']) : null,
                $entry['is_billable'],
                $labels['status.'.$entry['status']->value] ??= $entry['status']->label(),
            ];

            if ($financials) {
                $amount = bcadd($amount, $line ?? '0', 2);
                array_push($row,
                    TableExporter::money($valuation['rate'] ?? null),
                    TableExporter::money($line),
                    $valuation === null ? null : ($labels['basis.'.$valuation['basis']] ??= self::t('reports.r2.billing.basis.'.$valuation['basis'])),
                );
            }

            array_push($row, $entry['minutes'], $inside ? $entry['minutes'] - $entry['overage_minutes'] : null, $inside ? $entry['overage_minutes'] : null);

            yield $row;
        }

        $totals = [self::t('reports.r2.total'), '', '', '', '', '', TableExporter::hours($minutes), TableExporter::hours($inBank), TableExporter::hours($overage), null, null];
        if ($financials) {
            array_push($totals, null, TableExporter::money($amount), null);
        }
        array_push($totals, $minutes, $inBank, $overage);

        yield $totals;
    }
}
