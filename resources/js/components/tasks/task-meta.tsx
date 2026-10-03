import {
    CalendarClock,
    Diamond,
    ListTree,
    MessageSquare,
    Paperclip,
} from 'lucide-react';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { todayInMadrid } from '@/lib/week';
import { cn } from '@/lib/utils';
import type { Task, TaskListItem } from '@/types';

/**
 * Piezas pequeñas que comparten la lista, el kanban, el panel y Mis tareas.
 */

/** ¿Vence antes de hoy (Madrid) y sigue abierta? */
export function isOverdue(
    task: Pick<Task, 'due_date' | 'is_completed'>,
    today: string = todayInMadrid(),
): boolean {
    return (
        task.due_date !== null && !task.is_completed && task.due_date < today
    );
}

/** Fechas de la tarea: «01/10/2026 – 15/10/2026», «Vence 15/10/2026» o nada. */
export function TaskDates({
    task,
    today,
    className,
}: {
    task: Pick<Task, 'start_date' | 'due_date' | 'is_completed'>;
    today?: string;
    className?: string;
}) {
    if (!task.start_date && !task.due_date) {
        return (
            <span className={cn('text-muted-foreground', className)}>
                <span aria-hidden="true">—</span>
                <span className="sr-only">{t('task_fields.no_dates')}</span>
            </span>
        );
    }

    const overdue = isOverdue(task, today);

    return (
        <span
            className={cn(
                'tabular inline-flex items-center gap-1 whitespace-nowrap',
                overdue && 'font-medium text-destructive-foreground',
                className,
            )}
        >
            {overdue ? (
                <CalendarClock
                    aria-hidden="true"
                    className="size-3.5 shrink-0"
                />
            ) : null}
            {task.start_date && task.due_date
                ? t('task_fields.date_range', {
                      start: formatDate(task.start_date),
                      due: formatDate(task.due_date),
                  })
                : task.due_date
                  ? t('task_fields.due_on', { date: formatDate(task.due_date) })
                  : t('task_fields.starts_on', {
                        date: formatDate(task.start_date),
                    })}
            {overdue ? (
                <span className="sr-only">{t('task_fields.overdue')}</span>
            ) : null}
        </span>
    );
}

/**
 * Minutos imputados a la tarea y a sus subtareas (las horas de las subtareas suman en el padre);
 * null si quien mira no ve las horas de todos (colaborador externo, D-134).
 */
export function totalLoggedMinutes(task: TaskListItem): number | null {
    if (task.logged_minutes === null) {
        return null;
    }

    return (
        (task.logged_minutes ?? 0) +
        (task.subtasks ?? []).reduce(
            (sum, subtask) => sum + (subtask.logged_minutes ?? 0),
            0,
        )
    );
}

export function TaskEstimate({ task }: { task: TaskListItem }) {
    const minutes = task.effective_estimated_minutes;

    if (minutes === null) {
        return (
            <span className="text-muted-foreground">
                <span aria-hidden="true">—</span>
                <span className="sr-only">{t('task_fields.no_estimate')}</span>
            </span>
        );
    }

    return (
        <span
            className="tabular"
            title={
                task.estimate_from_subtasks
                    ? t('task_fields.estimate_from_subtasks')
                    : undefined
            }
        >
            {formatMinutes(minutes)}
            {task.estimate_from_subtasks ? (
                <>
                    <span aria-hidden="true" className="text-muted-foreground">
                        {' '}
                        Σ
                    </span>
                    <span className="sr-only">
                        {t('task_fields.estimate_from_subtasks')}
                    </span>
                </>
            ) : null}
        </span>
    );
}

/** Indicadores: hito, subtareas, comentarios y adjuntos (icono + número, con texto accesible). */
export function TaskIndicators({ task }: { task: TaskListItem }) {
    const items = [
        task.is_milestone
            ? {
                  key: 'milestone',
                  icon: Diamond,
                  text: t('task_fields.milestone'),
                  value: null,
              }
            : null,
        task.subtasks_count
            ? {
                  key: 'subtasks',
                  icon: ListTree,
                  text: t('task_fields.subtasks_count', {
                      count: task.subtasks_count,
                  }),
                  value: task.subtasks_count,
              }
            : null,
        task.comments_count
            ? {
                  key: 'comments',
                  icon: MessageSquare,
                  text: t('task_fields.comments_count', {
                      count: task.comments_count,
                  }),
                  value: task.comments_count,
              }
            : null,
        task.attachments_count
            ? {
                  key: 'attachments',
                  icon: Paperclip,
                  text: t('task_fields.attachments_count', {
                      count: task.attachments_count,
                  }),
                  value: task.attachments_count,
              }
            : null,
    ].filter((item) => item !== null);

    if (items.length === 0) {
        return null;
    }

    return (
        <span className="inline-flex items-center gap-2 text-xs text-muted-foreground">
            {items.map((item) => (
                <span
                    key={item.key}
                    className="inline-flex items-center gap-0.5"
                    title={item.text}
                >
                    <item.icon aria-hidden="true" className="size-3.5" />
                    {item.value !== null ? (
                        <span aria-hidden="true">{item.value}</span>
                    ) : null}
                    <span className="sr-only">{item.text}</span>
                </span>
            ))}
        </span>
    );
}
