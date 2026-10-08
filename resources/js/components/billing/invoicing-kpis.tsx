import { ArrowDown, ArrowUp, CircleAlert, Minus } from 'lucide-react';
import { KpiCard } from '@/components/reports/kpi-card';
import { formatCurrency, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { InvoicingReport } from '@/types';
import { amount, compactCurrency, signedPercent } from './invoicing-lib';

/** Año de una fecha AAAA-MM-DD, para «frente a 2025». */
function year(date: string): string {
    return date.slice(0, 4);
}

/**
 * Variación frente al mismo periodo del año anterior, con flecha y texto (nunca solo color).
 */
function VariationValue({ pct }: { pct: string | null }) {
    if (pct === null) {
        return (
            <span className="text-base text-muted-foreground">
                {t('billing.invoicing.kpis.no_previous')}
            </span>
        );
    }

    const value = Number(pct);
    const Icon = value > 0 ? ArrowUp : value < 0 ? ArrowDown : Minus;

    return (
        <span className="inline-flex items-center gap-1.5">
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-5',
                    value > 0
                        ? 'text-success'
                        : value < 0
                          ? 'text-danger'
                          : 'text-muted-foreground',
                )}
            />
            {signedPercent(pct)}
        </span>
    );
}

/**
 * Cifras del informe de facturación (D-400): facturado, variación frente al año anterior, cobrado,
 * pendiente, vencido, previsto, número de facturas y ticket medio, cada una con su definición. Los
 * importes van sin céntimos (el detalle, en las tablas y en el Excel); cobrado y pendiente, con IVA.
 */
export function InvoicingKpis({ report }: { report: InvoicingReport }) {
    const k = report.kpis;
    const previousYear = year(report.previous_from);
    const overdueCount = report.aging
        .filter((bucket) => bucket.key !== 'current')
        .reduce((sum, bucket) => sum + bucket.count, 0);
    const creditNotes = amount(k.credit_notes);

    return (
        <section
            aria-label={t('billing.invoicing.kpis.label')}
            className="grid grid-cols-2 gap-3 lg:grid-cols-4"
            data-test="invoicing-kpis"
        >
            <KpiCard
                className="col-span-2 lg:col-span-1"
                label={t('billing.invoicing.kpis.invoiced')}
                definition={t('billing.invoicing.definitions.invoiced')}
                value={compactCurrency(k.invoiced)}
                detail={
                    creditNotes !== 0
                        ? t('billing.invoicing.kpis.credit_notes', {
                              amount: formatCurrency(k.credit_notes),
                          })
                        : t('billing.invoicing.kpis.without_vat')
                }
            />
            <KpiCard
                className="col-span-2 lg:col-span-1"
                label={t('billing.invoicing.kpis.variation', {
                    year: previousYear,
                })}
                definition={t('billing.invoicing.definitions.variation')}
                value={<VariationValue pct={k.variation_pct} />}
                detail={t('billing.invoicing.kpis.previous', {
                    year: previousYear,
                    amount: compactCurrency(k.previous_invoiced),
                })}
            />
            <KpiCard
                label={t('billing.invoicing.kpis.collected')}
                definition={t('billing.invoicing.definitions.collected')}
                value={compactCurrency(k.collected)}
                detail={t('billing.invoicing.kpis.with_vat')}
            />
            <KpiCard
                label={t('billing.invoicing.kpis.outstanding')}
                definition={t('billing.invoicing.definitions.outstanding')}
                value={compactCurrency(k.outstanding)}
                detail={t('billing.invoicing.kpis.with_vat')}
            />
            <KpiCard
                label={t('billing.invoicing.kpis.overdue')}
                definition={t('billing.invoicing.definitions.overdue')}
                value={compactCurrency(k.overdue)}
                detail={
                    overdueCount > 0
                        ? t(
                              overdueCount === 1
                                  ? 'billing.invoicing.overdue_count_one'
                                  : 'billing.invoicing.overdue_count_other',
                              { count: overdueCount },
                          )
                        : t('billing.invoicing.kpis.nothing_overdue')
                }
            >
                {overdueCount > 0 ? (
                    <p className="inline-flex items-center gap-1 text-xs text-foreground">
                        <CircleAlert
                            aria-hidden="true"
                            className="size-3.5 text-danger"
                        />
                        {t('billing.invoicing.kpis.overdue_hint')}
                    </p>
                ) : null}
            </KpiCard>
            <KpiCard
                label={t('billing.invoicing.kpis.planned')}
                definition={t('billing.invoicing.definitions.planned')}
                value={compactCurrency(k.planned)}
                detail={t(
                    k.planned_count === 1
                        ? 'billing.invoicing.kpis.drafts_one'
                        : 'billing.invoicing.kpis.drafts_other',
                    { count: k.planned_count },
                )}
            />
            <KpiCard
                label={t('billing.invoicing.kpis.count')}
                definition={t('billing.invoicing.definitions.count')}
                value={formatNumber(k.count, 0)}
                delta={
                    report.compare && k.previous_count !== null
                        ? {
                              current: k.count,
                              previous: k.previous_count,
                              versus: 'previous_year',
                          }
                        : undefined
                }
            />
            <KpiCard
                label={t('billing.invoicing.kpis.average')}
                definition={t('billing.invoicing.definitions.average')}
                value={k.average === null ? null : compactCurrency(k.average)}
                delta={
                    report.compare && k.average !== null
                        ? {
                              current: amount(k.average),
                              previous:
                                  k.previous_average === null
                                      ? null
                                      : amount(k.previous_average),
                              versus: 'previous_year',
                          }
                        : undefined
                }
            />
        </section>
    );
}
