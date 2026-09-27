import { KpiCard } from '@/components/reports/kpi-card';
import type { KpiDelta } from '@/components/reports/kpi-card';
import { formatOverage, toCents } from '@/components/reports/r2-helpers';
import { formatCurrency, formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { MetricsSummary } from '@/types';

/**
 * KPIs de los informes de cliente y de proyecto (SPEC §10): imputadas, facturables (con la
 * facturabilidad), exceso (en rojo si lo hay), precisión de estimación (proyecto) y, con
 * view-financials, ingreso, coste y rentabilidad. Cada tarjeta lleva su definición (claves
 * reports.metric.*) y, si se compara, la variación frente al periodo anterior.
 */
export function R2SummaryKpis({
    summary,
    comparison,
    financials,
    inBankMinutes,
    estimation = false,
}: {
    summary: MetricsSummary;
    comparison: MetricsSummary | null;
    financials: boolean;
    /**
     * Horas dentro de las bolsas (detalle de la tarjeta de exceso), o null si no hay horas en
     * bolsas. No es summary.in_bank_minutes, que también cuenta las horas sin bolsa.
     */
    inBankMinutes: number | null;
    /** Tarjeta de precisión de estimación (informe de proyecto). */
    estimation?: boolean;
}) {
    const delta = (
        current: number | null,
        previous: number | null,
        higherIsBetter = true,
    ): KpiDelta | undefined =>
        comparison ? { current, previous, higherIsBetter } : undefined;
    const money = (value: string | null): number | null =>
        value === null ? null : toCents(value) / 100;
    const accuracy = summary.estimation.accuracy;
    const deviationRatio = summary.estimation.deviation;
    const showMoney = financials && summary.income !== null;
    const cards = 3 + (estimation ? 1 : 0) + (showMoney ? 3 : 0);

    return (
        <section
            aria-label={t('reports_r2.kpi.label')}
            className={cn(
                'grid gap-3 sm:grid-cols-2',
                {
                    3: 'lg:grid-cols-3',
                    4: 'lg:grid-cols-4',
                    6: 'lg:grid-cols-3 2xl:grid-cols-6',
                    7: 'lg:grid-cols-4',
                }[cards],
            )}
        >
            <KpiCard
                label={t('reports.metric.logged.label')}
                definition={t('reports.metric.logged.definition')}
                value={formatMinutes(summary.logged_minutes)}
                delta={delta(
                    summary.logged_minutes,
                    comparison?.logged_minutes ?? null,
                )}
            />
            <KpiCard
                label={t('reports.metric.billable.label')}
                definition={t('reports.metric.billable.definition')}
                value={formatMinutes(summary.billable_minutes)}
                detail={
                    summary.billability === null
                        ? undefined
                        : t('reports_r2.kpi.billability', {
                              pct: formatPercent(summary.billability),
                          })
                }
                delta={delta(
                    summary.billable_minutes,
                    comparison?.billable_minutes ?? null,
                )}
            />
            <KpiCard
                label={t('reports.metric.overage.label')}
                definition={t('reports.metric.overage.definition')}
                value={formatOverage(summary.overage_minutes)}
                detail={
                    inBankMinutes === null
                        ? undefined
                        : t('reports_r2.kpi.in_bank', {
                              hours: formatMinutes(inBankMinutes),
                          })
                }
                delta={delta(
                    summary.overage_minutes,
                    comparison?.overage_minutes ?? null,
                    false,
                )}
                // El exceso siempre en rojo (SPEC §8.6); la etiqueta ya dice qué es.
                className={cn(
                    summary.overage_minutes > 0 &&
                        '[&_[data-slot=card-content]>p:first-child]:text-danger',
                )}
            />
            {estimation ? (
                <KpiCard
                    label={t('reports.metric.estimation.label')}
                    definition={t('reports.metric.estimation.definition')}
                    value={accuracy === null ? null : formatPercent(accuracy)}
                    detail={
                        deviationRatio === null
                            ? t('reports_r2.kpi.estimation_none')
                            : t('reports_r2.kpi.estimation_detail', {
                                  deviation: `${deviationRatio > 0 ? '+' : ''}${formatPercent(deviationRatio)}`,
                                  tasks: summary.estimation.tasks,
                              })
                    }
                />
            ) : null}
            {showMoney ? (
                <>
                    <KpiCard
                        label={t('reports.metric.income.label')}
                        definition={t('reports.metric.income.definition')}
                        value={formatCurrency(summary.income)}
                        delta={delta(
                            money(summary.income),
                            money(comparison?.income ?? null),
                        )}
                    />
                    <KpiCard
                        label={t('reports.metric.cost.label')}
                        definition={t('reports.metric.cost.definition')}
                        value={formatCurrency(summary.cost)}
                        delta={delta(
                            money(summary.cost),
                            money(comparison?.cost ?? null),
                            false,
                        )}
                    />
                    <KpiCard
                        label={t('reports.metric.margin.label')}
                        definition={t('reports.metric.margin.definition')}
                        value={formatCurrency(summary.margin)}
                        detail={
                            summary.margin_pct === null
                                ? undefined
                                : t('reports_r2.kpi.margin_pct', {
                                      pct: formatPercent(summary.margin_pct),
                                  })
                        }
                        delta={delta(
                            money(summary.margin),
                            money(comparison?.margin ?? null),
                        )}
                    />
                </>
            ) : null}
        </section>
    );
}
