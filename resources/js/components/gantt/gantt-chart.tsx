import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight, CircleCheck, Diamond } from 'lucide-react';
import type {
    FocusEvent,
    KeyboardEvent,
    PointerEvent,
    Ref,
    MouseEvent as ReactMouseEvent,
} from 'react';
import {
    useId,
    useImperativeHandle,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { GanttArrows } from '@/components/gantt/gantt-arrows';
import type { ArrowItem } from '@/components/gantt/gantt-arrows';
import { GanttProjectBar, GanttTaskBar } from '@/components/gantt/gantt-bars';
import type { BarVariant } from '@/components/gantt/gantt-bars';
import type { GanttColors } from '@/components/gantt/colors';
import { isConflict } from '@/components/gantt/conflicts';
import { GanttHeader } from '@/components/gantt/gantt-header';
import {
    GanttMenuButton,
    GanttTaskMenu,
} from '@/components/gantt/gantt-task-menu';
import type {
    GanttTaskAction,
    MenuDependency,
} from '@/components/gantt/gantt-task-menu';
import {
    applyDelta,
    dateAtScroll,
    dateToX,
    dayCenterX,
    DRAG_THRESHOLD,
    dragDelta,
    HEADER_HEIGHT,
    MILESTONE_SIZE,
    milestoneBox,
    ROW_HEIGHT,
    sameDates,
    spanBox,
    taskSpan,
    weekendBands,
    gridLines,
} from '@/components/gantt/geometry';
import type {
    Box,
    DragMode,
    Point,
    Timeline,
} from '@/components/gantt/geometry';
import { barLabel, describeDates } from '@/components/gantt/labels';
import type { GanttRow } from '@/components/gantt/rows';
import type {
    GanttDates,
    GanttProject,
    GanttTask,
} from '@/components/gantt/types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskDependencyItem } from '@/types/schedule';

export type GanttChartHandle = {
    /** Desplaza el diagrama para que la fecha quede a un tercio del ancho visible. */
    scrollToDate: (date: string, behavior?: ScrollBehavior) => void;
    /**
     * Enfoca la barra de la tarea (p. ej. al cerrar un diálogo abierto desde su menú). Devuelve
     * false si la tarea no tiene barra (sin fechas o en un proyecto plegado).
     */
    focusTask: (taskId: number) => boolean;
};

type Layout = {
    task: GanttTask;
    row: number;
    top: number;
    variant: BarVariant;
    box: Box;
    dates: GanttDates;
};

type PointerState = {
    pointerId: number;
    task: GanttTask;
    mode: DragMode;
    startX: number;
    started: boolean;
    base: GanttDates;
    dates: GanttDates;
};

type MenuState = { taskId: number; origin: 'bar' | 'button' };

/**
 * Virtualización vertical (el Gantt multiproyecto llega a 1.500 tareas): solo se pintan las filas
 * que se ven más un margen, en bloques de CHUNK filas para no repintar en cada píxel de scroll.
 * La fila activa (tabindex itinerante) se pinta siempre.
 */
const OVERSCAN_ROWS = 12;
const CHUNK_ROWS = 8;
const DEFAULT_VISIBLE_ROWS = 30;

export type GanttChartProps = {
    /** Nombre accesible del diagrama. */
    label: string;
    rows: ReadonlyArray<GanttRow>;
    dependencies: ReadonlyArray<TaskDependencyItem>;
    timeline: Timeline;
    today: string;
    colors: GanttColors;
    /** Solo lectura (el portal de la F5, o quien no puede editar). */
    readOnly?: boolean;
    /** Sin responsables en los nombres de las barras (portal de cliente, F5). */
    hideAssignees?: boolean;
    /** Tareas que se están guardando. */
    saving?: ReadonlySet<number>;
    /** Espera antes de guardar los cambios hechos con el teclado (ms). 0: al momento. */
    keyboardCommitDelay?: number;
    onReschedule?: (task: GanttTask, dates: GanttDates) => void;
    onLink?: (predecessor: GanttTask, successor: GanttTask) => void;
    onUnlink?: (dependency: TaskDependencyItem) => void;
    onOpen: (task: GanttTask) => void;
    onAction?: (action: GanttTaskAction, task: GanttTask) => void;
    onToggleProject?: (projectId: number) => void;
    projectHref?: (project: GanttProject) => string;
    handleRef?: Ref<GanttChartHandle>;
};

function datesOf(task: GanttTask): GanttDates {
    return { start_date: task.start_date, due_date: task.due_date };
}

/**
 * Diagrama de Gantt propio (D-060): columna de títulos y cabecera de tiempo pegajosas, scroll
 * propio en las dos direcciones, marca de hoy, fines de semana sombreados (escala día), barras,
 * hitos, resúmenes y flechas de dependencias.
 *
 * Interacción (si no es de solo lectura y la tarea se puede editar, `can.update`):
 * - ratón: arrastrar la barra la mueve; sus bordes cambian el inicio o la entrega; el conector
 *   del final crea una dependencia al soltarlo sobre otra barra; doble clic abre la tarea y el
 *   clic derecho, su menú,
 * - teclado (tabindex itinerante): ↑/↓ cambian de tarea, ←/→ la mueven un día, Mayús + ←/→
 *   cambian la entrega, Alt + Mayús + ←/→ el inicio; Intro guarda el cambio (o abre la tarea),
 *   Esc lo deshace y Mayús + F10 abre el menú.
 * Los cambios de fechas NO se guardan aquí: se entregan a `onReschedule` (propuesta, D-057).
 */
export function GanttChart({
    label,
    rows,
    dependencies,
    timeline,
    today,
    colors,
    readOnly = false,
    hideAssignees = false,
    saving,
    keyboardCommitDelay = 700,
    onReschedule,
    onLink,
    onUnlink,
    onOpen,
    onAction,
    onToggleProject,
    projectHref,
    handleRef,
}: GanttChartProps) {
    const instructionsId = useId();
    const readOnlyInstructionsId = useId();
    const scrollRef = useRef<HTMLDivElement>(null);
    const bodyRef = useRef<HTMLDivElement>(null);
    const sidebarRef = useRef<HTMLDivElement>(null);
    const pointer = useRef<PointerState | null>(null);
    const linkFrom = useRef<{ pointerId: number; task: GanttTask } | null>(
        null,
    );
    const draftRef = useRef<{ task: GanttTask; dates: GanttDates } | null>(
        null,
    );
    const draftTimer = useRef<number | null>(null);
    const anchor = useRef<string | null>(null);
    const initialized = useRef(false);
    const previousScale = useRef(timeline.scale);
    const previousStartDay = useRef(timeline.startDay);
    const menuCloseFocus = useRef<'bar' | 'none' | 'default'>('default');
    // Las barras del último render ya pintado: para enfocar desde efectos y avisos que llegan
    // después (el cierre de un menú o de un diálogo), cuando la tarea puede haberse ido.
    const paintedLayouts = useRef<ReadonlyMap<number, Layout>>(new Map());

    const [activeId, setActiveId] = useState<number | null>(null);
    const [drag, setDrag] = useState<{
        taskId: number;
        dates: GanttDates;
    } | null>(null);
    const [draft, setDraft] = useState<{
        taskId: number;
        dates: GanttDates;
    } | null>(null);
    const [linking, setLinking] = useState<{
        fromId: number;
        from: Point;
        to: Point;
    } | null>(null);
    const [menu, setMenu] = useState<MenuState | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const [scrollRow, setScrollRow] = useState(0);
    const [visibleRows, setVisibleRows] = useState(DEFAULT_VISIBLE_ROWS);
    const pendingFocus = useRef<number | null>(null);

    // Fechas que se pintan: las del arrastre o del teclado mientras duran; si no, las de la tarea.
    const displayDates = (task: GanttTask): GanttDates => {
        if (drag?.taskId === task.id) {
            return drag.dates;
        }

        if (draft?.taskId === task.id) {
            return draft.dates;
        }

        return datesOf(task);
    };

    const tasksById = new Map<number, GanttTask>();
    const layouts = new Map<number, Layout>();
    const order: number[] = [];

    rows.forEach((row, index) => {
        if (row.kind !== 'task') {
            return;
        }

        const task = row.task;
        const dates = displayDates(task);
        const top = index * ROW_HEIGHT;
        tasksById.set(task.id, task);
        order.push(task.id);

        if (row.summary) {
            layouts.set(task.id, {
                task,
                row: index,
                top,
                variant: 'summary',
                box: spanBox(timeline, row.summary),
                dates,
            });

            return;
        }

        const span = taskSpan(dates, task.is_milestone);

        if (!span) {
            return;
        }

        layouts.set(task.id, {
            task,
            row: index,
            top,
            variant: task.is_milestone ? 'milestone' : 'bar',
            box: task.is_milestone
                ? milestoneBox(timeline, span.start)
                : spanBox(timeline, span),
            dates,
        });
    });

    const current =
        activeId !== null && layouts.has(activeId) ? activeId : order[0];

    const firstRow = Math.max(scrollRow - OVERSCAN_ROWS, 0);
    const lastRow = scrollRow + visibleRows + CHUNK_ROWS + OVERSCAN_ROWS;
    const currentRow =
        current !== undefined ? layouts.get(current)?.row : undefined;
    const rendered = (index: number) =>
        (index >= firstRow && index < lastRow) || index === currentRow;

    const canEdit = (task: GanttTask) =>
        !readOnly && task.can.update && !(saving?.has(task.id) ?? false);

    const canMove = (task: GanttTask) =>
        canEdit(task) &&
        onReschedule !== undefined &&
        layouts.get(task.id)?.variant !== 'summary';

    const canLink = (task: GanttTask) => canEdit(task) && onLink !== undefined;

    // Flechas: solo entre filas visibles (un proyecto plegado las oculta).
    const conflictsBySuccessor = new Map<number, string[]>();
    const arrows: ArrowItem[] = [];
    const menuDependencies = new Map<number, MenuDependency[]>();

    for (const dependency of dependencies) {
        const predecessor = layouts.get(dependency.predecessor_task_id);
        const successor = layouts.get(dependency.successor_task_id);

        if (!predecessor || !successor) {
            continue;
        }

        const conflict = isConflict(predecessor.dates, successor.dates);
        const removable =
            onUnlink !== undefined &&
            canEdit(predecessor.task) &&
            canEdit(successor.task);

        if (conflict) {
            const list = conflictsBySuccessor.get(successor.task.id) ?? [];
            list.push(predecessor.task.title);
            conflictsBySuccessor.set(successor.task.id, list);
        }

        const low = Math.min(predecessor.row, successor.row);
        const high = Math.max(predecessor.row, successor.row);

        if (high >= firstRow && low < lastRow) {
            arrows.push({
                dependency,
                from: endPoint(predecessor),
                to: startPoint(successor),
                conflict,
                predecessorTitle: predecessor.task.title,
                successorTitle: successor.task.title,
                removable,
            });
        }

        if (removable) {
            const item = {
                dependency,
                label: t('gantt.menu.dependency_item', {
                    predecessor: predecessor.task.title,
                    successor: successor.task.title,
                }),
            };

            for (const id of [predecessor.task.id, successor.task.id]) {
                menuDependencies.set(id, [
                    ...(menuDependencies.get(id) ?? []),
                    item,
                ]);
            }
        }
    }

    const titles = new Map<number, string>();
    for (const row of rows) {
        if (row.kind === 'task') {
            titles.set(row.task.id, row.task.title);
        }
    }

    const height = rows.length * ROW_HEIGHT;
    const showToday = today >= timeline.start && today <= timeline.end;

    // --- Desplazamiento -------------------------------------------------------------------

    const sidebarWidth = () => sidebarRef.current?.offsetWidth ?? 0;

    const scrollToDate = (date: string, behavior: ScrollBehavior = 'auto') => {
        const element = scrollRef.current;

        if (!element) {
            return;
        }

        const visible = Math.max(element.clientWidth - sidebarWidth(), 0);
        const left = Math.max(dateToX(timeline, date) - visible / 3, 0);

        if (typeof element.scrollTo === 'function') {
            element.scrollTo({ left, behavior });
        } else {
            element.scrollLeft = left;
        }
    };

    const ensureVisible = (layout: Layout) => {
        const element = scrollRef.current;

        if (!element) {
            return;
        }

        const visible = element.clientWidth - sidebarWidth();
        const right = layout.box.x + layout.box.width;

        if (layout.box.x < element.scrollLeft + 16) {
            element.scrollLeft = Math.max(layout.box.x - 24, 0);
        } else if (right > element.scrollLeft + visible - 16) {
            element.scrollLeft = Math.max(
                Math.min(right - visible + 32, layout.box.x - 24),
                0,
            );
        }

        const bottom = layout.top + ROW_HEIGHT;
        const visibleHeight = element.clientHeight - HEADER_HEIGHT;

        if (layout.top < element.scrollTop) {
            element.scrollTop = layout.top;
        } else if (bottom > element.scrollTop + visibleHeight) {
            element.scrollTop = bottom - visibleHeight;
        }
    };

    const barElement = (taskId: number): HTMLElement | null =>
        bodyRef.current?.querySelector<HTMLElement>(
            `[data-task-id="${taskId}"][data-gantt-part="bar"]`,
        ) ?? null;

    const focusTask = (taskId: number): boolean => {
        const layout = paintedLayouts.current.get(taskId);

        if (!layout) {
            return false;
        }

        setActiveId(taskId);
        ensureVisible(layout);
        const element = barElement(taskId);

        if (element) {
            element.focus({ preventScroll: true });
        } else {
            // Fila aún sin pintar (virtualizada): se enfoca tras el siguiente render.
            pendingFocus.current = taskId;
        }

        return true;
    };

    useImperativeHandle(handleRef, () => ({ scrollToDate, focusTask }));

    // Al montar: hoy (o la primera barra) a la vista. Al cambiar de escala: el mismo día a la
    // izquierda que antes. Si cambia el primer día del diagrama (p. ej. al guardar una tarea que
    // era la primera, el servidor recalcula el rango), se compensa para que nada salte.
    useLayoutEffect(() => {
        paintedLayouts.current = layouts;
        const element = scrollRef.current;

        if (!element) {
            return;
        }

        if (pendingFocus.current !== null) {
            const bar = barElement(pendingFocus.current);

            if (bar) {
                pendingFocus.current = null;
                bar.focus({ preventScroll: true });
            }
        }

        const rowsInView = Math.ceil(element.clientHeight / ROW_HEIGHT);

        if (rowsInView > 0 && rowsInView !== visibleRows) {
            setVisibleRows(rowsInView);
        }

        if (!initialized.current) {
            initialized.current = true;
            previousStartDay.current = timeline.startDay;
            const first = order
                .map((id) => layouts.get(id)?.dates)
                .map((dates) => dates?.start_date ?? dates?.due_date)
                .find((date): date is string => typeof date === 'string');
            scrollToDate(showToday ? today : (first ?? timeline.start));

            return;
        }

        if (previousScale.current !== timeline.scale) {
            previousScale.current = timeline.scale;
            previousStartDay.current = timeline.startDay;

            if (anchor.current) {
                element.scrollLeft = Math.max(
                    dateToX(timeline, anchor.current),
                    0,
                );
            }

            return;
        }

        if (previousStartDay.current !== timeline.startDay) {
            const shift =
                (previousStartDay.current - timeline.startDay) *
                timeline.dayWidth;
            previousStartDay.current = timeline.startDay;
            element.scrollLeft = Math.max(element.scrollLeft + shift, 0);
        }
    });

    // --- Teclado ---------------------------------------------------------------------------

    const announce = (text: string) => setAnnouncement(text);

    const clearDraftTimer = () => {
        if (draftTimer.current !== null) {
            window.clearTimeout(draftTimer.current);
            draftTimer.current = null;
        }
    };

    const flushDraft = () => {
        clearDraftTimer();
        const pending = draftRef.current;
        draftRef.current = null;

        if (!pending) {
            return false;
        }

        setDraft(null);

        if (!sameDates(pending.dates, datesOf(pending.task))) {
            onReschedule?.(pending.task, pending.dates);
        }

        return true;
    };

    const cancelDraft = () => {
        clearDraftTimer();
        const pending = draftRef.current;
        draftRef.current = null;
        setDraft(null);

        if (pending) {
            announce(
                t('gantt.announce.cancelled', {
                    task: pending.task.title,
                }),
            );
        }
    };

    const keyboardMove = (task: GanttTask, delta: number, mode: DragMode) => {
        const base =
            draftRef.current?.task.id === task.id
                ? draftRef.current.dates
                : datesOf(task);

        if (draftRef.current && draftRef.current.task.id !== task.id) {
            flushDraft();
        }

        const next = applyDelta(base, delta, mode, task.is_milestone);

        if (sameDates(next, base)) {
            return;
        }

        draftRef.current = { task, dates: next };
        setDraft({ taskId: task.id, dates: next });
        announce(
            t('gantt.announce.moved', {
                task: task.title,
                dates: describeDates(next, task.is_milestone),
            }),
        );
        clearDraftTimer();

        if (keyboardCommitDelay <= 0) {
            flushDraft();
        } else {
            draftTimer.current = window.setTimeout(
                flushDraft,
                keyboardCommitDelay,
            );
        }
    };

    const openMenu = (taskId: number, origin: MenuState['origin']) => {
        flushDraft();
        setActiveId(taskId);
        menuCloseFocus.current = origin === 'bar' ? 'bar' : 'default';
        setMenu({ taskId, origin });
    };

    const taskFromEvent = (target: EventTarget | null): GanttTask | null => {
        const element =
            target instanceof Element
                ? target.closest<HTMLElement>('[data-task-id]')
                : null;
        const id = Number(element?.dataset.taskId);

        return tasksById.get(id) ?? null;
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const target = event.target as HTMLElement;

        if (target.dataset.ganttPart !== 'bar') {
            return;
        }

        const task = taskFromEvent(target);

        if (!task) {
            return;
        }

        const index = order.indexOf(task.id);

        switch (event.key) {
            case 'ArrowUp':
            case 'ArrowDown': {
                event.preventDefault();
                flushDraft();
                const next = order[index + (event.key === 'ArrowUp' ? -1 : 1)];

                if (next !== undefined) {
                    focusTask(next);
                }

                return;
            }
            case 'Home':
            case 'End': {
                event.preventDefault();
                flushDraft();
                const next =
                    event.key === 'Home' ? order[0] : order[order.length - 1];

                if (next !== undefined) {
                    focusTask(next);
                }

                return;
            }
            case 'ArrowLeft':
            case 'ArrowRight': {
                if (event.altKey && !event.shiftKey) {
                    // Alt + flecha es «atrás/adelante» del navegador: no se toca.
                    return;
                }

                event.preventDefault();

                if (!canMove(task)) {
                    announce(
                        layouts.get(task.id)?.variant === 'summary'
                            ? t('gantt.announce.summary', {
                                  task: task.title,
                              })
                            : t('gantt.announce.read_only', {
                                  task: task.title,
                              }),
                    );

                    return;
                }

                const mode: DragMode =
                    event.altKey && event.shiftKey
                        ? 'start'
                        : event.shiftKey
                          ? 'end'
                          : 'move';
                keyboardMove(task, event.key === 'ArrowLeft' ? -1 : 1, mode);

                return;
            }
            case 'Enter':
            case ' ': {
                event.preventDefault();

                if (!flushDraft()) {
                    onOpen(task);
                }

                return;
            }
            case 'Escape': {
                if (draftRef.current) {
                    event.preventDefault();
                    cancelDraft();
                }

                return;
            }
            case 'ContextMenu': {
                event.preventDefault();
                openMenu(task.id, 'bar');

                return;
            }
            case 'F10': {
                if (event.shiftKey) {
                    event.preventDefault();
                    openMenu(task.id, 'bar');
                }

                return;
            }
        }
    };

    const onBlur = (event: FocusEvent<HTMLDivElement>) => {
        const target = event.target as HTMLElement;

        if (
            draftRef.current &&
            target.dataset.taskId === String(draftRef.current.task.id)
        ) {
            flushDraft();
        }
    };

    const onFocus = (event: FocusEvent<HTMLDivElement>) => {
        const target = event.target as HTMLElement;

        if (target.dataset.ganttPart === 'bar') {
            const task = taskFromEvent(target);

            if (task && task.id !== current) {
                setActiveId(task.id);
            }
        }
    };

    // --- Ratón y pantalla táctil (delegación en el cuerpo del diagrama) ---------------------

    const localPoint = (event: PointerEvent<HTMLDivElement>): Point => {
        const rect = bodyRef.current?.getBoundingClientRect();

        return {
            x: event.clientX - (rect?.left ?? 0),
            y: event.clientY - (rect?.top ?? 0),
        };
    };

    const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
        if (event.button !== 0) {
            return;
        }

        const target = event.target as HTMLElement;
        const task = taskFromEvent(target);

        if (!task) {
            return;
        }

        const part =
            target.closest<HTMLElement>('[data-gantt-part]')?.dataset
                .ganttPart ?? 'bar';
        const layout = layouts.get(task.id);
        flushDraft();
        setActiveId(task.id);

        if (!layout) {
            return;
        }

        if (part === 'connector') {
            if (!canLink(task)) {
                return;
            }

            event.preventDefault();
            linkFrom.current = { pointerId: event.pointerId, task };
            const from = endPoint(layout);
            setLinking({ fromId: task.id, from, to: from });
            event.currentTarget.setPointerCapture?.(event.pointerId);

            return;
        }

        if (!canMove(task)) {
            return;
        }

        if (part !== 'bar') {
            event.preventDefault();
        }

        pointer.current = {
            pointerId: event.pointerId,
            task,
            mode: part === 'start' ? 'start' : part === 'end' ? 'end' : 'move',
            startX: event.clientX,
            started: false,
            base: datesOf(task),
            dates: datesOf(task),
        };
    };

    const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
        if (linkFrom.current?.pointerId === event.pointerId) {
            const to = localPoint(event);
            setLinking((previous) => (previous ? { ...previous, to } : null));

            return;
        }

        const state = pointer.current;

        if (!state || state.pointerId !== event.pointerId) {
            return;
        }

        const dx = event.clientX - state.startX;

        if (!state.started) {
            if (Math.abs(dx) < DRAG_THRESHOLD) {
                return;
            }

            state.started = true;
            event.currentTarget.setPointerCapture?.(event.pointerId);
        }

        const dates = applyDelta(
            state.base,
            dragDelta(dx, timeline.dayWidth),
            state.mode,
            state.task.is_milestone,
        );

        if (!sameDates(dates, state.dates)) {
            state.dates = dates;
            setDrag({ taskId: state.task.id, dates });
        }
    };

    const finishLinking = (event: PointerEvent<HTMLDivElement>) => {
        const from = linkFrom.current;
        linkFrom.current = null;
        setLinking(null);

        if (!from || event.type !== 'pointerup') {
            return;
        }

        const element =
            typeof document.elementFromPoint === 'function'
                ? document.elementFromPoint(event.clientX, event.clientY)
                : null;
        const target = taskFromEvent(element);

        if (target && target.id !== from.task.id) {
            onLink?.(from.task, target);
        }
    };

    const onPointerUp = (event: PointerEvent<HTMLDivElement>) => {
        if (linkFrom.current?.pointerId === event.pointerId) {
            finishLinking(event);

            return;
        }

        const state = pointer.current;

        if (!state || state.pointerId !== event.pointerId) {
            return;
        }

        pointer.current = null;
        setDrag(null);

        if (
            event.type === 'pointerup' &&
            state.started &&
            !sameDates(state.dates, state.base)
        ) {
            announce(
                t('gantt.announce.moved', {
                    task: state.task.title,
                    dates: describeDates(state.dates, state.task.is_milestone),
                }),
            );
            onReschedule?.(state.task, state.dates);
        }
    };

    const onDoubleClick = (event: ReactMouseEvent<HTMLDivElement>) => {
        const task = taskFromEvent(event.target);

        if (task) {
            onOpen(task);
        }
    };

    const onContextMenu = (event: ReactMouseEvent<HTMLDivElement>) => {
        const task = taskFromEvent(event.target);

        if (task) {
            event.preventDefault();
            openMenu(task.id, 'bar');
        }
    };

    const onScroll = () => {
        const element = scrollRef.current;

        if (!element) {
            return;
        }

        anchor.current = dateAtScroll(timeline, element.scrollLeft);
        const first = Math.floor(element.scrollTop / ROW_HEIGHT);
        const chunk = first - (first % CHUNK_ROWS);

        if (chunk !== scrollRow) {
            setScrollRow(chunk);
        }
    };

    const action = (kind: GanttTaskAction, task: GanttTask) => {
        // El foco lo lleva quien hace la acción (GanttView): los diálogos se lo quedan y, al
        // quitar las fechas, sigue a la tarea hasta la lista «Sin fechas».
        menuCloseFocus.current = 'none';
        onAction?.(kind, task);
    };

    const hasFocusableBars = order.some((id) => layouts.has(id));

    return (
        <div className="relative min-w-0 rounded-md border bg-card [--gantt-sidebar:9.5rem] sm:[--gantt-sidebar:14rem] lg:[--gantt-sidebar:17rem]">
            <p id={instructionsId} className="sr-only">
                {t('gantt.instructions.edit')}
            </p>
            <p id={readOnlyInstructionsId} className="sr-only">
                {t('gantt.instructions.read_only')}
            </p>
            <p role="status" aria-live="polite" className="sr-only">
                {announcement}
            </p>

            <div
                ref={scrollRef}
                role="region"
                aria-label={label}
                tabIndex={hasFocusableBars ? undefined : 0}
                onScroll={onScroll}
                className={cn(
                    'relative max-h-[70vh] overflow-auto overscroll-contain rounded-md',
                    FOCUS_RING,
                )}
                data-test="gantt-scroll"
            >
                {/* Ancho del contenido (w-max): si no, las celdas pegajosas solo se desplazarían
                    dentro del ancho visible y la columna de títulos se quedaría atrás. */}
                <div
                    className="grid w-max min-w-full"
                    style={{
                        gridTemplateColumns: `var(--gantt-sidebar) ${timeline.width}px`,
                    }}
                >
                    <div
                        className="sticky top-0 left-0 z-30 flex items-end border-r border-b bg-card px-3 pb-1.5 text-xs font-medium text-muted-foreground"
                        style={{ height: HEADER_HEIGHT }}
                    >
                        {t('gantt.column.task')}
                    </div>
                    <div className="sticky top-0 z-20 bg-card">
                        <GanttHeader timeline={timeline} today={today} />
                    </div>

                    <div
                        ref={sidebarRef}
                        className="sticky left-0 z-10 border-r bg-card"
                        style={{ height }}
                    >
                        {rows.map((row, index) =>
                            !rendered(index) ? null : row.kind === 'project' ? (
                                <ProjectSidebarRow
                                    key={row.key}
                                    top={index * ROW_HEIGHT}
                                    project={row.project}
                                    collapsed={row.collapsed}
                                    scheduledCount={row.scheduledCount}
                                    unscheduledCount={row.unscheduledCount}
                                    href={projectHref?.(row.project)}
                                    onToggle={onToggleProject}
                                />
                            ) : (
                                <div
                                    key={row.key}
                                    className="absolute inset-x-0 flex items-center gap-1 border-b pr-1 text-sm"
                                    style={{
                                        top: index * ROW_HEIGHT,
                                        height: ROW_HEIGHT,
                                        paddingLeft: row.depth === 1 ? 28 : 10,
                                    }}
                                    data-test="gantt-row"
                                >
                                    {row.task.is_milestone ? (
                                        <Diamond
                                            aria-hidden="true"
                                            className="size-3.5 shrink-0 text-muted-foreground"
                                        />
                                    ) : null}
                                    {row.task.is_completed ? (
                                        <CircleCheck
                                            aria-hidden="true"
                                            className="size-3.5 shrink-0 text-success"
                                        />
                                    ) : null}
                                    <button
                                        type="button"
                                        tabIndex={-1}
                                        onClick={() => onOpen(row.task)}
                                        className="min-w-0 flex-1 truncate rounded-md text-left hover:underline"
                                        title={row.task.title}
                                    >
                                        {row.task.title}
                                    </button>
                                    {row.task.id === current ? (
                                        <GanttTaskMenu
                                            task={row.task}
                                            open={menu?.taskId === row.task.id}
                                            onOpenChange={(open) => {
                                                if (!open) {
                                                    setMenu(null);
                                                } else {
                                                    openMenu(
                                                        row.task.id,
                                                        'button',
                                                    );
                                                }
                                            }}
                                            editable={canEdit(row.task)}
                                            hasDates={
                                                row.task.start_date !== null ||
                                                row.task.due_date !== null
                                            }
                                            dependencies={
                                                menuDependencies.get(
                                                    row.task.id,
                                                ) ?? []
                                            }
                                            onOpen={onOpen}
                                            onAction={
                                                onAction ? action : undefined
                                            }
                                            onUnlink={
                                                onUnlink
                                                    ? (dependency) => {
                                                          menuCloseFocus.current =
                                                              'bar';
                                                          onUnlink(dependency);
                                                      }
                                                    : undefined
                                            }
                                            onCloseAutoFocus={(event) => {
                                                const focus =
                                                    menuCloseFocus.current;
                                                menuCloseFocus.current =
                                                    'default';

                                                if (focus === 'none') {
                                                    event.preventDefault();
                                                } else if (focus === 'bar') {
                                                    event.preventDefault();
                                                    focusTask(row.task.id);
                                                }
                                            }}
                                        />
                                    ) : (
                                        <GanttMenuButton
                                            task={row.task}
                                            onOpenMenu={() =>
                                                openMenu(row.task.id, 'button')
                                            }
                                        />
                                    )}
                                </div>
                            ),
                        )}
                    </div>

                    <div
                        ref={bodyRef}
                        role="group"
                        aria-label={t('gantt.bars_label')}
                        className="relative"
                        style={{
                            width: timeline.width,
                            height,
                            backgroundImage: `repeating-linear-gradient(to bottom, transparent 0 ${ROW_HEIGHT - 1}px, var(--border) ${ROW_HEIGHT - 1}px ${ROW_HEIGHT}px)`,
                        }}
                        onKeyDown={onKeyDown}
                        onFocus={onFocus}
                        onBlur={onBlur}
                        onPointerDown={onPointerDown}
                        onPointerMove={onPointerMove}
                        onPointerUp={onPointerUp}
                        onPointerCancel={onPointerUp}
                        onDoubleClick={onDoubleClick}
                        onContextMenu={onContextMenu}
                    >
                        {weekendBands(timeline).map((band) => (
                            <div
                                key={band.x}
                                aria-hidden="true"
                                className="pointer-events-none absolute inset-y-0 bg-muted"
                                style={{ left: band.x, width: band.width }}
                            />
                        ))}
                        {gridLines(timeline).map((x) => (
                            <div
                                key={x}
                                aria-hidden="true"
                                className="pointer-events-none absolute inset-y-0 w-px bg-border/60"
                                style={{ left: x }}
                            />
                        ))}
                        {rows.map((row, index) =>
                            row.kind === 'project' && rendered(index) ? (
                                <div
                                    key={row.key}
                                    aria-hidden="true"
                                    className="pointer-events-none absolute inset-x-0 bg-muted/60"
                                    style={{
                                        top: index * ROW_HEIGHT,
                                        height: ROW_HEIGHT - 1,
                                    }}
                                />
                            ) : null,
                        )}
                        {showToday ? (
                            <div
                                aria-hidden="true"
                                className="pointer-events-none absolute inset-y-0 z-[1] w-0.5 -translate-x-1/2 bg-primary"
                                style={{ left: dayCenterX(timeline, today) }}
                                data-test="gantt-today"
                            />
                        ) : null}

                        {rows.map((row, index) =>
                            row.kind === 'project' &&
                            row.span &&
                            rendered(index) ? (
                                <GanttProjectBar
                                    key={row.key}
                                    project={row.project}
                                    box={spanBox(timeline, row.span)}
                                    top={index * ROW_HEIGHT}
                                />
                            ) : null,
                        )}

                        <GanttArrows
                            items={arrows}
                            width={timeline.width}
                            height={height}
                            linking={
                                linking
                                    ? { from: linking.from, to: linking.to }
                                    : null
                            }
                            onUnlink={onUnlink}
                        />

                        {[...layouts.values()].map((layout) => {
                            if (
                                !rendered(layout.row) &&
                                drag?.taskId !== layout.task.id
                            ) {
                                return null;
                            }

                            const editable = canMove(layout.task);
                            const color = colors.colorOf(layout.task);

                            return (
                                <GanttTaskBar
                                    key={layout.task.id}
                                    task={layout.task}
                                    variant={layout.variant}
                                    x={layout.box.x}
                                    width={layout.box.width}
                                    top={layout.top}
                                    color={color.color}
                                    dashed={color.dashed}
                                    active={layout.task.id === current}
                                    editable={editable}
                                    linkable={canLink(layout.task)}
                                    saving={
                                        saving?.has(layout.task.id) ?? false
                                    }
                                    dragging={drag?.taskId === layout.task.id}
                                    label={barLabel(layout.task, layout.dates, {
                                        summary:
                                            layout.variant === 'summary'
                                                ? summaryOf(rows, layout)
                                                : null,
                                        conflicts:
                                            conflictsBySuccessor.get(
                                                layout.task.id,
                                            ) ?? [],
                                        readOnly: !canEdit(layout.task),
                                        hideAssignee: hideAssignees,
                                        parentTitle:
                                            layout.task.parent_task_id !== null
                                                ? (titles.get(
                                                      layout.task
                                                          .parent_task_id,
                                                  ) ?? null)
                                                : null,
                                    })}
                                    describedBy={
                                        canEdit(layout.task)
                                            ? instructionsId
                                            : readOnlyInstructionsId
                                    }
                                />
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
}

function summaryOf(rows: ReadonlyArray<GanttRow>, layout: Layout) {
    const row = rows[layout.row];

    return row?.kind === 'task' ? row.summary : null;
}

/** Punto de salida de una flecha: el final de la barra (o el lado derecho del rombo). */
function endPoint(layout: Layout): Point {
    const y = layout.top + ROW_HEIGHT / 2;

    return layout.variant === 'milestone'
        ? { x: layout.box.x + MILESTONE_SIZE, y }
        : { x: layout.box.x + layout.box.width, y };
}

/** Punto de llegada: el inicio de la barra (o el lado izquierdo del rombo). */
function startPoint(layout: Layout): Point {
    return { x: layout.box.x, y: layout.top + ROW_HEIGHT / 2 };
}

function ProjectSidebarRow({
    top,
    project,
    collapsed,
    scheduledCount,
    unscheduledCount,
    href,
    onToggle,
}: {
    top: number;
    project: GanttProject;
    collapsed: boolean;
    scheduledCount: number;
    unscheduledCount: number;
    href?: string;
    onToggle?: (projectId: number) => void;
}) {
    const Icon = collapsed ? ChevronRight : ChevronDown;
    const summary =
        unscheduledCount > 0
            ? t('gantt.project.counts_unscheduled', {
                  count: scheduledCount,
                  unscheduled: unscheduledCount,
              })
            : t('gantt.project.counts', { count: scheduledCount });

    return (
        <div
            className="absolute inset-x-0 flex items-center gap-1 border-b bg-muted/60 pr-1 pl-1 text-sm"
            style={{ top, height: ROW_HEIGHT }}
            data-test="gantt-project-row"
        >
            <button
                type="button"
                aria-expanded={!collapsed}
                aria-label={
                    collapsed
                        ? t('gantt.project.expand', { project: project.name })
                        : t('gantt.project.collapse', { project: project.name })
                }
                onClick={() => onToggle?.(project.id)}
                className={cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                    FOCUS_RING,
                )}
            >
                <Icon aria-hidden="true" className="size-4" />
            </button>
            <span
                aria-hidden="true"
                className="size-2.5 shrink-0 rounded-full"
                style={{ backgroundColor: project.color }}
            />
            <div className="min-w-0 flex-1 leading-tight">
                {href ? (
                    <Link
                        href={href}
                        className={cn(
                            'block truncate rounded-md font-medium text-foreground hover:underline',
                            FOCUS_RING,
                        )}
                        title={`${project.code} · ${project.name}`}
                    >
                        {project.name}
                    </Link>
                ) : (
                    <span className="block truncate font-medium">
                        {project.name}
                    </span>
                )}
                <span className="block truncate text-xs text-muted-foreground">
                    {project.code} · {summary}
                </span>
            </div>
        </div>
    );
}
