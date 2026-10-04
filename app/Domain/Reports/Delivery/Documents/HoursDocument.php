<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Export\EntryRows;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\RevenueCalculator;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\TimeEntry;
use App\Models\User;

/**
 * Exportación de horas con los filtros globales (/informes/horas/exportar, SPEC §10, D-045): las
 * entradas del alcance de quien exporta (ReportScope, D-044), una fila por entrada (EntryRows), y
 * su PDF (el listado con sus cifras). Quién: TimeEntryPolicy::exportHours.
 */
final class HoursDocument extends BaseDocument
{
    use BuildsReportScope, EntryListPdf, PdfPieces;

    public function __construct(
        private readonly EntryRows $rows,
        private readonly RevenueCalculator $revenue,
    ) {}

    public function title(ReportRequest $request, User $as): string
    {
        return $this->titleFor($this->authorized($request, $as));
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        $scope = $this->authorized($request, $as);
        $financials = $scope->canSeeFinancials();
        $basename = self::t('reports.r3.hours.filename', [
            'from' => $scope->filters->from->toDateString(),
            'to' => $scope->filters->to->toDateString(),
        ]);

        return new ExportTable($basename, EntryRows::headers($financials), $this->rows->rows($scope->entries(), $financials), $this->titleFor($scope));
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        $scope = $this->authorized($request, $as);
        $financials = $scope->canSeeFinancials();
        $list = $this->entryList($scope->entries(), $financials, $this->revenue);

        return new ReportPdf(
            view: 'reports.pdf.hours',
            title: $this->titleFor($scope),
            filename: self::filename(self::t('report_pdf.files.hours'), PdfFormat::periodSlug($scope->filters)),
            data: [
                'cover' => self::cover(self::t('report_pdf.kinds.hours'), self::t('report_pdf.hours.title'), PdfFormat::period($scope->filters),
                    self::filterFacts($scope->filters), $as, $financials),
                'kpis' => $list['kpis'],
                'entries' => $list['entries'],
                'more' => $list['more'],
                'definitions' => self::definitions(['logged', 'billable', 'in_bank', 'overage', 'income'], $financials),
            ],
            landscape: true,
        );
    }

    private function authorized(ReportRequest $request, User $as): ReportScope
    {
        self::authorizeFor($as, 'exportHours', TimeEntry::class);

        return $this->scopeFor($as, $request->query);
    }

    private function titleFor(ReportScope $scope): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.hours'), PdfFormat::period($scope->filters));
    }
}
