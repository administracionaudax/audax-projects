<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Export\EntryRows;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\RevenueCalculator;
use App\Enums\TimeEntryStatus;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Horas de la pestaña Horas de un proyecto (/proyectos/{project}/horas/exportar, D-021 y D-045):
 * con los filtros de la pestaña (persona, desde, hasta, bolsa, estado, facturable); quien no puede
 * ver todas las horas del proyecto solo saca las suyas. Excel, CSV (EntryRows) y PDF.
 */
final class ProjectHoursDocument extends BaseDocument
{
    use EntryListPdf, PdfPieces;

    /** Límites de fecha de la pestaña Horas sin «desde» o «hasta»: todas sus entradas. */
    public const string OPEN_FROM = '2000-01-01';

    public const string OPEN_TO = '2100-12-31';

    public function __construct(
        private readonly EntryRows $rows,
        private readonly RevenueCalculator $revenue,
    ) {}

    public function title(ReportRequest $request, User $as): string
    {
        [$project, , , $tab] = $this->authorized($request, $as);

        return $this->titleFor($project, $tab);
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        [$project, $scope, $entries, $tab] = $this->authorized($request, $as);
        $financials = $scope->canSeeFinancials();

        return new ExportTable(self::t('reports.r3.hours.project_filename', ['code' => $project->code]), EntryRows::headers($financials),
            $this->rows->rows($entries, $financials), $this->titleFor($project, $tab));
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        [$project, $scope, $entries, $tab] = $this->authorized($request, $as);
        $financials = $scope->canSeeFinancials();
        $list = $this->entryList($entries, $financials, $this->revenue);

        $facts = [[self::t('report_pdf.cover.period'), self::range($tab)]];
        if ($scope->filters->userIds !== []) {
            $facts[] = [self::t('report_pdf.filters.persona'), (string) User::query()->whereKey($scope->filters->userIds)->value('name')];
        }
        if ($tab['bolsa'] !== null) {
            $facts[] = [self::t('report_pdf.filters.bolsa'), (string) $project->hourBanks()->withoutGlobalScopes()->whereKey($tab['bolsa'])->value('name')];
        }
        if ($tab['estado'] !== null) {
            $facts[] = [self::t('report_pdf.filters.estado'), TimeEntryStatus::from($tab['estado'])->label()];
        }
        if ($tab['facturable'] !== null) {
            $facts[] = [self::t('report_pdf.filters.facturable'), self::t($tab['facturable'] ? 'report_pdf.filters.billable_yes' : 'report_pdf.filters.billable_no')];
        }

        return new ReportPdf(
            view: 'reports.pdf.hours',
            title: $this->titleFor($project, $tab),
            filename: self::filename(self::t('report_pdf.files.project_hours'), $project->code, $tab['desde'] ?? '', $tab['hasta'] ?? ''),
            data: [
                'cover' => self::cover(self::t('report_pdf.kinds.project_hours'), $project->code.' · '.$project->name, self::range($tab), $facts, $as, $financials,
                    Gate::forUser($as)->allows('viewAllTime', $project) ? null : self::t('report_pdf.project_hours.own_only')),
                'kpis' => $list['kpis'],
                'entries' => $list['entries'],
                'more' => $list['more'],
                'definitions' => self::definitions(['logged', 'billable', 'in_bank', 'overage', 'income'], $financials),
            ],
            landscape: true,
        );
    }

    /**
     * @return array{0: Project, 1: ReportScope, 2: Builder<TimeEntry>, 3: array{persona: int|null, desde: string|null, hasta: string|null, bolsa: int|null, estado: string|null, facturable: bool|null}}
     */
    private function authorized(ReportRequest $request, User $as): array
    {
        $project = self::routeModel($request, 'project', Project::class);
        self::authorizeFor($as, 'view', $project);

        $viewAll = Gate::forUser($as)->allows('viewAllTime', $project);
        $tab = self::tabFilters($request->query);

        $filters = new ReportFilters(
            period: ReportPeriod::Range,
            from: CarbonImmutable::parse($tab['desde'] ?? self::OPEN_FROM),
            to: CarbonImmutable::parse($tab['hasta'] ?? self::OPEN_TO),
            // Sin permiso para ver todas las horas del proyecto, solo las suyas (D-021).
            userIds: $viewAll ? ($tab['persona'] !== null ? [$tab['persona']] : []) : [$as->id],
            projectIds: [$project->id],
            bankIds: $tab['bolsa'] !== null ? [$tab['bolsa']] : [],
            billable: $tab['facturable'],
        );
        $scope = new ReportScope($as, $filters);

        $entries = $scope->entries()
            ->when($tab['estado'] !== null, fn (Builder $query) => $query->where('time_entries.status', $tab['estado']));

        return [$project, $scope, $entries, $tab];
    }

    /**
     * @param  array{desde: string|null, hasta: string|null}  $tab
     */
    private function titleFor(Project $project, array $tab): string
    {
        return self::joinTitle(self::t('report_pdf.kinds.project_hours'), $project->code, self::range($tab));
    }

    /**
     * @param  array{desde: string|null, hasta: string|null}  $tab
     */
    private static function range(array $tab): string
    {
        return match (true) {
            $tab['desde'] !== null && $tab['hasta'] !== null => self::t('report_pdf.period.range', ['from' => PdfFormat::date($tab['desde']), 'to' => PdfFormat::date($tab['hasta'])]),
            $tab['desde'] !== null => self::t('report_pdf.period.since', ['from' => PdfFormat::date($tab['desde'])]),
            $tab['hasta'] !== null => self::t('report_pdf.period.until', ['to' => PdfFormat::date($tab['hasta'])]),
            default => self::t('report_pdf.period.all'),
        };
    }

    /**
     * Filtros de la pestaña Horas del proyecto (los mismos que ProjectTimeController). Lo que no se
     * entiende se ignora.
     *
     * @param  array<string, mixed>  $query
     * @return array{persona: int|null, desde: string|null, hasta: string|null, bolsa: int|null, estado: string|null, facturable: bool|null}
     */
    public static function tabFilters(array $query): array
    {
        $id = function (string $key) use ($query): ?int {
            $value = $query[$key] ?? null;

            return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
        };

        $date = function (string $key) use ($query): ?string {
            $value = $query[$key] ?? null;

            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                && CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d') ? $value : null;
        };

        $status = $query['estado'] ?? null;
        $billable = $query['facturable'] ?? null;

        return [
            'persona' => $id('persona'),
            'desde' => $date('desde'),
            'hasta' => $date('hasta'),
            'bolsa' => $id('bolsa'),
            'estado' => is_string($status) && in_array($status, TimeEntryStatus::values(), true) ? $status : null,
            'facturable' => match ($billable) {
                'si' => true,
                'no' => false,
                default => null,
            },
        ];
    }
}
