import type { ReactNode } from 'react';
import {
    Bar,
    BarChart,
    Cell,
    LabelList,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    BAR_RADIUS,
    CHART_COLORS,
    CHART_INK,
    MAX_BAR_SIZE,
} from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import type { ChartTableColumn } from '@/components/charts/chart-frame';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { formatCurrency } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    amount,
    amountTicks,
    compactCurrency,
    shareLabel,
} from './invoicing-lib';

export type AmountBar = {
    id: string;
    label: string;
    /** Importe decimal («1234.50»). */
    amount: string;
    share: string | null;
    /** Agregados («Resto», «Sin cliente casado»): en gris, no son una entidad. */
    muted?: boolean;
    /** Celdas extra de la vista de tabla (p. ej. el número de facturas o un enlace). */
    cells?: Record<string, ReactNode>;
};

const ROW_HEIGHT = 34;
/** Caracteres que caben en el eje; el nombre entero va en el tooltip y en la tabla. */
const LABEL_CHARS = 16;
/** Gris de los agregados: la tinta secundaria sobre la tarjeta (no es un color de serie). */
const MUTED_FILL = `color-mix(in oklab, ${CHART_INK.axis} 45%, ${CHART_INK.surface})`;

function short(label: string): string {
    return label.length > LABEL_CHARS
        ? `${label.slice(0, LABEL_CHARS - 1)}…`
        : label;
}

type TickProps = {
    x?: number | string;
    y?: number | string;
    payload?: { value?: unknown };
};

function NameTick({ x, y, payload }: TickProps) {
    const text = typeof payload?.value === 'string' ? payload.value : '';

    return (
        <g transform={`translate(${Number(x ?? 0)},${Number(y ?? 0)})`}>
            <title>{text}</title>
            <text
                x={-8}
                y={0}
                dy={4}
                textAnchor="end"
                fill={CHART_INK.label}
                fontSize={12}
            >
                {short(text)}
            </text>
        </g>
    );
}

/**
 * Barras horizontales de importes (D-400: por servicio y ranking de clientes): una sola serie en
 * --chart-1 (la magnitud la da la longitud; la identidad, el nombre del eje), los agregados en gris,
 * cada barra con su importe y su peso al final, tooltip y vista de tabla accesible.
 */
export function InvoicingBars({
    title,
    description,
    categoryLabel,
    rows,
    extraColumns = [],
    footer,
    test,
}: {
    title: string;
    description?: string;
    categoryLabel: string;
    rows: AmountBar[];
    extraColumns?: ChartTableColumn[];
    footer?: ReactNode;
    test?: string;
}) {
    const data = rows.map((row) => ({
        ...row,
        value: amount(row.amount),
    }));
    const ticks = amountTicks(
        Math.min(0, ...data.map((row) => row.value)),
        Math.max(0, ...data.map((row) => row.value)),
        4,
    );

    return (
        <div className="grid gap-3" data-test={test}>
            <ChartFrame
                title={title}
                description={description}
                summary={t('billing.invoicing.charts.bars_summary', {
                    title,
                    items: rows
                        .map(
                            (row) =>
                                `${row.label}: ${formatCurrency(row.amount)} (${shareLabel(row.share)})`,
                        )
                        .join('; '),
                })}
                table={{
                    columns: [
                        { key: 'label', label: categoryLabel },
                        {
                            key: 'amount',
                            label: t('billing.invoicing.columns.invoiced'),
                            numeric: true,
                        },
                        {
                            key: 'share',
                            label: t('billing.invoicing.columns.share'),
                            numeric: true,
                        },
                        ...extraColumns,
                    ],
                    rows: rows.map((row) => ({
                        id: row.id,
                        label: row.label,
                        amount: formatCurrency(row.amount),
                        share: shareLabel(row.share),
                        ...row.cells,
                    })),
                }}
            >
                <ResponsiveContainer
                    width="100%"
                    height={rows.length * ROW_HEIGHT + 4}
                >
                    <BarChart
                        accessibilityLayer={false}
                        data={data}
                        layout="vertical"
                        margin={{ top: 0, right: 96, bottom: 0, left: 0 }}
                        barCategoryGap={7}
                    >
                        {/* Cada barra lleva su importe: sin eje de euros ni rejilla (en el móvil se pisaban). */}
                        <XAxis
                            type="number"
                            domain={[ticks[0] ?? 0, ticks.at(-1) ?? 'auto']}
                            hide
                        />
                        <YAxis
                            type="category"
                            dataKey="label"
                            width={120}
                            tick={<NameTick />}
                            tickLine={false}
                            axisLine={{ stroke: CHART_INK.grid }}
                            interval={0}
                        />
                        {(ticks[0] ?? 0) < 0 ? (
                            <ReferenceLine x={0} stroke={CHART_INK.axis} />
                        ) : null}
                        <Tooltip
                            isAnimationActive={false}
                            cursor={{ fill: 'var(--muted)' }}
                            content={({ active, payload }) => {
                                const row = payload?.[0]?.payload as
                                    | (AmountBar & { value: number })
                                    | undefined;

                                return active && row ? (
                                    <ChartTooltipCard
                                        title={row.label}
                                        rows={[
                                            {
                                                key: 'amount',
                                                label: t(
                                                    'billing.invoicing.series.invoiced',
                                                ),
                                                color: row.muted
                                                    ? MUTED_FILL
                                                    : CHART_COLORS[0],
                                                value: formatCurrency(
                                                    row.amount,
                                                ),
                                            },
                                            {
                                                key: 'share',
                                                label: t(
                                                    'billing.invoicing.columns.share',
                                                ),
                                                color: 'transparent',
                                                value: shareLabel(row.share),
                                            },
                                        ]}
                                    />
                                ) : null;
                            }}
                        />
                        <Bar
                            dataKey="value"
                            maxBarSize={MAX_BAR_SIZE}
                            radius={[0, BAR_RADIUS, BAR_RADIUS, 0]}
                            isAnimationActive={false}
                        >
                            {data.map((row) => (
                                <Cell
                                    key={row.id}
                                    fill={
                                        row.muted ? MUTED_FILL : CHART_COLORS[0]
                                    }
                                />
                            ))}
                            <LabelList
                                dataKey="value"
                                position="right"
                                offset={8}
                                fill={CHART_INK.label}
                                fontSize={12}
                                formatter={(value: unknown) =>
                                    typeof value === 'number'
                                        ? compactCurrency(value)
                                        : ''
                                }
                            />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </ChartFrame>
            {footer}
        </div>
    );
}
