import { CalendarClock, CalendarMinus, OctagonAlert } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { LoadCell } from '@/components/charts/load-cell';
import type {
    WorkloadCellData,
    WorkloadColumn,
    WorkloadMatrixData,
    WorkloadRow,
    WorkloadTotals,
} from '@/components/workload/types';
import {
    cellAccessibleLabel,
    columnHeading,
    loadSummary,
    periodLabel,
    reasonShort,
} from '@/components/workload/workload-labels';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Clave de una celda: la misma que ?celda=persona:fecha. */
export function cellKey(personId: number, column: WorkloadColumn): string {
    return `${personId}:${column.key}`;
}

type Position = { row: number; col: number };

const NAV_KEYS = new Set([
    'ArrowRight',
    'ArrowLeft',
    'ArrowUp',
    'ArrowDown',
    'Home',
    'End',
    'PageUp',
    'PageDown',
]);

/**
 * Matriz personas × días (o semanas) de la vista Carga (SPEC §9, D-052), agrupada por departamento
 * con totales por departamento, por columna y de toda la vista.
 *
 * Accesibilidad (patrón «data grid» de WAI-ARIA): la tabla es una rejilla con UNA parada de
 * tabulación; las flechas mueven el foco entre las celdas de las personas (Inicio/Fin, a la
 * primera o la última de la fila; con Ctrl, de la matriz; Re Pág/Av Pág, 5 filas) e Intro o
 * Espacio abre el panel de la celda. Cada celda lleva un nombre accesible completo (persona,
 * periodo, cifras, nivel del semáforo, motivo del gris y si lleva tareas vencidas): el color nunca
 * va solo.
 */
export function WorkloadMatrix({
    matrix,
    byWeek,
    from,
    to,
    openKey,
    loading = false,
    onOpen,
}: {
    matrix: WorkloadMatrixData;
    byWeek: boolean;
    from: string;
    to: string;
    /** Celda con el panel abierto (persona:fecha). */
    openKey: string | null;
    loading?: boolean;
    onOpen: (person: WorkloadRow, column: WorkloadColumn) => void;
}) {
    const captionId = useId();
    const helpId = useId();
    const tableRef = useRef<HTMLTableElement>(null);
    const columns = matrix.columns;
    const rows = matrix.groups.flatMap((group) => group.people);
    const [active, setActive] = useState<Position>(() =>
        initialPosition(rows, columns, openKey),
    );
    // La matriz puede encoger al cambiar los filtros: la celda activa siempre existe.
    const current: Position = {
        row: Math.min(active.row, Math.max(rows.length - 1, 0)),
        col: Math.min(active.col, Math.max(columns.length - 1, 0)),
    };

    const focusCell = (position: Position) => {
        setActive(position);
        tableRef.current
            ?.querySelector<HTMLButtonElement>(
                `[data-cell="${position.row}:${position.col}"]`,
            )
            ?.focus();
    };

    const onKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        position: Position,
    ) => {
        if (!NAV_KEYS.has(event.key)) {
            return;
        }

        event.preventDefault();
        const lastRow = rows.length - 1;
        const lastCol = columns.length - 1;
        const ctrl = event.ctrlKey || event.metaKey;
        let { row, col } = position;

        switch (event.key) {
            case 'ArrowRight':
                col = Math.min(col + 1, lastCol);
                break;
            case 'ArrowLeft':
                col = Math.max(col - 1, 0);
                break;
            case 'ArrowDown':
                row = Math.min(row + 1, lastRow);
                break;
            case 'ArrowUp':
                row = Math.max(row - 1, 0);
                break;
            case 'Home':
                col = 0;
                row = ctrl ? 0 : row;
                break;
            case 'End':
                col = lastCol;
                row = ctrl ? lastRow : row;
                break;
            case 'PageDown':
                row = Math.min(row + 5, lastRow);
                break;
            case 'PageUp':
                row = Math.max(row - 5, 0);
                break;
        }

        focusCell({ row, col });
    };

    let rowIndex = -1;

    return (
        <div
            className="min-w-0 overflow-x-auto rounded-md border bg-card"
            data-test="workload-matrix"
        >
            <p id={helpId} className="sr-only">
                {t('workload_matrix.keyboard_help')}
            </p>
            <table
                ref={tableRef}
                role="grid"
                aria-labelledby={captionId}
                aria-describedby={helpId}
                aria-busy={loading || undefined}
                className={cn(
                    'w-full border-separate border-spacing-1 text-sm transition-opacity',
                    loading && 'opacity-60',
                )}
            >
                <caption id={captionId} className="sr-only">
                    {t(
                        byWeek
                            ? 'workload_matrix.caption_weeks'
                            : 'workload_matrix.caption_days',
                        { from: formatDate(from), to: formatDate(to) },
                    )}
                </caption>
                <thead>
                    <tr>
                        <th
                            scope="col"
                            className="sticky left-0 z-10 min-w-36 bg-card px-2 py-1 text-left font-medium"
                        >
                            {t('workload_matrix.person')}
                        </th>
                        {columns.map((column) => {
                            const heading = columnHeading(column, byWeek);

                            return (
                                <th
                                    key={column.key}
                                    scope="col"
                                    aria-current={
                                        column.today ? 'date' : undefined
                                    }
                                    className={cn(
                                        'min-w-24 px-2 py-1 text-left align-bottom font-normal whitespace-nowrap',
                                        column.weekend &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    <span className="sr-only">
                                        {periodLabel(column, byWeek)}
                                    </span>
                                    <span
                                        aria-hidden="true"
                                        className="block text-xs text-muted-foreground capitalize"
                                    >
                                        {heading.top}
                                    </span>
                                    <span
                                        aria-hidden="true"
                                        className="tabular flex items-center gap-1"
                                    >
                                        {heading.bottom}
                                        {column.today ? (
                                            <span className="rounded-[3px] bg-primary px-1 text-[0.65rem] leading-4 text-primary-foreground">
                                                {t('workload_matrix.today')}
                                            </span>
                                        ) : null}
                                    </span>
                                </th>
                            );
                        })}
                        <th
                            scope="col"
                            className="min-w-24 px-2 py-1 text-left align-bottom font-medium"
                        >
                            {t('workload_matrix.total')}
                        </th>
                    </tr>
                </thead>

                {matrix.groups.map((group) => {
                    const name =
                        group.department.name ??
                        t('workload_matrix.no_department');

                    return (
                        <tbody
                            key={group.department.id ?? 'none'}
                            data-test="workload-group"
                        >
                            <tr>
                                <th
                                    scope="colgroup"
                                    colSpan={columns.length + 2}
                                    className="px-2 pt-3 pb-1 text-left font-medium"
                                >
                                    <span className="sticky left-2 inline-flex items-center gap-2">
                                        <span
                                            aria-hidden="true"
                                            className="size-2.5 rounded-full bg-neutral-soft"
                                            style={
                                                group.department.color
                                                    ? {
                                                          backgroundColor:
                                                              group.department
                                                                  .color,
                                                      }
                                                    : undefined
                                            }
                                        />
                                        {name}{' '}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {t('workload_matrix.people_count', {
                                                count: group.people.length,
                                            })}
                                        </span>
                                    </span>
                                </th>
                            </tr>

                            {group.people.map((person) => {
                                rowIndex++;
                                const row = rowIndex;

                                return (
                                    <tr
                                        key={person.id}
                                        data-test="workload-row"
                                    >
                                        <th
                                            scope="row"
                                            className="sticky left-0 z-10 bg-card px-2 py-1 text-left font-normal"
                                        >
                                            <span className="flex items-center gap-1.5">
                                                <span className="truncate">
                                                    {person.name}
                                                </span>{' '}
                                                {person.is_me ? (
                                                    <span className="rounded-[3px] border px-1 text-[0.65rem] leading-4 text-muted-foreground">
                                                        {t(
                                                            'workload_matrix.me',
                                                        )}
                                                    </span>
                                                ) : null}
                                            </span>
                                        </th>
                                        {columns.map((column, col) => (
                                            <td
                                                key={column.key}
                                                className="p-0"
                                            >
                                                <CellButton
                                                    person={person}
                                                    column={column}
                                                    cell={person.cells[col]}
                                                    byWeek={byWeek}
                                                    position={{ row, col }}
                                                    focusable={
                                                        current.row === row &&
                                                        current.col === col
                                                    }
                                                    open={
                                                        openKey ===
                                                        cellKey(
                                                            person.id,
                                                            column,
                                                        )
                                                    }
                                                    onFocus={() =>
                                                        setActive({ row, col })
                                                    }
                                                    onKeyDown={onKeyDown}
                                                    onOpen={() =>
                                                        onOpen(person, column)
                                                    }
                                                />
                                            </td>
                                        ))}
                                        <td className="p-0">
                                            <TotalCell
                                                totals={person.total}
                                                label={t(
                                                    'workload_matrix.person_total',
                                                    { name: person.name },
                                                )}
                                                strong
                                            />
                                        </td>
                                    </tr>
                                );
                            })}

                            <tr data-test="workload-department-total">
                                <th
                                    scope="row"
                                    className="sticky left-0 z-10 bg-card px-2 py-1 text-left text-xs font-medium text-muted-foreground"
                                >
                                    {t('workload_matrix.department_total', {
                                        department: name,
                                    })}
                                </th>
                                {group.totals.map((totals, col) => (
                                    <td key={columns[col].key} className="p-0">
                                        <TotalCell
                                            totals={totals}
                                            label={t(
                                                'workload_matrix.total_of',
                                                {
                                                    name,
                                                    period: periodLabel(
                                                        columns[col],
                                                        byWeek,
                                                    ),
                                                },
                                            )}
                                        />
                                    </td>
                                ))}
                                <td className="p-0">
                                    <TotalCell
                                        totals={group.total}
                                        label={t(
                                            'workload_matrix.person_total',
                                            { name },
                                        )}
                                        strong
                                    />
                                </td>
                            </tr>
                        </tbody>
                    );
                })}

                {matrix.groups.length > 1 ? (
                    <tfoot>
                        <tr data-test="workload-grand-total">
                            <th
                                scope="row"
                                className="sticky left-0 z-10 bg-card px-2 pt-3 pb-1 text-left font-medium"
                            >
                                {t('workload_matrix.grand_total')}
                            </th>
                            {matrix.totals.map((totals, col) => (
                                <td key={columns[col].key} className="p-0 pt-2">
                                    <TotalCell
                                        totals={totals}
                                        label={t('workload_matrix.total_of', {
                                            name: t(
                                                'workload_matrix.grand_total',
                                            ),
                                            period: periodLabel(
                                                columns[col],
                                                byWeek,
                                            ),
                                        })}
                                    />
                                </td>
                            ))}
                            <td className="p-0 pt-2">
                                <TotalCell
                                    totals={matrix.total}
                                    label={t('workload_matrix.person_total', {
                                        name: t('workload_matrix.grand_total'),
                                    })}
                                    strong
                                />
                            </td>
                        </tr>
                    </tfoot>
                ) : null}
            </table>
        </div>
    );
}

function CellButton({
    person,
    column,
    cell,
    byWeek,
    position,
    focusable,
    open,
    onFocus,
    onKeyDown,
    onOpen,
}: {
    person: WorkloadRow;
    column: WorkloadColumn;
    cell: WorkloadCellData;
    byWeek: boolean;
    position: Position;
    focusable: boolean;
    open: boolean;
    onFocus: () => void;
    onKeyDown: (
        event: KeyboardEvent<HTMLButtonElement>,
        position: Position,
    ) => void;
    onOpen: () => void;
}) {
    const label = cellAccessibleLabel(
        person.name,
        periodLabel(column, byWeek),
        cell,
    );
    // Carga en un día sin capacidad (vencidas que caen hoy en festivo, sin días laborables…).
    const loadWithoutCapacity = cell.capacity <= 0 && cell.planned > 0;
    const markers = [cell.reduced, loadWithoutCapacity, cell.overdue].filter(
        Boolean,
    ).length;

    return (
        <button
            type="button"
            data-cell={`${position.row}:${position.col}`}
            data-test="workload-cell"
            tabIndex={focusable ? 0 : -1}
            aria-label={label}
            aria-haspopup="dialog"
            aria-expanded={open}
            title={label}
            onFocus={onFocus}
            onKeyDown={(event) => onKeyDown(event, position)}
            onClick={onOpen}
            className={cn(
                'relative block w-full rounded-[3px] text-left transition-shadow hover:ring-2 hover:ring-border',
                FOCUS_RING,
                open && 'ring-2 ring-ring',
            )}
        >
            <LoadCell
                planned={cell.planned}
                capacity={cell.capacity}
                reason={cell.reason ? reasonShort(cell.reason) : undefined}
                // Hueco para las marcas de la esquina: el texto no se monta encima.
                className={cn(markers === 1 && 'pr-5', markers > 1 && 'pr-9')}
            />
            {markers > 0 ? (
                <span
                    aria-hidden="true"
                    className="absolute top-1 right-1 flex items-center gap-0.5"
                >
                    {cell.reduced ? (
                        <CalendarMinus className="size-3 text-muted-foreground" />
                    ) : null}
                    {loadWithoutCapacity ? (
                        <OctagonAlert className="size-3 text-danger" />
                    ) : null}
                    {cell.overdue ? (
                        <CalendarClock className="size-3 text-danger" />
                    ) : null}
                </span>
            ) : null}
        </button>
    );
}

/** Celda de totales (no interactiva): el semáforo de la suma, con su texto accesible. */
function TotalCell({
    totals,
    label,
    strong = false,
}: {
    totals: WorkloadTotals;
    label: string;
    strong?: boolean;
}) {
    return (
        <div data-test="workload-total">
            <span className="sr-only">{`${label}: ${loadSummary(totals)}`}</span>
            <div aria-hidden="true">
                <LoadCell
                    planned={totals.planned}
                    capacity={totals.capacity}
                    className={cn(!strong && 'bg-transparent')}
                />
            </div>
        </div>
    );
}

function initialPosition(
    rows: WorkloadRow[],
    columns: WorkloadColumn[],
    openKey: string | null,
): Position {
    if (openKey) {
        for (const [row, person] of rows.entries()) {
            const col = columns.findIndex(
                (column) => cellKey(person.id, column) === openKey,
            );

            if (col >= 0) {
                return { row, col };
            }
        }
    }

    return { row: 0, col: 0 };
}
