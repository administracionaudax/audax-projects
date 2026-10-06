import { Head, router } from '@inertiajs/react';
import { isReportPageVisit } from '@/components/reports/r1-report-state';
import { Info, SearchX, WifiOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/projects-list/page-header';
import { ExportMenu } from '@/components/reports/export-menu';
import { KpiCard } from '@/components/reports/kpi-card';
import { PivotControls } from '@/components/reports/r3-pivot-controls';
import { PivotTable } from '@/components/reports/r3-pivot-table';
import type {
    DetailLayout,
    DetailPageProps,
    DetailSummary,
} from '@/components/reports/r3-types';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { detail, index as reportsIndex } from '@/routes/reports';

/**
 * Informe de horas detallado (SPEC §10.6): tabla dinámica por dos dimensiones con subtotales,
 * para cualquier interno con su alcance (D-044). Todo va en la URL: los filtros globales y las
 * elecciones de la tabla (filas=, columnas=, medida=). Exporta la tabla tal cual y las entradas.
 */
export default function ReportDetail({
    filters,
    layout,
    dimensions,
    measures,
    filterKeys,
    pivot,
    summary,
    comparison,
    report_request: reportRequest,
}: DetailPageProps) {
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const seesOthers = dimensions.includes('persona');
    const path = detail.url();

    // Estados de carga y de error de cualquier visita a esta página (barra de filtros o tabla).
    useEffect(() => {
        let pending: string | null = null;
        const offStart = router.on('start', (event) => {
            if (isReportPageVisit(event.detail.visit, path)) {
                pending = event.detail.visit.id;
                setLoading(true);
                setFailed(false);
            }
        });
        const offFinish = router.on('finish', (event) => {
            if (event.detail.visit.id === pending) {
                pending = null;
                setLoading(false);
            }
        });
        // Solo si falla la visita de este informe (no cualquier petición de la app).
        const offNetwork = router.on('networkError', () => {
            if (pending !== null) {
                setFailed(true);
            }
        });

        return () => {
            offStart();
            offFinish();
            offNetwork();
        };
    }, [path]);

    const changeLayout = (next: DetailLayout) =>
        router.get(
            path,
            { ...filters.query, ...next },
            { preserveState: true, preserveScroll: true },
        );

    const measureLabel = t(`reports_r3.measure.${layout.medida}`);
    const caption = t('reports_r3.table.label', {
        measure: measureLabel,
        rows: t(`reports_r3.dimension.${layout.filas}`),
        columns: t(`reports_r3.dimension.${layout.columnas}`),
    });
    const hasFilters = filterKeys.some((key) =>
        key === 'facturable'
            ? filters.query.facturable !== undefined
            : (filters.query[key]?.length ?? 0) > 0,
    );

    return (
        <>
            <Head title={t('reports_r3.detail.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('reports_r3.detail.heading')}
                    description={t('reports_r3.detail.description')}
                    actions={
                        <>
                            <ExportMenu
                                request={reportRequest}
                                title={t('reports_r3.detail.title')}
                                label={t('reports_r3.export.table')}
                            />
                            <ExportMenu
                                request={{
                                    kind: 'hours',
                                    route_params: {},
                                    query: filters.query,
                                }}
                                title={t('reports_r3.export.entries')}
                                label={t('reports_r3.export.entries')}
                            />
                        </>
                    }
                />

                {!seesOthers ? (
                    <Alert>
                        <Info aria-hidden="true" />
                        <AlertDescription>
                            {t('reports_r3.detail.scope_mine')}
                        </AlertDescription>
                    </Alert>
                ) : null}

                <ReportFilterBar
                    filters={filters}
                    url={path}
                    show={filterKeys}
                />

                <section aria-label={t('reports_r3.detail.summary')}>
                    <SummaryCards summary={summary} comparison={comparison} />
                </section>

                <PivotControls
                    layout={layout}
                    dimensions={dimensions}
                    measures={measures}
                    disabled={loading}
                    onChange={changeLayout}
                />

                {failed ? (
                    <Alert variant="destructive">
                        <WifiOff aria-hidden="true" />
                        <AlertDescription className="flex flex-wrap items-center gap-3">
                            {t('reports_r3.detail.network_error')}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setFailed(false);
                                    router.reload();
                                }}
                            >
                                {t('reports_r3.detail.retry')}
                            </Button>
                        </AlertDescription>
                    </Alert>
                ) : null}

                <div
                    aria-busy={loading}
                    className={loading ? 'opacity-60 transition-opacity' : ''}
                >
                    {loading ? (
                        <p
                            role="status"
                            className="mb-2 flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            {/* El texto ya lo anuncia: sin un segundo «status» anidado. */}
                            <Spinner role="presentation" aria-hidden="true" />
                            {t('reports_r3.detail.loading')}
                        </p>
                    ) : null}

                    {pivot.rows.length === 0 ? (
                        <EmptyState
                            icon={SearchX}
                            title={t(
                                layout.medida === 'imputadas'
                                    ? 'reports_r3.detail.empty_title'
                                    : `reports_r3.detail.empty_title_${layout.medida}`,
                            )}
                            description={
                                hasFilters || layout.medida !== 'imputadas'
                                    ? t('reports_r3.detail.empty_description')
                                    : undefined
                            }
                        />
                    ) : (
                        <PivotTable
                            pivot={pivot}
                            rowsDimension={layout.filas}
                            columnsDimension={layout.columnas}
                            caption={caption}
                        />
                    )}
                </div>
            </div>
        </>
    );
}

ReportDetail.layout = {
    breadcrumbs: [
        { title: t('nav.reports'), href: reportsIndex() },
        { title: t('reports_r3.detail.title'), href: detail() },
    ],
};

function SummaryCards({
    summary,
    comparison,
}: {
    summary: DetailSummary;
    comparison: DetailSummary | null;
}) {
    const delta = (
        pick: (value: DetailSummary) => number | null,
        higherIsBetter = true,
    ) =>
        comparison
            ? {
                  current: pick(summary),
                  previous: pick(comparison),
                  higherIsBetter,
              }
            : undefined;

    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
                label={t('reports.metric.logged.label')}
                definition={t('reports.metric.logged.definition')}
                value={formatMinutes(summary.logged_minutes)}
                delta={delta((value) => value.logged_minutes)}
            />
            <KpiCard
                label={t('reports.metric.billable.label')}
                definition={t('reports.metric.billable.definition')}
                value={formatMinutes(summary.billable_minutes)}
                delta={delta((value) => value.billable_minutes)}
            />
            <KpiCard
                label={t('reports.metric.billability.label')}
                definition={t('reports.metric.billability.definition')}
                value={
                    summary.billability === null
                        ? null
                        : formatPercent(summary.billability)
                }
                delta={delta((value) => value.billability)}
            />
            <KpiCard
                label={t('reports.metric.overage.label')}
                definition={t('reports.metric.overage.definition')}
                value={formatMinutes(summary.overage_minutes)}
                detail={t('reports_r3.detail.in_bank_detail', {
                    hours: formatMinutes(summary.in_bank_minutes),
                })}
                delta={delta((value) => value.overage_minutes, false)}
            />
        </div>
    );
}
