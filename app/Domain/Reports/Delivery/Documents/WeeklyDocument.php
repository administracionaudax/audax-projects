<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Weeklies\AppModules;
use App\Domain\Weeklies\Report\ReportPipeline;
use App\Domain\Weeklies\Report\WeeklyClientUpdate;
use App\Domain\Weeklies\Report\WeeklyMilestone;
use App\Domain\Weeklies\Report\WeeklyProjectSnapshot;
use App\Domain\Weeklies\Report\WeeklyReport;
use App\Enums\AppModule;
use App\Enums\WeeklyClientStatus;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklySubmission;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * El informe de la weekly (/weeklies/{cycle}/informe/pdf, F-083; Fase 10, D-192): sustituye a la
 * descarga en HTML de WeeklySync (`ws:src/lib/weeklyHtmlExport.ts`) por el PDF y la impresión con
 * la hoja de documentos de Audax (D-140): portada, cifras clave, el resumen global, los riesgos y,
 * por cliente, su estado, resumen, pasos, hitos, etiquetas, satisfacción y el estado de sus
 * proyectos. En Excel y CSV, una fila por cliente. Con ?mios=1, solo los clientes de mis proyectos
 * (el filtro «Solo mis proyectos», F-080). Quién: WeeklyCyclePolicy::view.
 */
final class WeeklyDocument extends BaseDocument
{
    public const string MINE = 'mios';

    public function title(ReportRequest $request, User $as): string
    {
        $cycle = $this->authorized($request, $as);

        return self::joinTitle(self::t('weeklies.pdf.kind'), $cycle->number, $cycle->label);
    }

    public function table(ReportRequest $request, User $as): ExportTable
    {
        $cycle = $this->authorized($request, $as);
        $c = fn (string $key): string => self::t('weeklies.pdf.columns.'.$key);
        $rows = array_map(fn (WeeklyClientUpdate $update): array => [
            $update->clientName,
            $update->status->label(),
            $update->executiveSummary,
            implode("\n", $update->nextSteps),
            implode("\n", array_map(self::milestone(...), $update->milestones)),
            implode(', ', $update->tags),
            $update->satisfactionScore,
            $update->hasReports ? self::t('weeklies.pdf.yes') : self::t('weeklies.pdf.no'),
        ], $this->updates($request, $as, $cycle));

        return new ExportTable(
            self::filename('weekly', $cycle->number),
            [$c('client'), $c('status'), $c('summary'), $c('next_steps'), $c('milestones'), $c('tags'), $c('satisfaction'), $c('reports')],
            $rows,
            $this->title($request, $as),
        );
    }

    public function pdf(ReportRequest $request, User $as): ReportPdf
    {
        $cycle = $this->authorized($request, $as);
        $report = $cycle->reportData();
        $updates = $this->updates($request, $as, $cycle);
        $mine = $this->onlyMine($request);
        $submitted = WeeklySubmission::query()->submitted()->where('weekly_cycle_id', $cycle->id)->count();
        $facts = [
            [self::t('weeklies.pdf.facts.week'), PdfFormat::date($cycle->start_date->toDateString()).' – '.PdfFormat::date($cycle->end_date->toDateString())],
            [self::t('weeklies.pdf.facts.deadline'), PdfFormat::date($cycle->deadline_date->toDateString())],
            [self::t('weeklies.pdf.facts.status'), $cycle->status->label()],
            [self::t('weeklies.pdf.facts.submissions'), (string) $submitted],
        ];

        if ($mine) {
            $facts[] = [self::t('weeklies.pdf.facts.filter'), self::t('weeklies.pdf.facts.only_mine')];
        }

        $count = fn (WeeklyClientStatus $status): int => count(array_filter($updates, fn (WeeklyClientUpdate $update): bool => $update->status === $status));

        return new ReportPdf(
            view: 'reports.pdf.weekly',
            title: $this->title($request, $as),
            filename: self::filename('weekly', $cycle->number, $mine ? 'mis proyectos' : ''),
            data: [
                'cover' => self::cover(self::t('weeklies.pdf.kind'), $cycle->label, $cycle->number, $facts, $as,
                    note: $report === null ? self::t('weeklies.pdf.no_report') : null),
                'kpis' => $report === null ? [] : [
                    ['label' => self::t('weeklies.pdf.kpis.clients'), 'value' => (string) count($updates), 'detail' => null],
                    ['label' => self::t('weeklies.pdf.kpis.with_news'), 'value' => (string) count(array_filter($updates, fn (WeeklyClientUpdate $update): bool => $update->hasReports)), 'detail' => null],
                    ['label' => self::t('weeklies.pdf.kpis.risk'), 'value' => (string) $count(WeeklyClientStatus::Risk), 'detail' => null],
                    ['label' => self::t('weeklies.pdf.kpis.blocked'), 'value' => (string) $count(WeeklyClientStatus::Blocked), 'detail' => null],
                ],
                'report' => $report === null ? null : [
                    'global_summary' => $report->globalSummary,
                    'team_risks' => $report->teamRisks,
                ],
                'clients' => array_map(fn (WeeklyClientUpdate $update): array => [
                    'name' => $update->clientName,
                    'status' => $update->status->value,
                    'status_label' => $update->status->label(),
                    'summary' => $update->executiveSummary,
                    'next_steps' => $update->nextSteps,
                    'milestones' => array_map(self::milestone(...), $update->milestones),
                    'tags' => $update->tags,
                    'satisfaction' => $update->satisfactionScore,
                    'projects' => $update->projects === [] ? null : PdfTable::make(
                        [[self::t('weeklies.pdf.projects.code')], [self::t('weeklies.pdf.projects.name')], [self::t('weeklies.pdf.projects.kind')],
                            [self::t('weeklies.pdf.projects.consumed'), true], [self::t('weeklies.pdf.projects.budget'), true], [self::t('weeklies.pdf.projects.expected'), true], [self::t('weeklies.pdf.projects.week'), true]],
                        array_map(fn (WeeklyProjectSnapshot $project): array => [
                            $project->code,
                            $project->name,
                            ReportPipeline::projectTag($project->billingType),
                            $project->budgetMinutes !== null && $project->consumedMinutes > $project->budgetMinutes
                                ? PdfTable::cell(PdfFormat::minutes($project->consumedMinutes), 'overage')
                                : PdfFormat::minutes($project->consumedMinutes),
                            $project->budgetMinutes === null ? '—' : PdfFormat::minutes($project->budgetMinutes),
                            $project->expectedMinutes === null ? '—' : PdfFormat::minutes($project->expectedMinutes),
                            PdfFormat::minutes($project->weekMinutes),
                        ], $update->projects),
                        compact: true,
                    ),
                ], $updates),
                'mine_empty' => $mine && $report !== null && $updates === [],
                'definitions' => [],
            ],
        );
    }

    /** «09/10: Entrega del diseño», o solo el texto si no tiene fecha. */
    public static function milestone(WeeklyMilestone $milestone): string
    {
        return $milestone->date !== null ? "{$milestone->date}: {$milestone->label}" : $milestone->label;
    }

    /**
     * Los clientes del informe, con el filtro «Solo mis proyectos» si se pide.
     *
     * @return list<WeeklyClientUpdate>
     */
    private function updates(ReportRequest $request, User $as, WeeklyCycle $cycle): array
    {
        $report = $cycle->reportData() ?? new WeeklyReport('');

        if (! $this->onlyMine($request)) {
            return $report->clientUpdates;
        }

        $mine = $as->projects()->whereNotNull('client_id')->pluck('client_id')->map(fn ($id): int => (int) $id)->flip()->all();

        return array_values(array_filter($report->clientUpdates, fn (WeeklyClientUpdate $update): bool => $update->clientId !== null && isset($mine[$update->clientId])));
    }

    private function onlyMine(ReportRequest $request): bool
    {
        return in_array($request->query[self::MINE] ?? null, ['1', 'true', 1, true], true);
    }

    private function authorized(ReportRequest $request, User $as): WeeklyCycle
    {
        if (! AppModules::enabled(AppModule::Weeklies)) {
            throw new AuthorizationException(self::t('report_pdf.errors.missing'));
        }

        $cycle = self::routeModel($request, 'cycle', WeeklyCycle::class);
        self::authorizeFor($as, 'view', $cycle);

        return $cycle;
    }
}
