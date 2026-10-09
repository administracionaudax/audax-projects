import { CircleAlert, CircleCheck, TriangleAlert } from 'lucide-react';
import { KpiCard } from '@/components/reports/kpi-card';
import {
    formatCurrency,
    formatDurationWords,
    formatMinutes,
    formatNumber,
} from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SoldVsActualTotals } from '@/types';
import { KpiGroup } from './kpi-group';
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

/** «46 h 15 min por encima», «1.493 h por debajo» o «Igual que lo vendido» (D-410). */
export function deviationWords(minutes: number): string {
    if (minutes === 0) {
        return t('billing.kpis.deviation_even');
    }

    return t(
        minutes > 0
            ? 'billing.kpis.deviation_above'
            : 'billing.kpis.deviation_below',
        { duration: formatDurationWords(minutes) },
    );
}

/**
 * Cifras de «Vendido frente a real» (D-390 y D-410) en grupos rotulados una vez: «Horas» (consumo
 * de lo vendido con su medidor, desviación en palabras y unidades por estado) y, con
 * view-financials, «Facturación (sin IVA)» con lo pendiente de facturar como cifra principal, lo
 * facturado y el margen, y «Cobros (con IVA)» con lo cobrado, lo pendiente y lo vencido.
 */
export function SoldVsActualKpis({
    totals,
    financials,
    compact = false,
}: {
    totals: SoldVsActualTotals;
    financials: boolean;
    /** Una sola unidad (la ficha de una bolsa): sin la tarjeta de unidades. */
    compact?: boolean;
}) {
    const pct = totals.consumption_pct;
    const invoicedTotal = amount(totals.collected) + amount(totals.outstanding);

    const hours = (
        <KpiGroup
            title={t('billing.kpis.group_hours')}
            help={<p>{t('billing.kpis.help_hours')}</p>}
            columns={cn(
                'sm:grid-cols-2',
                compact ? 'lg:grid-cols-2' : 'lg:grid-cols-3',
            )}
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
                value={deviationWords(totals.deviation_minutes)}
                detail={
                    totals.pending_minutes > 0
                        ? t('billing.kpis.pending_detail', {
                              hours: formatDurationWords(
                                  totals.pending_minutes,
                              ),
                          })
                        : t('billing.kpis.deviation_exact', {
                              value: signedMinutes(
                                  totals.deviation_minutes,
                                  formatMinutes,
                              ),
                          })
                }
            />
            {compact ? null : (
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
                        t('billing.kpis.units_ok', {
                            count: totals.by_status.ok,
                        }),
                        totals.by_status.unbilled > 0
                            ? t('billing.kpis.units_unbilled', {
                                  count: totals.by_status.unbilled,
                              })
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                >
                    {totals.by_status.over > 0 ? (
                        <p className="inline-flex items-center gap-1 text-xs text-foreground">
                            <CircleAlert
                                aria-hidden="true"
                                className="size-3.5 text-danger"
                            />
                            {t('billing.kpis.units_over_hint')}
                        </p>
                    ) : null}
                </KpiCard>
            )}
        </KpiGroup>
    );

    if (!financials) {
        return (
            <div className="grid gap-5" data-test="sold-vs-actual-kpis">
                {hours}
            </div>
        );
    }

    return (
        <div
            role="group"
            aria-label={t('billing.kpis.label')}
            className="grid gap-5"
            data-test="sold-vs-actual-kpis"
        >
            {hours}
            <div className="grid gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,1fr)]">
                <KpiGroup
                    title={t('billing.kpis.group_invoiced')}
                    help={<p>{t('billing.kpis.help_invoiced')}</p>}
                    columns="grid-cols-2 sm:grid-cols-3"
                >
                    <KpiCard
                        className="col-span-2 sm:col-span-1"
                        label={t('billing.kpis.to_invoice')}
                        definition={t('billing.kpis.to_invoice_definition')}
                        value={formatCurrency(totals.to_invoice ?? '0')}
                        detail={t('billing.kpis.to_invoice_from', {
                            sold: formatCurrency(totals.income ?? '0'),
                        })}
                    />
                    <KpiCard
                        label={t('billing.kpis.invoiced')}
                        definition={t('billing.kpis.invoiced_definition')}
                        value={formatCurrency(totals.invoiced ?? '0')}
                        detail={
                            amount(totals.planned) !== 0
                                ? t('billing.kpis.planned_detail', {
                                      amount: formatCurrency(
                                          totals.planned ?? '0',
                                      ),
                                  })
                                : undefined
                        }
                    />
                    <KpiCard
                        label={t('billing.kpis.margin')}
                        definition={t('billing.kpis.margin_definition')}
                        value={formatCurrency(totals.margin ?? '0')}
                        detail={[
                            totals.margin_pct === null ||
                            totals.margin_pct === undefined
                                ? null
                                : t('billing.kpis.margin_detail', {
                                      pct: formatNumber(totals.margin_pct, 1),
                                  }),
                            t('billing.kpis.cost_detail', {
                                cost: formatCurrency(totals.cost ?? '0'),
                            }),
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    >
                        {amount(totals.margin) < 0 ? (
                            <p className="inline-flex items-center gap-1 text-xs text-foreground">
                                <CircleAlert
                                    aria-hidden="true"
                                    className="size-3.5 text-danger"
                                />
                                {t('billing.kpis.margin_negative')}
                            </p>
                        ) : null}
                    </KpiCard>
                </KpiGroup>
                <KpiGroup title={t('billing.kpis.group_collected')}>
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
                </KpiGroup>
            </div>
        </div>
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
