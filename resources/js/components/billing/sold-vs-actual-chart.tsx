import { Link } from '@inertiajs/react';
import type { LegendItem } from '@/components/charts/chart-config';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SoldVsActualUnit } from '@/types';
import { SaleStatusBadge } from './billing-badges';
import {
    bulletSegments,
    chartUnits,
    signedMinutes,
} from './sold-vs-actual-lib';
import { unitHref } from './sold-vs-actual-table';

/** Lo real dentro de lo vendido: la serie única (chart-1); el exceso, en el rojo de estado (SPEC §8.6). */
const REAL_COLOR = 'var(--chart-1)';
const OVER_COLOR = 'var(--danger)';
/** Pista de lo vendido: un paso más claro de la misma rampa (dataviz: meter). */
const TRACK_COLOR = 'color-mix(in oklab, var(--chart-1) 16%, var(--card))';

/**
 * «Vendido frente a real» en barras de bala (D-390): por unidad de venta, la pista es lo vendido,
 * la barra lo real (azul dentro, rojo lo que pasa) y la marca vertical el 100 %. Todas en la misma
 * escala, primero las más consumidas y como mucho 12; el resto, en la tabla. Cada barra tiene su
 * tooltip con las cifras (también con el teclado) y la gráfica, su vista de tabla.
 */
export function SoldVsActualChart({
    units,
    title,
    description,
}: {
    units: ReadonlyArray<SoldVsActualUnit>;
    title: string;
    description?: string;
}) {
    const rows = chartUnits(units);
    const scale = Math.max(
        1,
        ...rows.map((unit) =>
            Math.max(unit.sold_minutes ?? 0, unit.real_minutes),
        ),
    );
    const withOver = rows.some(
        (unit) => unit.real_minutes > (unit.sold_minutes ?? 0),
    );
    const legend: LegendItem[] = [
        {
            key: 'real',
            label: t('billing.chart.real'),
            color: REAL_COLOR,
            shape: 'rect',
        },
        ...(withOver
            ? [
                  {
                      key: 'over',
                      label: t('billing.chart.over'),
                      color: OVER_COLOR,
                      shape: 'rect' as const,
                  },
              ]
            : []),
        {
            key: 'sold',
            label: t('billing.chart.sold'),
            color: TRACK_COLOR,
            shape: 'rect',
        },
    ];

    if (rows.length === 0) {
        return null;
    }

    const hidden =
        units.filter((unit) => unit.sold_minutes).length - rows.length;

    return (
        <ChartFrame
            title={title}
            description={description}
            summary={t('billing.chart.summary', {
                count: rows.length,
                over: rows.filter((unit) => unit.status === 'over').length,
            })}
            legend={<ChartLegend items={legend} />}
            chartRole="group"
            table={{
                columns: [
                    { key: 'unit', label: t('billing.columns.unit') },
                    {
                        key: 'sold',
                        label: t('billing.columns.sold_hours'),
                        numeric: true,
                    },
                    {
                        key: 'real',
                        label: t('billing.columns.real_hours'),
                        numeric: true,
                    },
                    {
                        key: 'deviation',
                        label: t('billing.columns.deviation'),
                        numeric: true,
                    },
                    {
                        key: 'pct',
                        label: t('billing.columns.consumption'),
                        numeric: true,
                    },
                ],
                rows: rows.map((unit) => ({
                    id: unit.key,
                    unit: `${unit.project.code} · ${unit.name}`,
                    sold: formatMinutes(unit.sold_minutes ?? 0),
                    real: formatMinutes(unit.real_minutes),
                    deviation: signedMinutes(
                        unit.deviation_minutes,
                        formatMinutes,
                    ),
                    pct:
                        unit.consumption_pct === null
                            ? '—'
                            : `${formatNumber(unit.consumption_pct, 1)} %`,
                })),
            }}
        >
            <ol className="grid gap-3" data-test="sold-vs-actual-chart">
                {rows.map((unit) => {
                    const sold = unit.sold_minutes ?? 0;
                    const { inside, over, track } = bulletSegments(
                        sold,
                        unit.real_minutes,
                        scale,
                    );

                    return (
                        <li
                            key={unit.key}
                            className="grid gap-1 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] sm:items-center sm:gap-4"
                        >
                            <Link
                                href={unitHref(unit)}
                                className={cn(
                                    'min-w-0 truncate rounded-md text-sm hover:underline',
                                    FOCUS_RING,
                                )}
                                title={`${unit.project.code} · ${unit.name}`}
                            >
                                <span className="text-muted-foreground">
                                    {unit.project.code}
                                </span>{' '}
                                {unit.name}
                            </Link>

                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <div
                                        tabIndex={0}
                                        aria-label={t(
                                            'billing.chart.bar_label',
                                            {
                                                unit: unit.name,
                                                real: formatMinutes(
                                                    unit.real_minutes,
                                                ),
                                                sold: formatMinutes(sold),
                                            },
                                        )}
                                        className={cn(
                                            'relative h-6 rounded-md py-1',
                                            FOCUS_RING,
                                        )}
                                    >
                                        {/* Pista: lo vendido. */}
                                        <span
                                            aria-hidden="true"
                                            className="absolute inset-y-1 left-0 rounded-r-md"
                                            style={{
                                                width: `${track}%`,
                                                backgroundColor: TRACK_COLOR,
                                            }}
                                        />
                                        {/* Lo real dentro de lo vendido. */}
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'absolute inset-y-2 left-0',
                                                over > 0
                                                    ? ''
                                                    : 'rounded-r-[4px]',
                                            )}
                                            style={{
                                                width: `${inside}%`,
                                                backgroundColor: REAL_COLOR,
                                            }}
                                        />
                                        {/* El exceso, tras un hueco de 2 px. */}
                                        {over > 0 ? (
                                            <span
                                                aria-hidden="true"
                                                className="absolute inset-y-2 rounded-r-[4px] border-l-2 border-card"
                                                style={{
                                                    left: `${inside}%`,
                                                    width: `${over}%`,
                                                    backgroundColor: OVER_COLOR,
                                                }}
                                            />
                                        ) : null}
                                        {/* El 100 % de lo vendido. */}
                                        <span
                                            aria-hidden="true"
                                            className="absolute inset-y-0 w-0.5 bg-foreground"
                                            style={{
                                                left: `calc(${track}% - 1px)`,
                                            }}
                                        />
                                    </div>
                                </TooltipTrigger>
                                <TooltipContent className="grid gap-1 text-left">
                                    <span className="font-medium">
                                        {unit.project.code} · {unit.name}
                                    </span>
                                    <span>
                                        {t('billing.chart.tooltip', {
                                            real: formatMinutes(
                                                unit.real_minutes,
                                            ),
                                            sold: formatMinutes(sold),
                                        })}
                                    </span>
                                    <span>
                                        {t('billing.chart.tooltip_deviation', {
                                            deviation: signedMinutes(
                                                unit.deviation_minutes,
                                                formatMinutes,
                                            ),
                                        })}
                                    </span>
                                    {unit.pending_minutes > 0 ? (
                                        <span>
                                            {t(
                                                'billing.chart.tooltip_pending',
                                                {
                                                    hours: formatMinutes(
                                                        unit.pending_minutes,
                                                    ),
                                                },
                                            )}
                                        </span>
                                    ) : null}
                                </TooltipContent>
                            </Tooltip>

                            <div className="flex items-center justify-between gap-2 sm:justify-end">
                                <span className="tabular text-sm">
                                    {unit.consumption_pct === null
                                        ? '—'
                                        : `${formatNumber(unit.consumption_pct, 0)} %`}
                                </span>
                                <SaleStatusBadge status={unit.status} />
                            </div>
                        </li>
                    );
                })}
            </ol>
            {hidden > 0 ? (
                <p className="mt-3 text-xs text-muted-foreground">
                    {t('billing.chart.more', { count: hidden })}
                </p>
            ) : null}
        </ChartFrame>
    );
}
