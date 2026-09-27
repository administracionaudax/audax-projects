import { useDraggable } from '@dnd-kit/core';
import {
    ArrowRight,
    CalendarClock,
    Circle,
    CircleCheck,
    CircleDot,
    Diamond,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { UserAvatar } from '@/components/tasks/task-fields';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { addDays } from '@/lib/week';
import type { TaskStatus } from '@/types';
import type { CalendarTask } from '@/types/planning';

const CATEGORY_ICON: Record<TaskStatus['category'], LucideIcon> = {
    todo: Circle,
    in_progress: CircleDot,
    done: CircleCheck,
};

/** Icono del estado (por categoría) con el color del estado: nunca va solo, siempre con su nombre. */
export function StatusIcon({
    status,
    className,
}: {
    status: TaskStatus | undefined;
    className?: string;
}) {
    const Icon = CATEGORY_ICON[status?.category ?? 'todo'];

    return (
        <Icon
            aria-hidden="true"
            className={cn(
                'size-3.5 shrink-0',
                status?.category === 'done' && 'text-success',
                className,
            )}
            style={
                status && status.category !== 'done'
                    ? { color: status.color }
                    : undefined
            }
        />
    );
}

/** «del 05/10/2026 al 09/10/2026», «vence el 09/10/2026» o «sin fecha de entrega». */
export function taskDatesText(
    task: Pick<CalendarTask, 'start_date' | 'due_date'>,
): string {
    if (task.start_date && task.due_date && task.start_date !== task.due_date) {
        return t('planning.chip.range', {
            start: formatDate(task.start_date),
            due: formatDate(task.due_date),
        });
    }

    if (task.due_date) {
        return t('planning.chip.due', { date: formatDate(task.due_date) });
    }

    return task.start_date
        ? t('planning.chip.starts_no_due', {
              date: formatDate(task.start_date),
          })
        : t('planning.chip.no_due');
}

/** Nombre accesible de una tarea del calendario: título, estado, hito, responsable y fechas. */
export function chipLabel(
    task: CalendarTask,
    status: TaskStatus | undefined,
    today: string,
): string {
    const overdue =
        !task.is_completed && task.due_date !== null && task.due_date < today;

    return [
        task.parent_title
            ? t('planning.chip.subtask_of', {
                  task: task.title,
                  parent: task.parent_title,
              })
            : task.title,
        status?.name ?? '',
        task.is_milestone ? t('task_fields.milestone') : '',
        task.assignee
            ? t('planning.chip.assignee', { name: task.assignee.name })
            : t('task_fields.no_assignee'),
        taskDatesText(task),
        overdue ? t('planning.chip.overdue') : '',
    ]
        .filter((part) => part !== '')
        .join('. ');
}

export type ChipMoveHandler = (task: CalendarTask, newDue: string) => void;

/**
 * Una tarea en el calendario: botón que abre su panel. Si se puede editar:
 * - se arrastra a otro día (ratón o pulsación larga en táctil),
 * - con el teclado, las flechas eligen el día nuevo (← → un día, ↑ ↓ una semana), Enter lo
 *   confirma y Escape lo cancela; sin nada elegido, Enter abre la tarea.
 * Mover pasa siempre por reprogramar con propuesta (D-057), conservando la duración.
 */
export function CalendarChip({
    task,
    today,
    dragId,
    canEdit,
    draggable = true,
    showDates = false,
    helpId,
    onOpen,
    onMove,
    onAnnounce,
}: {
    task: CalendarTask;
    today: string;
    /** Id único para el arrastre (la misma tarea puede estar en la rejilla y en otra lista). */
    dragId: string;
    canEdit: boolean;
    draggable?: boolean;
    /** Enseña las fechas y el nombre del estado (vista semana y lista «Sin fecha»). */
    showDates?: boolean;
    helpId?: string;
    onOpen: (taskId: number) => void;
    onMove: ChipMoveHandler;
    onAnnounce: (message: string) => void;
}) {
    const { statusById } = useTaskLookups();
    const status = statusById.get(task.status_id);
    const [offset, setOffset] = useState(0);
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: dragId,
        data: { task },
        disabled: !canEdit || !draggable,
        attributes: { roleDescription: t('planning.chip.role') },
    });
    const base = task.due_date ?? today;
    const target = addDays(base, offset);
    const overdue =
        !task.is_completed && task.due_date !== null && task.due_date < today;

    const choose = (next: number) => {
        setOffset(next);
        onAnnounce(
            next === 0
                ? t('planning.keyboard.back', { task: task.title })
                : t('planning.keyboard.target', {
                      task: task.title,
                      date: formatDate(addDays(base, next)),
                  }),
        );
    };

    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (!canEdit) {
            return;
        }

        const steps: Record<string, number> = {
            ArrowLeft: -1,
            ArrowRight: 1,
            ArrowUp: -7,
            ArrowDown: 7,
        };

        if (event.key in steps) {
            event.preventDefault();
            choose(offset + steps[event.key]);

            return;
        }

        if (offset === 0) {
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            setOffset(0);
            onMove(task, target);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            setOffset(0);
            onAnnounce(t('planning.keyboard.cancelled', { task: task.title }));
        }
    };

    return (
        <button
            type="button"
            ref={setNodeRef}
            {...attributes}
            {...listeners}
            // Si no se puede arrastrar sigue siendo un botón que abre la tarea: nunca «desactivado».
            aria-disabled={undefined}
            aria-label={chipLabel(task, status, today)}
            aria-describedby={canEdit ? helpId : undefined}
            onClick={() => onOpen(task.id)}
            onKeyDown={onKeyDown}
            onBlur={() => setOffset(0)}
            title={chipLabel(task, status, today)}
            className={cn(
                'flex w-full min-w-0 items-center gap-1 rounded-[3px] border bg-card px-1.5 py-0.5 text-left text-xs hover:bg-accent',
                task.is_completed && 'text-muted-foreground',
                overdue && 'border-l-2 border-l-danger',
                offset !== 0 && 'ring-2 ring-ring',
                isDragging && 'opacity-40',
                canEdit && draggable && 'cursor-grab',
                FOCUS_RING,
            )}
            data-test="calendar-chip"
            data-task-id={task.id}
        >
            {task.is_milestone ? (
                <Diamond
                    aria-hidden="true"
                    className="size-3.5 shrink-0 fill-current text-primary-text"
                />
            ) : (
                <StatusIcon status={status} />
            )}
            <span className="min-w-0 flex-1">
                <span
                    className={cn(
                        'block truncate',
                        task.is_completed && 'line-through',
                    )}
                >
                    {task.title}
                </span>
                {showDates ? (
                    <span className="flex flex-wrap items-center gap-x-1.5 text-muted-foreground">
                        {task.is_milestone ? (
                            <StatusIcon status={status} className="size-3" />
                        ) : null}
                        <span>{status?.name}</span>
                        <span className="tabular">{taskDatesText(task)}</span>
                    </span>
                ) : null}
            </span>
            {overdue ? (
                <CalendarClock
                    aria-hidden="true"
                    className="size-3.5 shrink-0 text-danger"
                />
            ) : null}
            {offset !== 0 ? (
                <span
                    aria-hidden="true"
                    className="tabular inline-flex shrink-0 items-center gap-0.5 rounded-[3px] bg-accent px-1 text-foreground"
                    data-test="calendar-chip-target"
                >
                    <ArrowRight className="size-3" />
                    {formatDate(target)}
                </span>
            ) : null}
            {task.assignee ? (
                <UserAvatar user={task.assignee} className="size-4" />
            ) : null}
        </button>
    );
}
