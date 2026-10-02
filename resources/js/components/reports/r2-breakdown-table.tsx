import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    formatOverage,
    marginRatio,
    subtractMoney,
} from '@/components/reports/r2-helpers';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BreakdownRow, MetricsSummary } from '@/types';

/** Totales de la tabla: los del resumen del servidor (Metrics::summary). */
export type R2BreakdownTotal = Pick<
    MetricsSummary,
    | 'logged_minutes'
    | 'billable_minutes'
    | 'in_bank_minutes'
    | 'overage_minutes'
    | 'income'
    | 'cost'
>;

/**
 * Tabla de un desglose de horas (por proyecto, persona o tipo): imputadas, facturables, dentro de
 * bolsa («—» en las filas sin horas en bolsas) y exceso por separado (el exceso en rojo con icono, SPEC §8.6) y, con view-financials,
 * ingreso, coste, rentabilidad y margen. Fila de totales al pie con los totales del SERVIDOR
 * (INT-04): los importes de las filas son su parte en céntimos del mismo total, así que el ingreso
 * sale con los mismos céntimos aquí, en los KPIs y en las exportaciones. Scroll horizontal propio
 * en el móvil (la página no se desplaza de lado).
 */
export function R2BreakdownTable({
    caption,
    firstColumn,
    rows,
    total,
    financials,
    showBank = true,
    renderName,
}: {
    caption: string;
    firstColumn: string;
    /** has_bank = false: la fila no tiene horas en bolsas («Dentro de bolsa» no aplica). */
    rows: ReadonlyArray<BreakdownRow & { has_bank?: boolean }>;
    /** Totales del resumen (servidor): nunca se suman aquí. */
    total: R2BreakdownTotal;
    financials: boolean;
    /** Columna «Dentro de bolsa» (solo tiene sentido si hay bolsas). */
    showBank?: boolean;
    /** Nombre enlazado u otro contenido para la primera columna. */
    renderName?: (row: BreakdownRow) => ReactNode;
}) {
    const money = (
        rowIncome: string | null,
        rowCost: string | null,
    ): ReactNode[] => {
        const margin = subtractMoney(rowIncome, rowCost);
        const ratio = marginRatio(rowIncome, rowCost);

        return [
            <td key="income" className="px-3 py-2 text-right">
                {formatCurrency(rowIncome ?? '0')}
            </td>,
            <td key="cost" className="px-3 py-2 text-right">
                {formatCurrency(rowCost ?? '0')}
            </td>,
            <td
                key="margin"
                className={cn(
                    'px-3 py-2 text-right',
                    margin.startsWith('-') && 'text-danger',
                )}
            >
                {formatCurrency(margin)}
            </td>,
            <td key="margin-pct" className="px-3 py-2 text-right">
                {ratio === null ? '—' : formatPercent(ratio)}
            </td>,
        ];
    };

    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={caption}
            tabIndex={0}
        >
            <table
                className={cn(
                    'tabular w-full text-sm',
                    financials ? 'min-w-[52rem]' : 'min-w-[32rem]',
                )}
            >
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {firstColumn}
                        </th>
                        <Th>{t('reports_r2.column.logged')}</Th>
                        <Th>{t('reports_r2.column.billable')}</Th>
                        {showBank ? (
                            <Th>{t('reports_r2.column.in_bank')}</Th>
                        ) : null}
                        <Th>{t('reports_r2.column.overage')}</Th>
                        {financials ? (
                            <>
                                <Th>{t('reports_r2.column.income')}</Th>
                                <Th>{t('reports_r2.column.cost')}</Th>
                                <Th>{t('reports_r2.column.margin')}</Th>
                                <Th>{t('reports_r2.column.margin_pct')}</Th>
                            </>
                        ) : null}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr
                            key={row.key ?? `none-${index}`}
                            className="border-b last:border-0 even:bg-muted"
                        >
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-normal"
                            >
                                {renderName ? renderName(row) : row.name}
                            </th>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(row.logged_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(row.billable_minutes)}
                            </td>
                            {showBank ? (
                                <td className="px-3 py-2 text-right">
                                    {row.has_bank === false ? (
                                        <>
                                            <span aria-hidden="true">—</span>
                                            <span className="sr-only">
                                                {t('reports_r2.no_bank')}
                                            </span>
                                        </>
                                    ) : (
                                        formatMinutes(row.in_bank_minutes)
                                    )}
                                </td>
                            ) : null}
                            <td className="px-3 py-2 text-right">
                                <R2OverageValue minutes={row.overage_minutes} />
                            </td>
                            {financials ? money(row.income, row.cost) : null}
                        </tr>
                    ))}
                </tbody>
                {rows.length > 1 ? (
                    <tfoot>
                        <tr className="border-t-2">
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-medium"
                            >
                                {t('reports_r2.total')}
                            </th>
                            <td className="px-3 py-2 text-right font-medium">
                                {formatMinutes(total.logged_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right font-medium">
                                {formatMinutes(total.billable_minutes)}
                            </td>
                            {showBank ? (
                                <td className="px-3 py-2 text-right font-medium">
                                    {formatMinutes(total.in_bank_minutes)}
                                </td>
                            ) : null}
                            <td className="px-3 py-2 text-right font-medium">
                                <R2OverageValue
                                    minutes={total.overage_minutes}
                                />
                            </td>
                            {financials
                                ? money(total.income, total.cost)
                                : null}
                        </tr>
                    </tfoot>
                ) : null}
            </table>
        </div>
    );
}

function Th({ children }: { children: ReactNode }) {
    return (
        <th scope="col" className="px-3 py-2 text-right font-medium">
            {children}
        </th>
    );
}

/** Exceso en rojo con icono y signo (nunca solo color); 0:00 en tinta normal. */
export function R2OverageValue({
    minutes,
    className,
}: {
    minutes: number;
    className?: string;
}) {
    if (minutes <= 0) {
        return <span className={className}>{formatOverage(0)}</span>;
    }

    return (
        <span
            className={cn(
                'inline-flex items-center justify-end gap-1 text-danger',
                className,
            )}
        >
            <TriangleAlert aria-hidden="true" className="size-3.5 shrink-0" />
            <span className="sr-only">{t('reports_r2.overage_sr')} </span>
            {formatOverage(minutes)}
        </span>
    );
}
