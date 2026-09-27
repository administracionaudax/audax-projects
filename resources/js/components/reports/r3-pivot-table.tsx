import { ArrowDown, ArrowUp, ArrowUpDown, TriangleAlert } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useMemo, useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { FOCUS_RING } from '@/lib/focus-ring';
import { LOCALE, formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PivotResult, ReportDimension } from '@/types';

/** Límites de PivotReport (App\Domain\Reports\PivotReport::MAX_ROWS y MAX_COLUMNS). */
export const PIVOT_MAX_ROWS = 200;
export const PIVOT_MAX_COLUMNS = 60;

type Header = { key: string | null; name: string };

export type PivotSort =
    | { by: 'name'; dir: 'asc' | 'desc' }
    | { by: 'total'; dir: 'asc' | 'desc' }
    | { by: 'column'; key: string; dir: 'asc' | 'desc' };

const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

const monthShort = new Intl.DateTimeFormat(LOCALE, {
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

const monthLong = new Intl.DateTimeFormat(LOCALE, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

/**
 * Texto de una cabecera: las semanas por su lunes («Sem. 21/09», «Semana del 21/09/2026»), los
 * meses por su nombre («sept 2026», «septiembre de 2026») y los días por su fecha. El resto, su
 * nombre tal cual llega del servidor.
 */
export function pivotHeaderLabel(
    dimension: ReportDimension,
    header: Header,
): { short: string; full: string } {
    const match = header.key !== null ? DATE_ONLY.exec(header.key) : null;

    if (!match || !['semana', 'mes', 'dia'].includes(dimension)) {
        return { short: header.name, full: header.name };
    }

    const [, year, month, day] = match;
    const date = new Date(
        Date.UTC(Number(year), Number(month) - 1, Number(day)),
    );

    if (dimension === 'mes') {
        return { short: monthShort.format(date), full: monthLong.format(date) };
    }

    const full = formatDate(header.key);

    if (dimension === 'semana') {
        return {
            short: t('reports_r3.table.week', { date: `${day}/${month}` }),
            full: t('reports_r3.table.week_full', { date: full }),
        };
    }

    return { short: full, full };
}

function cell(pivot: PivotResult, row: Header, column: Header): number | null {
    return pivot.cells[row.key ?? '']?.[column.key ?? ''] ?? null;
}

/**
 * Tabla dinámica del informe detallado (SPEC §10.6): filas y columnas de dos dimensiones, horas en
 * h:mm, subtotales por fila (columna «Total») y por columna (fila «Total»), cabeceras y primera
 * columna fijas al desplazarse y orden por cualquier columna (por defecto, el del servidor: de más
 * a menos horas). Las celdas llevan un fondo proporcional a sus horas (--chart-1, solo refuerzo:
 * la cifra siempre está escrita). Si PivotReport recortó filas o columnas, lo avisa.
 */
export function PivotTable({
    pivot,
    rowsDimension,
    columnsDimension,
    caption,
}: {
    pivot: PivotResult;
    rowsDimension: ReportDimension;
    columnsDimension: ReportDimension;
    /** Nombre accesible de la tabla (medida, filas y columnas). */
    caption: string;
}) {
    const [sort, setSort] = useState<PivotSort | null>(null);

    const rows = useMemo(() => {
        if (sort === null) {
            return pivot.rows;
        }

        const value = (row: Header): number | string => {
            if (sort.by === 'name') {
                return row.name;
            }

            if (sort.by === 'total') {
                return pivot.row_totals[row.key ?? ''] ?? 0;
            }

            return pivot.cells[row.key ?? '']?.[sort.key] ?? 0;
        };

        const direction = sort.dir === 'asc' ? 1 : -1;

        return [...pivot.rows].sort((a, b) => {
            const left = value(a);
            const right = value(b);
            const order =
                typeof left === 'string' && typeof right === 'string'
                    ? left.localeCompare(right, LOCALE, { numeric: true })
                    : Number(left) - Number(right);

            return order * direction;
        });
    }, [pivot, sort]);

    const max = useMemo(() => {
        let highest = 0;

        for (const row of pivot.rows) {
            for (const column of pivot.columns) {
                highest = Math.max(highest, cell(pivot, row, column) ?? 0);
            }
        }

        return highest;
    }, [pivot]);

    const toggle = (next: PivotSort) => {
        setSort((current) => {
            const same =
                current !== null &&
                current.by === next.by &&
                (current.by !== 'column' ||
                    (next.by === 'column' && current.key === next.key));

            if (!same) {
                return next;
            }

            return { ...current, dir: current.dir === 'asc' ? 'desc' : 'asc' };
        });
    };

    const ariaSort = (
        by: PivotSort['by'],
        key?: string,
    ): 'ascending' | 'descending' | undefined => {
        if (
            sort === null ||
            sort.by !== by ||
            (sort.by === 'column' && sort.key !== key)
        ) {
            return undefined;
        }

        return sort.dir === 'asc' ? 'ascending' : 'descending';
    };

    const shade = (minutes: number | null): CSSProperties | undefined => {
        if (minutes === null || minutes <= 0 || max <= 0) {
            return undefined;
        }

        const percent = Math.round(4 + (minutes / max) * 14);

        return {
            backgroundColor: `color-mix(in oklab, var(--chart-1) ${percent}%, transparent)`,
        };
    };

    const rowsLabel = t(`reports_r3.dimension.${rowsDimension}`);
    const columnsLabel = t(`reports_r3.dimension.${columnsDimension}`);

    return (
        <div className="grid gap-3">
            {pivot.truncated ? (
                <Alert>
                    <TriangleAlert
                        aria-hidden="true"
                        className="text-warning"
                    />
                    <AlertDescription>
                        {t('reports_r3.table.truncated', {
                            rows: PIVOT_MAX_ROWS,
                            columns: PIVOT_MAX_COLUMNS,
                        })}
                    </AlertDescription>
                </Alert>
            ) : null}

            <div
                className={cn(
                    'max-h-[70vh] overflow-auto rounded-md border bg-card',
                    FOCUS_RING,
                )}
                role="region"
                aria-label={caption}
                tabIndex={0}
                data-test="pivot-table"
            >
                <table className="w-max min-w-full border-separate border-spacing-0 text-sm">
                    <caption className="sr-only">{caption}</caption>
                    <thead>
                        <tr>
                            <th
                                scope="col"
                                aria-sort={ariaSort('name')}
                                className="sticky top-0 left-0 z-30 border-r border-b bg-muted px-3 py-2 text-left font-medium"
                            >
                                <SortButton
                                    label={t('reports_r3.table.corner', {
                                        rows: rowsLabel,
                                        columns: columnsLabel,
                                    })}
                                    sortLabel={rowsLabel}
                                    state={ariaSort('name')}
                                    onClick={() =>
                                        toggle({ by: 'name', dir: 'asc' })
                                    }
                                />
                            </th>
                            {pivot.columns.map((column) => {
                                const label = pivotHeaderLabel(
                                    columnsDimension,
                                    column,
                                );
                                const key = column.key ?? '';

                                return (
                                    <th
                                        key={key}
                                        scope="col"
                                        aria-sort={ariaSort('column', key)}
                                        className="sticky top-0 z-20 border-b bg-muted px-3 py-2 text-right font-medium"
                                    >
                                        <SortButton
                                            label={label.short}
                                            title={label.full}
                                            sortLabel={label.full}
                                            align="right"
                                            state={ariaSort('column', key)}
                                            onClick={() =>
                                                toggle({
                                                    by: 'column',
                                                    key,
                                                    dir: 'desc',
                                                })
                                            }
                                        />
                                    </th>
                                );
                            })}
                            <th
                                scope="col"
                                aria-sort={ariaSort('total')}
                                className="sticky top-0 z-20 border-b border-l bg-muted px-3 py-2 text-right font-medium"
                            >
                                <SortButton
                                    label={t('reports_r3.table.total')}
                                    sortLabel={t('reports_r3.table.total')}
                                    align="right"
                                    state={ariaSort('total')}
                                    onClick={() =>
                                        toggle({ by: 'total', dir: 'desc' })
                                    }
                                />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => {
                            const label = pivotHeaderLabel(rowsDimension, row);
                            const rowKey = row.key ?? '';

                            return (
                                <tr key={rowKey} data-test="pivot-row">
                                    <th
                                        scope="row"
                                        title={label.full}
                                        className="sticky left-0 z-10 max-w-[16rem] border-r border-b bg-card px-3 py-1.5 text-left font-normal break-words"
                                    >
                                        {label.short}
                                    </th>
                                    {pivot.columns.map((column) => {
                                        const minutes = cell(
                                            pivot,
                                            row,
                                            column,
                                        );

                                        return (
                                            <td
                                                key={column.key ?? ''}
                                                style={shade(minutes)}
                                                className="tabular border-b px-3 py-1.5 text-right whitespace-nowrap"
                                            >
                                                <Minutes value={minutes} />
                                            </td>
                                        );
                                    })}
                                    <td className="tabular border-b border-l px-3 py-1.5 text-right font-medium whitespace-nowrap">
                                        {formatMinutes(
                                            pivot.row_totals[rowKey] ?? 0,
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                    <tfoot>
                        <tr data-test="pivot-totals">
                            <th
                                scope="row"
                                className="sticky bottom-0 left-0 z-20 border-t border-r bg-muted px-3 py-2 text-left font-medium"
                            >
                                {t('reports_r3.table.total')}
                            </th>
                            {pivot.columns.map((column) => (
                                <td
                                    key={column.key ?? ''}
                                    className="tabular sticky bottom-0 z-10 border-t bg-muted px-3 py-2 text-right font-medium whitespace-nowrap"
                                >
                                    {formatMinutes(
                                        pivot.column_totals[column.key ?? ''] ??
                                            0,
                                    )}
                                </td>
                            ))}
                            <td className="tabular sticky bottom-0 z-10 border-t border-l bg-muted px-3 py-2 text-right font-medium whitespace-nowrap">
                                {formatMinutes(pivot.total)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

function Minutes({ value }: { value: number | null }) {
    if (value === null || value === 0) {
        return (
            <>
                <span aria-hidden="true" className="text-muted-foreground">
                    –
                </span>
                <span className="sr-only">
                    {t('reports_r3.table.no_hours')}
                </span>
            </>
        );
    }

    return <>{formatMinutes(value)}</>;
}

function SortButton({
    label,
    sortLabel,
    title,
    state,
    align = 'left',
    onClick,
}: {
    label: string;
    /** Qué se ordena, para el nombre accesible («Ordenar por Semana del 21/09/2026»). */
    sortLabel: string;
    title?: string;
    state: 'ascending' | 'descending' | undefined;
    align?: 'left' | 'right';
    onClick: () => void;
}) {
    const Icon =
        state === 'ascending'
            ? ArrowUp
            : state === 'descending'
              ? ArrowDown
              : ArrowUpDown;

    return (
        <button
            type="button"
            onClick={onClick}
            title={title}
            aria-label={`${label}. ${t('reports_r3.table.sort_by', { column: sortLabel })}`}
            className={cn(
                'inline-flex items-center gap-1 rounded-[3px] font-medium whitespace-nowrap hover:text-foreground',
                align === 'right' && 'flex-row-reverse',
                FOCUS_RING,
            )}
        >
            {label}
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5 shrink-0',
                    state === undefined && 'text-muted-foreground',
                )}
            />
        </button>
    );
}
