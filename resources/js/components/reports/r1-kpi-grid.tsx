import { KpiCard } from '@/components/reports/kpi-card';
import type { KpiDelta } from '@/components/reports/kpi-card';
import { formatCurrency, formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { MetricsSummary } from '@/types';

/** Métricas del SPEC §10 que puede mostrar la rejilla (claves de reports.metric.*). */
export type R1Kpi =
    | 'logged'
    | 'capacity'
    | 'billable'
    | 'occupancy'
    | 'billability'
    | 'billable_productivity'
    | 'estimation'
    | 'income'
    | 'cost'
    | 'margin';

/** Las económicas solo salen si el resumen las trae (view-financials). */
function isFinancial(key: R1Kpi): key is 'income' | 'cost' | 'margin' {
    return key === 'income' || key === 'cost' || key === 'margin';
}

function money(value: string | null): number | null {
    if (value === null) {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

function signedPercent(ratio: number): string {
    const formatted = formatPercent(Math.abs(ratio), 0);

    return ratio > 0
        ? `+${formatted}`
        : ratio < 0
          ? `−${formatted}`
          : formatted;
}

type KpiView = {
    value: string | null;
    detail?: string;
    delta?: KpiDelta;
};

/**
 * Valor, línea secundaria y variación de cada KPI. La variación compara con el periodo anterior
 * (comparar=1); el coste sube «a peor». La precisión de estimación no lleva variación: lo bueno es
 * acercarse al 100 %, no subir ni bajar.
 */
export function kpiView(
    key: R1Kpi,
    summary: MetricsSummary,
    previous: MetricsSummary | null,
): KpiView {
    const delta = (
        current: number | null,
        before: number | null | undefined,
        higherIsBetter = true,
    ): KpiDelta | undefined =>
        previous === null
            ? undefined
            : { current, previous: before ?? null, higherIsBetter };

    switch (key) {
        case 'logged':
            return {
                value: formatMinutes(summary.logged_minutes),
                detail: t('reports_r1.kpi.of_capacity', {
                    capacity: formatMinutes(summary.capacity_minutes),
                }),
                delta: delta(summary.logged_minutes, previous?.logged_minutes),
            };
        case 'capacity':
            return {
                value: formatMinutes(summary.capacity_minutes),
                delta: delta(
                    summary.capacity_minutes,
                    previous?.capacity_minutes,
                ),
            };
        case 'billable':
            return {
                value: formatMinutes(summary.billable_minutes),
                delta: delta(
                    summary.billable_minutes,
                    previous?.billable_minutes,
                ),
            };
        case 'occupancy':
        case 'billability':
        case 'billable_productivity': {
            const ratio = summary[key];

            return {
                value: ratio === null ? null : formatPercent(ratio),
                delta: delta(ratio, previous?.[key]),
            };
        }
        case 'estimation': {
            const { accuracy, deviation, tasks } = summary.estimation;

            return {
                value: accuracy === null ? null : formatPercent(accuracy),
                detail:
                    tasks === 0
                        ? t('reports_r1.kpi.estimation_none')
                        : t('reports_r1.kpi.estimation_detail', {
                              deviation:
                                  deviation === null
                                      ? '—'
                                      : signedPercent(deviation),
                              tasks,
                          }),
            };
        }
        case 'income':
            return {
                value: formatCurrency(summary.income),
                delta: delta(
                    money(summary.income),
                    money(previous?.income ?? null),
                ),
            };
        case 'cost':
            return {
                value: formatCurrency(summary.cost),
                delta: delta(
                    money(summary.cost),
                    money(previous?.cost ?? null),
                    false,
                ),
            };
        case 'margin':
            return {
                value: formatCurrency(summary.margin),
                detail:
                    summary.margin_pct === null
                        ? undefined
                        : t('reports_r1.kpi.margin_pct', {
                              pct: formatPercent(summary.margin_pct),
                          }),
                delta: delta(
                    money(summary.margin),
                    money(previous?.margin ?? null),
                ),
            };
    }
}

/**
 * Rejilla de KPIs de un dashboard (SPEC §10): cada tarjeta con su definición (tooltip) y, si se
 * compara, su variación frente al periodo anterior con icono y texto.
 */
export function R1KpiGrid({
    summary,
    comparison,
    kpis,
    loading = false,
    className,
}: {
    summary: MetricsSummary;
    comparison: MetricsSummary | null;
    kpis: R1Kpi[];
    loading?: boolean;
    className?: string;
}) {
    const visible = kpis.filter(
        (key) => !isFinancial(key) || summary[key] !== null,
    );

    return (
        <div
            className={cn(
                'grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-4',
                className,
            )}
            data-test="r1-kpis"
        >
            {visible.map((key) => {
                const view = kpiView(key, summary, comparison);

                return (
                    <KpiCard
                        key={key}
                        label={t(`reports.metric.${key}.label`)}
                        definition={t(`reports.metric.${key}.definition`)}
                        value={view.value}
                        detail={view.detail}
                        delta={view.delta}
                        loading={loading}
                    />
                );
            })}
        </div>
    );
}
