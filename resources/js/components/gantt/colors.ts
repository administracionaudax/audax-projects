/**
 * Colores de las barras del Gantt (D-060, D-012), siempre con leyenda y con el nombre en la barra
 * o en su nombre accesible (nunca solo color):
 * - por estado: el color del estado de la tarea (el que elige el admin), en el orden de los estados;
 * - por responsable: --chart-1..6 en orden fijo de aparición (el orden de las filas); del 7.º en
 *   adelante, «Otros» en gris; sin responsable, gris con borde discontinuo.
 */
import { CHART_COLORS } from '@/components/charts/chart-config';
import type {
    GanttColorMode,
    GanttTask,
    GanttTaskStatus,
} from '@/components/gantt/types';
import { t } from '@/lib/i18n';

/** Gris de «Otros» y «Sin responsable» (tokens del tema, claro y oscuro). */
export const NEUTRAL_COLOR = 'var(--muted-foreground)';

export type BarColor = {
    color: string;
    /** Borde discontinuo (sin responsable), para no depender solo del color. */
    dashed: boolean;
};

export type LegendEntry = {
    key: string;
    label: string;
    color: string;
    dashed: boolean;
    /** Estado de categoría «hecha»: la leyenda lo marca con un icono. */
    done?: boolean;
};

export type GanttColors = {
    colorOf: (task: GanttTask) => BarColor;
    legend: LegendEntry[];
};

export function statusColors(
    tasks: ReadonlyArray<GanttTask>,
    statuses: ReadonlyArray<GanttTaskStatus>,
): GanttColors {
    const used = new Set(
        tasks.flatMap((task) => (task.status ? [task.status.id] : [])),
    );

    return {
        colorOf: (task) => ({
            color: task.status?.color ?? NEUTRAL_COLOR,
            dashed: false,
        }),
        legend: statuses
            .filter((status) => used.has(status.id))
            .map((status) => ({
                key: `s-${status.id}`,
                label: status.name,
                color: status.color,
                dashed: false,
                done: status.category === 'done',
            })),
    };
}

export function assigneeColors(tasks: ReadonlyArray<GanttTask>): GanttColors {
    const palette = new Map<number, string>();
    const names = new Map<number, string>();
    let others = false;
    let unassigned = false;

    for (const task of tasks) {
        const assignee = task.assignee;

        if (!assignee) {
            unassigned = true;
            continue;
        }

        if (palette.has(assignee.id) || names.has(assignee.id)) {
            continue;
        }

        if (palette.size < CHART_COLORS.length) {
            palette.set(assignee.id, CHART_COLORS[palette.size]);
        } else {
            others = true;
        }

        names.set(assignee.id, assignee.name);
    }

    const legend: LegendEntry[] = [...palette].map(([id, color]) => ({
        key: `u-${id}`,
        label: names.get(id) ?? '',
        color,
        dashed: false,
    }));

    if (others) {
        legend.push({
            key: 'others',
            label: t('gantt.legend.others'),
            color: NEUTRAL_COLOR,
            dashed: false,
        });
    }

    if (unassigned) {
        legend.push({
            key: 'unassigned',
            label: t('gantt.legend.unassigned'),
            color: NEUTRAL_COLOR,
            dashed: true,
        });
    }

    return {
        colorOf: (task) =>
            task.assignee
                ? {
                      color: palette.get(task.assignee.id) ?? NEUTRAL_COLOR,
                      dashed: false,
                  }
                : { color: NEUTRAL_COLOR, dashed: true },
        legend,
    };
}

export function ganttColors(
    mode: GanttColorMode,
    tasks: ReadonlyArray<GanttTask>,
    statuses: ReadonlyArray<GanttTaskStatus>,
): GanttColors {
    return mode === 'assignee'
        ? assigneeColors(tasks)
        : statusColors(tasks, statuses);
}

/**
 * Relleno suave de la barra: el color mezclado con la superficie, para que el título en tinta de
 * texto cumpla AA sobre cualquier color de estado en los dos temas.
 */
export function barFill(color: string): string {
    return `color-mix(in oklab, ${color} 24%, var(--card))`;
}
