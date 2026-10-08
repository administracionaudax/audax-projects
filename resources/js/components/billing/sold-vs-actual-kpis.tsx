import { CircleAlert, CircleCheck, TriangleAlert } from 'lucide-react';
import { KpiCard } from '@/components/reports/kpi-card';
import { formatCurrency, formatMinutes, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SoldVsActualTotals } from '@/types';
import { amount, signedMinutes } from './sold-vs-actual-lib';

const TRACK = 'color-mix(in oklab, var(--chart-1) 16%, var(--card))';

/**
 * Medidor de una proporción (dataviz: meter): el relleno lleva el dato y la pista es un paso más
 * claro de la misma rampa; lo que pasa del 100 % va en rojo de estado, tras un hueco de 2 px.
 */
export function Meter({
    value,
    max,
    label,
    overColor = 'var(--danger)',
    className,
}: {
    value: number;
    max: number;
    label: string;
    overColor?: string;
    className?: string;
}) {
    const scale = Math.max(value, max, 1);
    const inside = (Math.min(value, max) / scale) * 100;
    const over = (Math.max(0, value - max) / scale) * 100;

    return (
        <div
            role="meter"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={max}
            aria-valuenow={value}
            className={cn('relative h-2 overflow-hidden rounded-md', className)}
            style={{ backgroundColor: TRACK }}
        >
            <span
                aria-hidden="true"
                className="absolute inset-y-0 left-0"
                style={{
                    width: `${inside}%`,
                    backgroundColor: 'var(--chart-1)',
                }}
            />
            {over > 0 ? (
                <span
                    aria-hidden="true"
                    className="absolute inset-y-0 border-l-2 border-card"
                    style={{
                        left: `${inside}%`,
                        width: `${over}%`,
                        backgroundColor: overColor,
                    }}
                />
            ) : null}
        </div>
    );
}

/**
 * Cifras de «Vendido frente a real» (D-390): consumo de lo vendido (con su medidor), desviación y
 * unidades por estado; con view-financials, lo vendido, lo facturado (y lo pendiente de facturar),
 * el cobro (cobrado, pendiente y vencido) y el margen.
 */
export function SoldVsActualKpis({
    totals,
    financials,
}: {
    totals: SoldVsActualTotals;
    financials: boolean;
}) {
    const pct = totals.consumption_pct;
    const invoicedTotal = amount(totals.collected) + amount(totals.outstanding);

    return (
        <section
            aria-label={t('billing.kpis.label')}
            className={cn(
                'grid gap-3 sm:grid-cols-2',
                financials ? 'xl:grid-cols-4' : 'lg:grid-cols-3',
            )}
            data-test="sold-vs-actual-kpis"
        >
            <KpiCard
                label={t('billing.kpis.consumption')}
                definition={t('billing.kpis.consumption_definition')}
                value={pct === null ? null : `${formatNumber(pct, 1)} %`}
                detail={t('billing.kpis.consumption_detail', {
                    real: formatMinutes(totals.real_of_sold_minutes),
                    sold: formatMinutes(totals.sold_minutes),
                })}
            >
                {totals.sold_minutes > 0 ? (
                    <Meter
                        value={totals.real_of_sold_minutes}
                        max={totals.sold_minutes}
                        label={t('billing.kpis.consumption')}
                        className="mt-1"
                    />
                ) : null}
            </KpiCard>
            <KpiCard
                label={t('billing.kpis.deviation')}
                definition={t('billing.kpis.deviation_definition')}
                value={signedMinutes(totals.deviation_minutes, formatMinutes)}
                detail={
                    totals.pending_minutes > 0
                        ? t('billing.kpis.pending_detail', {
                              hours: formatMinutes(totals.pending_minutes),
                          })
                        : t(
                              totals.deviation_minutes > 0
                                  ? 'billing.kpis.deviation_over'
                                  : 'billing.kpis.deviation_under',
                          )
                }
            />
            <KpiCard
                label={t('billing.kpis.units')}
                definition={t('billing.kpis.units_definition')}
                value={formatNumber(totals.units)}
                detail={[
                    totals.by_status.over > 0
                        ? t('billing.kpis.units_over', {
                              count: totals.by_status.over,
                          })
                        : null,
                    totals.by_status.risk > 0
                        ? t('billing.kpis.units_risk', {
                              count: totals.by_status.risk,
                          })
                        : null,
                    t('billing.kpis.units_ok', { count: totals.by_status.ok }),
                ]
                    .filter(Boolean)
                    .join(' · ')}
                className={cn(
                    totals.by_status.over > 0 &&
                        '[&_[data-slot=card-content]>p:first-child]:text-danger',
                )}
            />
            {financials ? (
                <>
                    <KpiCard
                        label={t('billing.kpis.income')}
                        definition={t('billing.kpis.income_definition')}
                        value={formatCurrency(totals.income ?? '0')}
                        detail={t('billing.kpis.cost_detail', {
                            cost: formatCurrency(totals.cost ?? '0'),
                        })}
                    />
                    <KpiCard
                        label={t('billing.kpis.invoiced')}
                        definition={t('billing.kpis.invoiced_definition')}
                        value={formatCurrency(totals.invoiced ?? '0')}
                        detail={[
                            t('billing.kpis.to_invoice_detail', {
                                amount: formatCurrency(
                                    totals.to_invoice ?? '0',
                                ),
                            }),
                            amount(totals.planned) !== 0
                                ? t('billing.kpis.planned_detail', {
                                      amount: formatCurrency(
                                          totals.planned ?? '0',
                                      ),
                                  })
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    />
                    <KpiCard
                        label={t('billing.kpis.collected')}
                        definition={t('billing.kpis.collected_definition')}
                        value={formatCurrency(totals.collected ?? '0')}
                        detail={
                            amount(totals.overdue) > 0
                                ? t('billing.kpis.outstanding_overdue', {
                                      pending: formatCurrency(
                                          totals.outstanding ?? '0',
                                      ),
                                      overdue: formatCurrency(
                                          totals.overdue ?? '0',
                                      ),
                                  })
                                : t('billing.kpis.outstanding_detail', {
                                      pending: formatCurrency(
                                          totals.outstanding ?? '0',
                                      ),
                                  })
                        }
                    >
                        {invoicedTotal > 0 ? (
                            <Meter
                                value={amount(totals.collected)}
                                max={invoicedTotal}
                                label={t('billing.kpis.collected')}
                                className="mt-1"
                            />
                        ) : null}
                    </KpiCard>
                    <KpiCard
                        label={t('billing.kpis.margin')}
                        definition={t('billing.kpis.margin_definition')}
                        value={formatCurrency(totals.margin ?? '0')}
                        detail={
                            totals.margin_pct === null ||
                            totals.margin_pct === undefined
                                ? undefined
                                : t('billing.kpis.margin_detail', {
                                      pct: formatNumber(totals.margin_pct, 1),
                                  })
                        }
                        className={cn(
                            amount(totals.margin) < 0 &&
                                '[&_[data-slot=card-content]>p:first-child]:text-danger',
                        )}
                    />
                </>
            ) : null}
        </section>
    );
}

/** Icono del estado global (para cabeceras compactas). */
export function TotalsStatusIcon({ status }: { status: string }) {
    if (status === 'over') {
        return (
            <CircleAlert aria-hidden="true" className="size-4 text-danger" />
        );
    }

    if (status === 'risk') {
        return (
            <TriangleAlert aria-hidden="true" className="size-4 text-warning" />
        );
    }

    return <CircleCheck aria-hidden="true" className="size-4 text-success" />;
}
