import { CalendarMinus, ChevronDown, ChevronRight, UserRound } from 'lucide-react';
import { useId, useLayoutEffect, useRef, useState } from 'react';
import type { FocusEvent, KeyboardEvent, MouseEvent, ReactNode } from 'react';
import { LOAD_LEVELS, loadPercent } from '@/components/charts/thresholds';
import { DepartmentColumn } from '@/components/forecast/department-column';
import {
    ForecastCell,
    forecastCellLabel,
    GapCell,
    noCapacityReason,
} from '@/components/forecast/forecast-cell';
import { ForecastCellTooltip } from '@/components/forecast/forecast-cell-tooltip';
import {
    COLLABORATORS_GROUP,
    matrixRows,
    rowCell,
    rowItems,
} from '@/components/forecast/matrix-model';
import type { MatrixGroup, MatrixRow } from '@/components/forecast/matrix-model';
import { FOCUS_RING } from '@/lib/focus-ring';
import {
    bucketLabel,
    cellLevel,
    cellLoad,
    formatHours,
    formatPercentValue,
    initials,
    isCurrentBucket,
    monthShort,
    plural,
} from '@/lib/forecast';
import type { LayerToggles } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ForecastBoard, ForecastPerson } from '@/types/forecast';

/** Ancho de cada columna (D-290): 64 px por semana y 88 px por mes. */
export const COLUMN_WIDTH = { week: 64, month: 88 } as const;
/** Alto del gráfico de la fila de departamento (sin el % de debajo). */
const DEPARTMENT_CHART = 56;

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

export type MatrixTarget = { row: MatrixRow; index: number };

/** Clave de una celda abierta en el panel. */
export function targetKey(row: MatrixRow, index: number): string {
    return `${row.key}@${index}`;
}

type Tip = { row: MatrixRow; index: number; x: number; y: number; above: boolean };

/**
 * Matriz de ocupación de la previsión (D-290): departamentos × semanas (o meses). La fila de cada
 * departamento son columnas apiladas frente a su capacidad con el % debajo; al desplegarla salen sus
 * personas (celda con el semáforo de la Carga y la barra de capas) y la fila «Sin persona» de sus
 * huecos. Los colaboradores externos van en su propio grupo al final (D-300).
 *
 * Accesibilidad (patrón «data grid», como WorkloadMatrix): una sola parada de tabulación; las
 * flechas mueven el foco entre celdas (también a la cabecera de un grupo, a la izquierda), Inicio y
 * Fin van al principio y al final de la fila y Re Pág y Av Pág saltan 5 filas. Intro abre el panel
 * de la celda y Espacio, en la fila de un grupo, lo despliega o lo pliega. El tooltip sale igual con
 * el foco que con el ratón y Escape lo cierra; lo que dice está también en el panel y en la tabla.
 */
export function ForecastMatrix({
    board,
    groups,
    layers,
    isExpanded,
    onToggle,
    openKey,
    onOpen,
    loading = false,
}: {
    board: ForecastBoard;
    groups: MatrixGroup[];
    layers: LayerToggles;
    isExpanded: (key: string) => boolean;
    onToggle: (key: string) => void;
    openKey: string | null;
    onOpen: (target: MatrixTarget) => void;
    loading?: boolean;
}) {
    const captionId = useId();
    const helpId = useId();
    const wrapperRef = useRef<HTMLDivElement>(null);
    const tableRef = useRef<HTMLTableElement>(null);
    const granularity = board.period.granularity;
    const columnWidth = COLUMN_WIDTH[granularity];
    const buckets = board.buckets;
    const rows = matrixRows(groups, isExpanded, layers);
    const [active, setActive] = useState<Position>({ row: 0, col: 0 });
    const [tip, setTip] = useState<Tip | null>(null);
    const current: Position = {
        row: Math.min(active.row, Math.max(rows.length - 1, 0)),
        col: Math.max(Math.min(active.col, buckets.length - 1), rows[active.row]?.kind === 'group' ? -1 : 0),
    };
    const labels = buckets.map((bucket) => bucketLabel(bucket, granularity));

    // Tras plegar o desplegar, el foco sigue en la misma cabecera.
    const pendingFocus = useRef<Position | null>(null);
    useLayoutEffect(() => {
        if (pendingFocus.current) {
            focusCell(pendingFocus.current);
            pendingFocus.current = null;
        }
    });

    const focusCell = (position: Position) => {
        setActive(position);
        tableRef.current
            ?.querySelector<HTMLElement>(`[data-cell="${position.row}:${position.col}"]`)
            ?.focus();
    };

    const showTip = (element: HTMLElement, row: MatrixRow, index: number) => {
        const wrapper = wrapperRef.current;

        if (!wrapper) {
            return;
        }

        const box = wrapper.getBoundingClientRect();
        const cell = element.getBoundingClientRect();
        const above = cell.bottom + 260 > window.innerHeight && cell.top - 260 > 0;

        setTip({
            row,
            index,
            x: Math.min(Math.max(cell.left - box.left + cell.width / 2 - 144, 0), Math.max(box.width - 288, 0)),
            y: above ? cell.top - box.top - 8 : cell.bottom - box.top + 8,
            above,
        });
    };

    const toggle = (row: MatrixRow, position: Position) => {
        if (row.kind !== 'group') {
            return;
        }

        pendingFocus.current = position;
        setTip(null);
        onToggle(row.group.key);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLElement>, position: Position) => {
        const row = rows[position.row];

        if (event.key === 'Escape') {
            setTip(null);

            return;
        }

        if (event.key === ' ' && row?.kind === 'group') {
            event.preventDefault();
            toggle(row, position);

            return;
        }

        if (!NAV_KEYS.has(event.key)) {
            return;
        }

        event.preventDefault();
        const lastRow = rows.length - 1;
        const lastCol = buckets.length - 1;
        let { row: r, col } = position;

        switch (event.key) {
            case 'ArrowRight':
                col = Math.min(col + 1, lastCol);
                break;
            case 'ArrowLeft':
                col -= 1;
                break;
            case 'ArrowDown':
                r = Math.min(r + 1, lastRow);
                break;
            case 'ArrowUp':
                r = Math.max(r - 1, 0);
                break;
            case 'Home':
                col = rows[r]?.kind === 'group' ? -1 : 0;
                r = event.ctrlKey || event.metaKey ? 0 : r;
                break;
            case 'End':
                col = lastCol;
                r = event.ctrlKey || event.metaKey ? lastRow : r;
                break;
            case 'PageDown':
                r = Math.min(r + 5, lastRow);
                break;
            case 'PageUp':
                r = Math.max(r - 5, 0);
                break;
        }

        const minCol = rows[r]?.kind === 'group' ? -1 : 0;
        focusCell({ row: r, col: Math.max(col, minCol) });
    };

    const cellHandlers = (row: MatrixRow, position: Position): CellProps => ({
        'data-cell': `${position.row}:${position.col}`,
        tabIndex: current.row === position.row && current.col === position.col ? 0 : -1,
        onKeyDown: (event: KeyboardEvent<HTMLElement>) => onKeyDown(event, position),
        onFocus: (event: FocusEvent<HTMLElement>) => {
            setActive(position);

            if (position.col >= 0) {
                showTip(event.currentTarget, row, position.col);
            }
        },
        onBlur: () => setTip(null),
        onMouseEnter: (event: MouseEvent<HTMLElement>) => {
            if (position.col >= 0) {
                showTip(event.currentTarget, row, position.col);
            }
        },
        onMouseLeave: () => setTip(null),
    });

    return (
        <div ref={wrapperRef} className="relative min-w-0">
            <p id={helpId} className="sr-only">
                {t('forecast.matrix.keyboard_help')}
            </p>
            <div
                className="min-w-0 overflow-x-auto border bg-card"
                data-test="forecast-matrix"
                onScroll={() => setTip(null)}
            >
                <table
                    ref={tableRef}
                    role="grid"
                    aria-labelledby={captionId}
                    aria-describedby={helpId}
                    aria-busy={loading || undefined}
                    className={cn(
                        'border-separate border-spacing-0 text-sm transition-opacity motion-reduce:transition-none',
                        loading && 'opacity-60',
                    )}
                >
                    <caption id={captionId} className="sr-only">
                        {t('forecast.matrix.caption', {
                            from: labels[0]?.long ?? '',
                            to: labels[labels.length - 1]?.long ?? '',
                        })}
                    </caption>
                    <MatrixHead board={board} labels={labels} columnWidth={columnWidth} />
                    <tbody>
                        {rows.map((row, r) => (
                            <MatrixRowView
                                key={row.key}
                                row={row}
                                rowIndex={r}
                                board={board}
                                layers={layers}
                                labels={labels}
                                columnWidth={columnWidth}
                                expanded={row.kind === 'group' && isExpanded(row.group.key)}
                                openKey={openKey}
                                cellProps={cellHandlers}
                                onOpen={(index) => onOpen({ row, index })}
                                onToggle={(position) => toggle(row, position)}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
            {tip ? (
                <div
                    role="tooltip"
                    className="pointer-events-none absolute z-30"
                    style={{
                        left: tip.x,
                        top: tip.y,
                        transform: tip.above ? 'translateY(-100%)' : undefined,
                    }}
                >
                    <MatrixTooltip board={board} row={tip.row} index={tip.index} layers={layers} label={labels[tip.index]?.long ?? ''} />
                </div>
            ) : null}
        </div>
    );
}

/** Nombre de la fila para los textos (persona, «Sin persona · Diseño» o el grupo). */
export function rowName(row: MatrixRow): string {
    if (row.kind === 'person') {
        return row.person.name;
    }

    if (row.kind === 'gap') {
        return t('forecast.matrix.gap_of', { department: groupName(row.group) });
    }

    return groupName(row.group);
}

export function groupName(group: MatrixGroup): string {
    if (group.kind === 'collaborators') {
        return t('forecast.matrix.collaborators');
    }

    return group.name ?? t('forecast.board.no_department');
}

/** Pie del tooltip y del panel: festivos de la columna y ausencias de la persona. */
export function cellFooter(board: ForecastBoard, row: MatrixRow, index: number): string[] {
    const lines: string[] = [];
    const holidays = board.holidays[index] ?? [];

    if (row.kind !== 'gap' && holidays.length > 0) {
        lines.push(
            plural('forecast.tooltip.holidays_one', 'forecast.tooltip.holidays_other', holidays.length, {
                names: holidays.map((holiday) => holiday.name).join(', '),
            }),
        );
    }

    if (row.kind === 'person') {
        const absence = row.person.absences[index];

        if (absence && absence.days > 0) {
            lines.push(
                absence.type
                    ? plural('forecast.tooltip.absence_type_one', 'forecast.tooltip.absence_type_other', absence.days, {
                          type: t(`absences.type.${absence.type}` as 'absences.type.vacation'),
                      })
                    : plural('forecast.tooltip.absence_one', 'forecast.tooltip.absence_other', absence.days),
            );

            if (absence.partial) {
                lines.push(t('forecast.tooltip.absence_partial'));
            }
        }
    }

    if (row.kind === 'group' && row.group.gaps) {
        const gap = row.group.gaps[index];

        if (gap && gap.real + gap.firm + gap.tentative > 0) {
            lines.push(
                t('forecast.tooltip.includes_gaps', {
                    hours: formatHours(gap.real + gap.firm + gap.tentative),
                }),
            );
        }
    }

    return lines;
}

export function MatrixTooltip({
    board,
    row,
    index,
    layers,
    label,
}: {
    board: ForecastBoard;
    row: MatrixRow;
    index: number;
    layers: LayerToggles;
    label: string;
}) {
    const cell = rowCell(row, index);
    const noSchedule = row.kind === 'person' && !row.person.has_schedule;

    return (
        <ForecastCellTooltip
            title={`${rowName(row)} · ${label}`}
            load={cellLoad(cell, layers)}
            capacity={row.kind === 'gap' || noSchedule ? null : cell.capacity}
            gap={row.kind === 'gap' || noSchedule}
            items={rowItems(board, row, index)}
            layers={layers}
            footer={[
                ...(noSchedule ? [t('forecast.cell.label_no_schedule', { load: formatHours(cellLoad(cell, layers)) })] : []),
                ...cellFooter(board, row, index),
            ]}
        />
    );
}

function MatrixHead({
    board,
    labels,
    columnWidth,
}: {
    board: ForecastBoard;
    labels: ReturnType<typeof bucketLabel>[];
    columnWidth: number;
}) {
    const byWeek = board.period.granularity === 'week';
    const months: { key: string; label: string; span: number }[] = [];

    if (byWeek) {
        for (const bucket of board.buckets) {
            // La semana va con el mes de su jueves (ISO): el del lunes basta para la cabecera.
            const month = bucket.from.slice(0, 7);
            const last = months[months.length - 1];

            if (last && last.key === month) {
                last.span += 1;
            } else {
                months.push({ key: month, label: monthShort(bucket.from), span: 1 });
            }
        }
    }

    const nameHeader = (
        <th
            scope="col"
            rowSpan={byWeek ? 2 : 1}
            className="sticky left-0 z-20 w-[132px] min-w-[132px] border-r px-3 py-1.5 text-left align-bottom md:w-52 md:min-w-52"
        >
            {t('forecast.matrix.person')}
        </th>
    );

    return (
        <thead>
            {byWeek ? (
                <tr>
                    {nameHeader}
                    {months.map((month) => (
                        <th
                            key={month.key}
                            scope="colgroup"
                            colSpan={month.span}
                            className="border-l px-2 pt-1.5 text-left"
                        >
                            {month.label}
                        </th>
                    ))}
                </tr>
            ) : null}
            <tr>
                {byWeek ? null : nameHeader}
                {board.buckets.map((bucket, index) => {
                    const holidays = board.holidays[index] ?? [];
                    const now = isCurrentBucket(bucket, board.period.today);

                    return (
                        <th
                            key={bucket.key}
                            scope="col"
                            aria-current={now ? 'date' : undefined}
                            className={cn(
                                'border-b-2 px-1.5 pt-0.5 pb-1 text-left leading-tight font-normal normal-case',
                                now ? 'border-b-brand' : 'border-b-transparent',
                            )}
                            style={{ width: columnWidth, minWidth: columnWidth }}
                        >
                            <span className="sr-only">{labels[index]?.long}</span>
                            <span aria-hidden="true" className={cn('flex items-center gap-1 text-xs tracking-normal', now ? 'text-primary-text' : 'text-foreground')}>
                                {labels[index]?.short}
                                {holidays.length > 0 ? (
                                    <CalendarMinus className="size-3 text-muted-foreground" data-test="holiday-mark" />
                                ) : null}
                            </span>
                            <span aria-hidden="true" className="block text-[0.6875rem] tracking-normal">
                                {labels[index]?.sub}
                            </span>
                            {holidays.length > 0 ? (
                                <span className="sr-only">
                                    {plural('forecast.tooltip.holidays_one', 'forecast.tooltip.holidays_other', holidays.length, {
                                        names: holidays.map((holiday) => holiday.name).join(', '),
                                    })}
                                </span>
                            ) : null}
                        </th>
                    );
                })}
            </tr>
        </thead>
    );
}

type CellProps = {
    'data-cell': string;
    tabIndex: number;
    onKeyDown: (event: KeyboardEvent<HTMLElement>) => void;
    onFocus: (event: FocusEvent<HTMLElement>) => void;
    onBlur: () => void;
    onMouseEnter: (event: MouseEvent<HTMLElement>) => void;
    onMouseLeave: () => void;
};

type CellPropsFactory = (row: MatrixRow, position: Position) => CellProps;

function MatrixRowView({
    row,
    rowIndex,
    board,
    layers,
    labels,
    columnWidth,
    expanded,
    openKey,
    cellProps,
    onOpen,
    onToggle,
}: {
    row: MatrixRow;
    rowIndex: number;
    board: ForecastBoard;
    layers: LayerToggles;
    labels: ReturnType<typeof bucketLabel>[];
    columnWidth: number;
    expanded: boolean;
    openKey: string | null;
    cellProps: CellPropsFactory;
    onOpen: (index: number) => void;
    onToggle: (position: Position) => void;
}) {
    const name = rowName(row);

    if (row.kind === 'group') {
        return (
            <GroupRow
                row={row}
                rowIndex={rowIndex}
                board={board}
                layers={layers}
                labels={labels}
                columnWidth={columnWidth}
                expanded={expanded}
                openKey={openKey}
                cellProps={cellProps}
                onOpen={onOpen}
                onToggle={onToggle}
            />
        );
    }

    const person = row.kind === 'person' ? row.person : null;

    return (
        <tr data-test={row.kind === 'person' ? 'forecast-person-row' : 'forecast-gap-row'}>
            <th
                scope="row"
                className="sticky left-0 z-10 w-[132px] min-w-[132px] max-w-[132px] border-t border-r bg-card px-3 py-1 text-left font-normal md:w-52 md:min-w-52 md:max-w-52"
            >
                <span className="flex min-w-0 items-center gap-2">
                    {person ? (
                        <PersonAvatar person={person} />
                    ) : (
                        <span
                            aria-hidden="true"
                            className="flex size-6 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground text-muted-foreground"
                        >
                            <UserRound className="size-3" />
                        </span>
                    )}
                    <span className="min-w-0">
                        <span className="block truncate">{person ? person.name : t('forecast.matrix.no_person')}</span>
                        <span className="block truncate text-[0.6875rem] text-muted-foreground">
                            {person ? personSubtitle(person) : t('forecast.matrix.gap_sub', { department: groupName(row.group) })}
                        </span>
                    </span>
                </span>
            </th>
            {board.buckets.map((bucket, index) => {
                const position = { row: rowIndex, col: index };
                const cell = rowCell(row, index);
                const props = cellProps(row, position);
                const open = openKey === targetKey(row, index);
                let label: string;
                let content: ReactNode;

                if (person) {
                    const absence = person.absences[index] ?? null;
                    const reason = noCapacityReason({
                        cell,
                        absence,
                        holidays: (board.holidays[index] ?? []).length,
                        hasSchedule: person.has_schedule,
                    });
                    label = forecastCellLabel({ name, period: labels[index]?.long ?? '', cell, layers, absence, reason });
                    content = <ForecastCell cell={cell} layers={layers} absence={absence} reason={reason} />;
                } else {
                    const load = cellLoad(cell, layers);
                    label = `${name}, ${labels[index]?.long ?? ''}: ${load > 0 ? t('forecast.matrix.gap_label', { hours: formatHours(load) }) : t('forecast.matrix.gap_empty')}`;
                    content = <GapCell minutes={cell} layers={layers} />;
                }

                return (
                    <td key={bucket.key} role="gridcell" className="border-t p-0.5">
                        <button
                            type="button"
                            {...props}
                            aria-label={label}
                            aria-haspopup="dialog"
                            aria-expanded={open}
                            data-test={row.kind === 'person' ? 'forecast-cell-button' : 'forecast-gap-button'}
                            onClick={() => onOpen(index)}
                            className={cn(
                                'block w-full text-left outline-none hover:ring-1 hover:ring-foreground hover:ring-inset focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                                open && 'ring-2 ring-ring ring-inset',
                            )}
                        >
                            {content}
                        </button>
                    </td>
                );
            })}
        </tr>
    );
}

function GroupRow({
    row,
    rowIndex,
    board,
    layers,
    labels,
    columnWidth,
    expanded,
    openKey,
    cellProps,
    onOpen,
    onToggle,
}: {
    row: Extract<MatrixRow, { kind: 'group' }>;
    rowIndex: number;
    board: ForecastBoard;
    layers: LayerToggles;
    labels: ReturnType<typeof bucketLabel>[];
    columnWidth: number;
    expanded: boolean;
    openKey: string | null;
    cellProps: CellPropsFactory;
    onOpen: (index: number) => void;
    onToggle: (position: Position) => void;
}) {
    const group = row.group;
    const name = groupName(group);
    const yMax =
        Math.max(1, ...group.cells.map((cell) => Math.max(cell.capacity, cellLoad(cell, layers)))) * 1.08;
    const Chevron = expanded ? ChevronDown : ChevronRight;
    const headerPosition = { row: rowIndex, col: -1 };
    const headerProps = cellProps(row, headerPosition);
    const firstCapacity = group.cells[0]?.capacity ?? 0;
    const unit = board.period.granularity === 'week' ? 'week' : 'month';
    const barWidth = board.period.granularity === 'week' ? 20 : 28;

    return (
        <tr data-test="forecast-group-row" data-group={group.key}>
            <th
                scope="row"
                className="sticky left-0 z-10 w-[132px] min-w-[132px] max-w-[132px] border-t border-r bg-card px-3 py-2 text-left align-top font-normal md:w-52 md:min-w-52 md:max-w-52"
            >
                <button
                    type="button"
                    {...headerProps}
                    aria-expanded={expanded}
                    data-test="forecast-group-toggle"
                    onClick={() => onToggle(headerPosition)}
                    className={cn('flex w-full min-w-0 items-start gap-1.5 text-left', FOCUS_RING)}
                >
                    <Chevron aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />
                    <span className="min-w-0">
                        <span className="block text-[0.9375rem] leading-snug font-medium break-words">{name}</span>
                        <span className="block text-xs text-muted-foreground">
                            {plural('forecast.matrix.people_one', 'forecast.matrix.people_other', group.size)}
                            {group.kind === 'department' && firstCapacity > 0
                                ? ` · ${t(`forecast.matrix.capacity_${unit}`, { hours: formatHours(firstCapacity) })}`
                                : ''}
                        </span>
                    </span>
                </button>
            </th>
            {board.buckets.map((bucket, index) => {
                const cell = group.cells[index];
                const position = { row: rowIndex, col: index };
                const props = cellProps(row, position);
                const load = cellLoad(cell, layers);
                const level = cellLevel(cell, layers);
                const meta = LOAD_LEVELS[level];
                const Icon = meta.icon;
                const strong = level === 'high' || level === 'over';
                const percent = loadPercent(load, cell.capacity);
                const open = openKey === targetKey(row, index);
                const label = `${name}, ${labels[index]?.long ?? ''}: ${
                    percent === null
                        ? t('forecast.matrix.group_no_capacity', { load: formatHours(load) })
                        : `${formatPercentValue(percent)}, ${meta.label}, ${t('forecast.cell.of', { load: formatHours(load), capacity: formatHours(cell.capacity) })}`
                }`;

                return (
                    <td key={bucket.key} role="gridcell" className="border-t px-0 pt-1">
                        <button
                            type="button"
                            {...props}
                            aria-label={label}
                            aria-haspopup="dialog"
                            aria-expanded={open}
                            data-test="forecast-group-cell"
                            onClick={() => onOpen(index)}
                            className={cn(
                                'block w-full outline-none hover:bg-foreground/5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                                open && 'ring-2 ring-ring ring-inset',
                            )}
                        >
                            <DepartmentColumn
                                cell={cell}
                                previousCapacity={index > 0 ? (group.cells[index - 1]?.capacity ?? null) : null}
                                yMax={yMax}
                                width={columnWidth}
                                height={DEPARTMENT_CHART}
                                barWidth={barWidth}
                                layers={layers}
                            />
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'tabular flex h-[18px] items-center justify-center gap-0.5 text-[0.6875rem] whitespace-nowrap',
                                    strong ? 'font-medium text-foreground' : 'text-muted-foreground',
                                )}
                            >
                                {strong ? <Icon className={cn('size-3', meta.tone)} /> : null}
                                {percent === null ? (load > 0 ? formatHours(load) : '') : formatPercentValue(percent)}
                            </span>
                        </button>
                    </td>
                );
            })}
        </tr>
    );
}

export function PersonAvatar({ person }: { person: Pick<ForecastPerson, 'name' | 'avatar'> }) {
    if (person.avatar) {
        return <img src={person.avatar} alt="" className="size-6 shrink-0 rounded-full object-cover" />;
    }

    return (
        <span
            aria-hidden="true"
            className="flex size-6 shrink-0 items-center justify-center rounded-full bg-neutral-soft text-[0.625rem] font-medium"
        >
            {initials(person.name)}
        </span>
    );
}

export function personSubtitle(person: ForecastPerson): string {
    if (person.collaborator) {
        return person.has_schedule
            ? t('forecast.matrix.collaborator_hours', { hours: formatHours(person.weekly_minutes) })
            : t('forecast.matrix.collaborator_no_schedule');
    }

    return t('forecast.matrix.weekly_hours', { hours: formatHours(person.weekly_minutes) });
}

export { COLLABORATORS_GROUP };
