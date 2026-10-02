/**
 * Textos accesibles del Gantt: el nombre de cada barra lleva todo lo que el diagrama enseña con
 * formas y colores (fechas, responsable, estado, horas y conflictos), para no depender de la vista.
 */
import { progressPercent } from '@/components/gantt/gantt-bars';
import type { Span } from '@/components/gantt/geometry';
import type { GanttDates, GanttTask } from '@/components/gantt/types';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';

export function describeDates(dates: GanttDates, milestone: boolean): string {
    const { start_date: start, due_date: due } = dates;

    if (milestone) {
        const day = due ?? start;

        return day
            ? t('gantt.bar.milestone_date', { date: formatDate(day) })
            : t('gantt.bar.no_dates');
    }

    if (start && due) {
        return t('gantt.bar.range', {
            start: formatDate(start),
            end: formatDate(due),
        });
    }

    if (due) {
        return t('gantt.bar.due_only', { date: formatDate(due) });
    }

    if (start) {
        return t('gantt.bar.start_only', { date: formatDate(start) });
    }

    return t('gantt.bar.no_dates');
}

export function barLabel(
    task: GanttTask,
    dates: GanttDates,
    options: {
        summary?: Span | null;
        /** Títulos de las predecesoras con las que está en conflicto. */
        conflicts?: ReadonlyArray<string>;
        readOnly?: boolean;
        parentTitle?: string | null;
        /** Sin responsable (portal de cliente, F5): ni su nombre ni «Sin asignar». */
        hideAssignee?: boolean;
    } = {},
): string {
    const percent = progressPercent(task);
    const parts = [
        task.title,
        options.parentTitle
            ? t('gantt.bar.subtask_of', { task: options.parentTitle })
            : null,
        options.summary
            ? t('gantt.bar.summary', {
                  start: formatDate(options.summary.start),
                  end: formatDate(options.summary.end),
              })
            : null,
        options.summary && !dates.start_date && !dates.due_date
            ? null
            : describeDates(dates, task.is_milestone),
        task.is_milestone ? t('gantt.bar.is_milestone') : null,
        options.hideAssignee
            ? null
            : task.assignee
              ? t('gantt.bar.assignee', { name: task.assignee.name })
              : t('gantt.bar.unassigned'),
        task.status
            ? t('gantt.bar.status', { status: task.status.name })
            : null,
        percent !== null
            ? t('gantt.bar.progress', {
                  percent,
                  logged: formatMinutes(task.logged_minutes),
                  estimated: formatMinutes(task.estimated_minutes ?? 0),
              })
            : null,
        options.conflicts && options.conflicts.length > 0
            ? t('gantt.bar.conflict', {
                  tasks: options.conflicts
                      .map((title) => `«${title}»`)
                      .join(', '),
              })
            : null,
        options.readOnly ? t('gantt.bar.read_only') : null,
    ];

    return parts.filter((part): part is string => part !== null).join('. ');
}
