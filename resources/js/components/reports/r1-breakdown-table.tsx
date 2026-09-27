import { Link } from '@inertiajs/react';
import type { BarRow } from '@/components/reports/r1-bar-chart';
import type {
    R1BreakdownRow,
    R1OthersRow,
    R1TopRows,
} from '@/components/reports/r1-types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Barras de un reparto: las filas del top y, si las hay, «Otros (n)» al final. */
export function breakdownBars(top: R1TopRows): BarRow[] {
    const rows: BarRow[] = top.rows.map((row) => ({
        id: row.key ?? 'none',
        label: row.name,
        values: { logged: row.logged_minutes },
    }));

    if (top.others) {
        rows.push({
            id: 'others',
            label: t('reports_r1.others', { count: top.others.count }),
            values: { logged: top.others.logged_minutes },
        });
    }

    return rows;
}

type Line = (R1BreakdownRow | R1OthersRow) & { id: string; name: string };

function ratio(numerator: number, denominator: number): string {
    return denominator > 0 ? formatPercent(numerator / denominator) : '—';
}

/**
 * Tabla de un reparto (top 10 de clientes, proyectos…): horas, facturables, % del total y
 * facturabilidad; con datos económicos, ingreso y rentabilidad. «Otros» suma el resto. En móvil se
 * desplaza dentro de su contenedor.
 */
export function R1BreakdownTable({
    caption,
    nameLabel,
    top,
    totalMinutes,
    financials,
    href,
    emptyLabel,
}: {
    caption: string;
    nameLabel: string;
    top: R1TopRows;
    /** Horas imputadas del periodo (para el % del total). */
    totalMinutes: number;
    financials: boolean;
    /** Enlace de cada fila (p. ej. al dashboard del cliente); null = sin enlace. */
    href?: (row: R1BreakdownRow) => string | null;
    emptyLabel: string;
}) {
    const lines: Line[] = top.rows.map((row) => ({
        ...row,
        id: row.key ?? 'none',
    }));

    if (top.others) {
        lines.push({
            ...top.others,
            id: 'others',
            name: t('reports_r1.others', { count: top.others.count }),
        });
    }

    if (lines.length === 0) {
        return <p className="text-sm text-muted-foreground">{emptyLabel}</p>;
    }

    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={caption}
            tabIndex={0}
        >
            <table className="w-full min-w-[34rem] text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {nameLabel}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.logged')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.share')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.billable')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.billability')}
                        </th>
                        {financials ? (
                            <>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {t('reports_r1.columns.income')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {t('reports_r1.columns.margin')}
                                </th>
                            </>
                        ) : null}
                    </tr>
                </thead>
                <tbody>
                    {lines.map((line) => {
                        const link =
                            line.id !== 'others' && href && 'key' in line
                                ? href(line)
                                : null;

                        return (
                            <tr
                                key={line.id}
                                className="border-b last:border-0"
                            >
                                <th
                                    scope="row"
                                    className={cn(
                                        'max-w-64 truncate px-3 py-1.5 text-left font-normal',
                                        line.id === 'others' &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {'color' in line && line.color ? (
                                        <span
                                            aria-hidden="true"
                                            className="mr-2 inline-block size-2 rounded-full align-middle"
                                            style={{
                                                backgroundColor: line.color,
                                            }}
                                        />
                                    ) : null}
                                    {link ? (
                                        <Link
                                            href={link}
                                            className={cn(
                                                'rounded-sm hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {line.name}
                                        </Link>
                                    ) : (
                                        line.name
                                    )}
                                </th>
                                <td className="tabular px-3 py-1.5 text-right">
                                    {formatMinutes(line.logged_minutes)}
                                </td>
                                <td className="tabular px-3 py-1.5 text-right">
                                    {ratio(line.logged_minutes, totalMinutes)}
                                </td>
                                <td className="tabular px-3 py-1.5 text-right">
                                    {formatMinutes(line.billable_minutes)}
                                </td>
                                <td className="tabular px-3 py-1.5 text-right">
                                    {ratio(
                                        line.billable_minutes,
                                        line.logged_minutes,
                                    )}
                                </td>
                                {financials ? (
                                    <>
                                        <td className="tabular px-3 py-1.5 text-right">
                                            {formatCurrency(line.income)}
                                        </td>
                                        <td className="tabular px-3 py-1.5 text-right">
                                            {formatCurrency(line.margin)}
                                        </td>
                                    </>
                                ) : null}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
