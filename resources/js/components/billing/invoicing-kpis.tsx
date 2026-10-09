import { ArrowDown, ArrowUp, Minus } from 'lucide-react';
import { KpiCard } from '@/components/reports/kpi-card';
import { formatCurrency, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type { InvoicingReport } from '@/types';
import { KpiGroup } from './kpi-group';
import { amount, compactCurrency, signedPercent } from './invoicing-lib';

/** Año de una fecha AAAA-MM-DD, para «frente a 2025». */
function year(date: string): string {
    return date.slice(0, 4);
}

/**
 * Variación frente al mismo periodo del año anterior, escrita siempre igual (D-410): flecha y
 * «+120,3 % frente a 2025» (nunca solo color). Sin datos del año anterior, nada.
 */
export function YearDelta({
    current,
    previous,
    year,
}: {
    current: number | null;
    previous: number | null;
    year: string;
}) {
    if (current === null || previous === null || previous === 0) {
        return null;
    }

    const pct = ((current - previous) / Math.abs(previous)) * 100;
    const Icon = pct > 0.05 ? ArrowUp : pct < -0.05 ? ArrowDown : Minus;

    return (
        <p className="inline-flex items-center gap-1 text-xs text-foreground">
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5',
                    pct > 0.05
                        ? 'text-success'
                        : pct < -0.05
                          ? 'text-danger'
                          : 'text-muted-foreground',
                )}
            />
            {t('billing.invoicing.kpis.versus', {
                delta: signedPercent(Math.round(pct * 10) / 10),
                year,
            })}
        </p>
    );
}

/**
 * Cifras del informe de ventas (D-400 y D-410), en dos grupos rotulados una vez: «Facturación (sin
 * IVA)» con lo facturado (y su variación frente al año anterior), lo previsto, el número de
 * facturas y el ticket medio, y «Cobros (con IVA)» con lo cobrado, lo pendiente y lo vencido. Cada
 * cifra con su definición; los importes, sin céntimos (el detalle, en las tablas y en el Excel).
 */
export function InvoicingKpis({ report }: { report: InvoicingReport }) {
    const k = report.kpis;
    const previousYear = year(report.previous_from);
    const overdueCount = report.aging
        .filter((bucket) => bucket.key !== 'current')
        .reduce((sum, bucket) => sum + bucket.count, 0);
    const creditNotes = amount(k.credit_notes);

    return (
        <div
            role="group"
            aria-label={t('billing.invoicing.kpis.label')}
            className="grid gap-5"
            data-test="invoicing-kpis"
        >
            <KpiGroup
                title={t('billing.invoicing.kpis.group_invoiced')}
                columns="grid-cols-2 lg:grid-cols-4"
            >
                <KpiCard
                    label={t('billing.invoicing.kpis.invoiced')}
                    definition={t('billing.invoicing.definitions.invoiced')}
                    value={compactCurrency(k.invoiced)}
                    detail={
                        creditNotes !== 0
                            ? t('billing.invoicing.kpis.credit_notes', {
                                  amount: formatCurrency(k.credit_notes),
                              })
                            : undefined
                    }
                >
                    {k.variation_pct === null ? (
                        <p className="text-xs text-muted-foreground">
                            {t('billing.invoicing.kpis.no_previous_year', {
                                year: previousYear,
                            })}
                        </p>
                    ) : (
                        <YearDelta
                            current={amount(k.invoiced)}
                            previous={amount(k.previous_invoiced)}
                            year={previousYear}
                        />
                    )}
                </KpiCard>
                <KpiCard
                    label={t('billing.invoicing.kpis.planned')}
                    definition={t('billing.invoicing.definitions.planned')}
                    value={compactCurrency(k.planned)}
                    detail={tCount(
                        'billing.invoicing.kpis.drafts',
                        k.planned_count,
                    )}
                />
                <KpiCard
                    label={t('billing.invoicing.kpis.count')}
                    definition={t('billing.invoicing.definitions.count')}
                    value={formatNumber(k.count, 0)}
                >
                    {report.compare ? (
                        <YearDelta
                            current={k.count}
                            previous={k.previous_count}
                            year={previousYear}
                        />
                    ) : null}
                </KpiCard>
                <KpiCard
                    label={t('billing.invoicing.kpis.average')}
                    definition={t('billing.invoicing.definitions.average')}
                    value={
                        k.average === null ? null : compactCurrency(k.average)
                    }
                >
                    {report.compare && k.average !== null ? (
                        <YearDelta
                            current={amount(k.average)}
                            previous={
                                k.previous_average === null
                                    ? null
                                    : amount(k.previous_average)
                            }
                            year={previousYear}
                        />
                    ) : null}
                </KpiCard>
            </KpiGroup>
            <KpiGroup
                title={t('billing.invoicing.kpis.group_collected')}
                columns="grid-cols-2 lg:grid-cols-3"
            >
                <KpiCard
                    label={t('billing.invoicing.kpis.collected')}
                    definition={t('billing.invoicing.definitions.collected')}
                    value={compactCurrency(k.collected)}
                />
                <KpiCard
                    label={t('billing.invoicing.kpis.outstanding')}
                    definition={t('billing.invoicing.definitions.outstanding')}
                    value={compactCurrency(k.outstanding)}
                />
                <KpiCard
                    className="col-span-2 lg:col-span-1"
                    label={t('billing.invoicing.kpis.overdue')}
                    definition={t('billing.invoicing.definitions.overdue')}
                    value={compactCurrency(k.overdue)}
                    detail={
                        overdueCount > 0
                            ? tCount(
                                  'billing.invoicing.overdue_count',
                                  overdueCount,
                              )
                            : t('billing.invoicing.kpis.nothing_overdue')
                    }
                />
            </KpiGroup>
        </div>
    );
}
