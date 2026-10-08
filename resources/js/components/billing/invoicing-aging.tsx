import { Link } from '@inertiajs/react';
import { ArrowRight, CircleAlert, CircleCheck } from 'lucide-react';
import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    LabelList,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    BAR_RADIUS,
    CHART_INK,
    MAX_BAR_SIZE,
    sequentialColor,
} from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { InvoicingOverdueGroup, InvoicingReport } from '@/types';
import {
    agingLabel,
    amount,
    amountTicks,
    compactCurrency,
    euroTick,
} from './invoicing-lib';

/** Grupos de vencidas que se ven antes de «Ver todas». */
const VISIBLE_GROUPS = 6;

/**
 * Antigüedad de lo pendiente (D-400): una columna por tramo con la rampa secuencial de --chart-1
 * (más intenso cuanto más antiguo), su importe encima (los tonos claros no se leen solos) y la
 * vista de tabla.
 */
export function InvoicingAgingChart({
    aging,
}: {
    aging: InvoicingReport['aging'];
}) {
    const rows = aging.map((bucket, index) => ({
        id: bucket.key,
        label: agingLabel(bucket.key),
        tick: t(`billing.invoicing.aging_short.${bucket.key}`),
        value: amount(bucket.amount),
        amount: bucket.amount,
        count: bucket.count,
        color: sequentialColor(index + 1),
    }));
    const ticks = amountTicks(0, Math.max(0, ...rows.map((row) => row.value)), 4);
    const total = rows.reduce((sum, row) => sum + row.value, 0);

    return (
        <ChartFrame
            title={t('billing.invoicing.charts.aging')}
            description={t('billing.invoicing.charts.aging_description')}
            summary={t('billing.invoicing.charts.bars_summary', {
                title: t('billing.invoicing.charts.aging'),
                items: rows
                    .map((row) => `${row.label}: ${formatCurrency(row.amount)}`)
                    .join('; '),
            })}
            table={{
                columns: [
                    {
                        key: 'label',
                        label: t('billing.invoicing.columns.bucket'),
                    },
                    {
                        key: 'amount',
                        label: t('billing.invoicing.columns.outstanding'),
                        numeric: true,
                    },
                    {
                        key: 'count',
                        label: t('billing.invoicing.columns.count'),
                        numeric: true,
                    },
                ],
                rows: [
                    ...rows.map((row) => ({
                        id: row.id,
                        label: row.label,
                        amount: formatCurrency(row.amount),
                        count: String(row.count),
                    })),
                    {
                        id: 'total',
                        label: t('billing.invoicing.total'),
                        amount: formatCurrency(total),
                        count: String(
                            rows.reduce((sum, row) => sum + row.count, 0),
                        ),
                    },
                ],
            }}
        >
            <ResponsiveContainer width="100%" height={240}>
                <BarChart
                    accessibilityLayer={false}
                    data={rows}
                    margin={{ top: 22, right: 8, bottom: 0, left: 0 }}
                    barCategoryGap="18%"
                >
                    <CartesianGrid vertical={false} stroke={CHART_INK.grid} />
                    <XAxis
                        dataKey="tick"
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={{ stroke: CHART_INK.grid }}
                        interval={0}
                    />
                    <YAxis
                        ticks={ticks}
                        domain={[0, ticks.at(-1) ?? 'auto']}
                        tickFormatter={euroTick}
                        tick={{ fill: CHART_INK.axis, fontSize: 12 }}
                        tickLine={false}
                        axisLine={false}
                        width={56}
                    />
                    <Tooltip
                        isAnimationActive={false}
                        cursor={{ fill: 'var(--muted)' }}
                        content={({ active, payload }) => {
                            const row = payload?.[0]?.payload as
                                | (typeof rows)[number]
                                | undefined;

                            return active && row ? (
                                <ChartTooltipCard
                                    title={row.label}
                                    rows={[
                                        {
                                            key: 'amount',
                                            label: t(
                                                'billing.invoicing.series.outstanding',
                                            ),
                                            color: row.color,
                                            value: formatCurrency(row.amount),
                                        },
                                        {
                                            key: 'count',
                                            label: t(
                                                'billing.invoicing.columns.count',
                                            ),
                                            color: 'transparent',
                                            value: String(row.count),
                                        },
                                    ]}
                                />
                            ) : null;
                        }}
                    />
                    <Bar
                        dataKey="value"
                        maxBarSize={MAX_BAR_SIZE * 2}
                        radius={[BAR_RADIUS, BAR_RADIUS, 0, 0]}
                        isAnimationActive={false}
                    >
                        {rows.map((row) => (
                            <Cell key={row.id} fill={row.color} />
                        ))}
                        <LabelList
                            dataKey="value"
                            position="top"
                            offset={6}
                            fill={CHART_INK.label}
                            fontSize={12}
                            formatter={(value: unknown) =>
                                typeof value === 'number' && value !== 0
                                    ? compactCurrency(value)
                                    : ''
                            }
                        />
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
        </ChartFrame>
    );
}

function daysTone(days: number): string {
    return days > 90
        ? 'bg-danger-soft text-foreground'
        : 'bg-warning-soft text-foreground';
}

function GroupTitle({ group }: { group: InvoicingOverdueGroup }) {
    if (group.client !== null) {
        return (
            <Link
                href={`/clientes/${group.client.id}/facturacion`}
                className={cn('rounded-sm hover:underline', FOCUS_RING)}
            >
                {group.client.name}
            </Link>
        );
    }

    return (
        <span className="flex flex-wrap items-center gap-x-2">
            <span>
                {group.contact_name ?? t('billing.invoicing.unmatched')}
            </span>
            <Link
                href="/facturacion/contactos"
                className={cn(
                    'rounded-sm text-xs text-muted-foreground underline hover:text-foreground',
                    FOCUS_RING,
                )}
            >
                {t('billing.invoicing.unmatched_link')}
            </Link>
        </span>
    );
}

/**
 * Facturas vencidas por cliente (D-400): quien más debe primero y, dentro, la más antigua; cada
 * factura enlaza a su ficha y el cliente, a su página de facturación (sin cliente casado, a los
 * contactos de Holded). En el móvil, una columna: nada de tablas anchas.
 */
export function InvoicingOverdueList({
    overdue,
}: {
    overdue: InvoicingReport['overdue'];
}) {
    const [all, setAll] = useState(false);
    const groups = all
        ? overdue.clients
        : overdue.clients.slice(0, VISIBLE_GROUPS);

    return (
        <section
            aria-labelledby="invoicing-overdue-title"
            className="grid gap-3"
            data-test="invoicing-overdue"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h3 id="invoicing-overdue-title" className="text-base font-medium">
                    {t('billing.invoicing.charts.overdue')}
                </h3>
                {overdue.total > 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            overdue.total === 1
                                ? 'billing.invoicing.overdue_count_one'
                                : 'billing.invoicing.overdue_count_other',
                            { count: overdue.total },
                        )}
                    </p>
                ) : null}
            </div>

            {overdue.clients.length === 0 ? (
                <p className="flex items-center gap-2 rounded-md border bg-success-soft px-3 py-2 text-sm">
                    <CircleCheck
                        aria-hidden="true"
                        className="size-4 shrink-0 text-success"
                    />
                    {t('billing.invoicing.no_overdue')}
                </p>
            ) : (
                <ul className="grid gap-3">
                    {groups.map((group) => (
                        <li
                            key={
                                group.client
                                    ? `c${group.client.id}`
                                    : `n${group.contact_name ?? ''}`
                            }
                            className="rounded-md border bg-card"
                        >
                            <div className="flex items-start justify-between gap-3 border-b px-3 py-2">
                                <p className="min-w-0 text-sm font-medium break-words">
                                    <GroupTitle group={group} />
                                </p>
                                <p className="shrink-0 text-sm font-medium tabular">
                                    {formatCurrency(group.amount)}
                                </p>
                            </div>
                            <ul className="divide-y">
                                {group.invoices.map((invoice) => (
                                    <li key={invoice.id}>
                                        <Link
                                            href={`/facturacion/facturas/${invoice.id}`}
                                            className={cn(
                                                'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5 px-3 py-2 text-sm hover:bg-muted',
                                                FOCUS_RING,
                                            )}
                                        >
                                            <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                                <span className="tabular">
                                                    {invoice.number ?? '—'}
                                                </span>
                                                <span
                                                    className={cn(
                                                        'inline-flex items-center gap-1 rounded-md px-1.5 text-xs',
                                                        daysTone(invoice.days),
                                                    )}
                                                >
                                                    <CircleAlert
                                                        aria-hidden="true"
                                                        className={cn(
                                                            'size-3',
                                                            invoice.days > 90
                                                                ? 'text-danger'
                                                                : 'text-warning',
                                                        )}
                                                    />
                                                    {t(
                                                        invoice.days === 1
                                                            ? 'billing.invoicing.days_late_one'
                                                            : 'billing.invoicing.days_late_other',
                                                        { count: invoice.days },
                                                    )}
                                                </span>
                                            </span>
                                            <span className="tabular text-right">
                                                {formatCurrency(invoice.pending)}
                                            </span>
                                            <span className="col-span-2 text-xs text-muted-foreground">
                                                {t('billing.invoicing.due_on', {
                                                    date: formatDate(
                                                        invoice.due_on,
                                                    ),
                                                })}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ul>
            )}

            {overdue.clients.length > VISIBLE_GROUPS ? (
                <Button
                    type="button"
                    variant="outline"
                    className="justify-self-start"
                    onClick={() => setAll((value) => !value)}
                >
                    {all
                        ? t('billing.invoicing.overdue_less')
                        : t('billing.invoicing.overdue_all', {
                              count: overdue.clients.length,
                          })}
                    <ArrowRight
                        aria-hidden="true"
                        className={cn(all && '-rotate-90', !all && 'rotate-90')}
                    />
                </Button>
            ) : null}
            {overdue.total > overdue.shown ? (
                <p className="text-xs text-muted-foreground">
                    {t('billing.invoicing.overdue_more', {
                        count: overdue.total - overdue.shown,
                    })}
                </p>
            ) : null}
        </section>
    );
}
