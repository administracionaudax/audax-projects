import { useDraggable } from '@dnd-kit/core';
import { ArrowRight, CalendarClock, Diamond } from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { anchorDay } from '@/components/calendar/calendar-query';
import { StatusIcon, taskDatesText } from '@/components/planning/calendar-chip';
import { UserAvatar } from '@/components/tasks/task-fields';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { addDays } from '@/lib/week';
import type { TaskStatus, UserSummary } from '@/types';
import type { TeamCalendarProject, TeamCalendarTask } from '@/types/calendar';

/** Llevar una tarea a otro día; `keepFocus`: se movió con el teclado (el foco la sigue). */
export type TeamMoveHandler = (
    task: TeamCalendarTask,
    newDay: string,
    keepFocus?: boolean,
) => void;

/** Lo que comparten todas las tarjetas del calendario. */
export type TeamChipContext = {
    today: string;
    /** Hay un cambio de día en curso: no se mueve otra hasta que acabe. */
    locked: boolean;
    helpId: string;
    statusById: Map<number, TaskStatus>;
    projectById: Map<number, TeamCalendarProject>;
    assigneeById: Map<number, UserSummary>;
    onOpen: (taskId: number) => void;
    onMove: TeamMoveHandler;
    onAnnounce: (message: string) => void;
};

/** Nombre accesible: título, proyecto, estado, hito, responsable, fechas y si va con retraso. */
export function teamChipLabel(
    task: TeamCalendarTask,
    context: Pick<
        TeamChipContext,
        'statusById' | 'projectById' | 'assigneeById' | 'today'
    >,
): string {
    const status = context.statusById.get(task.status_id);
    const project = context.projectById.get(task.project_id);
    const assignee =
        task.assignee_id === null
            ? undefined
            : context.assigneeById.get(task.assignee_id);
    const overdue =
        !task.is_completed &&
        task.due_date !== null &&
        task.due_date < context.today;

    return [
        task.parent_title
            ? t('planning.chip.subtask_of', {
                  task: task.title,
                  parent: task.parent_title,
              })
            : task.title,
        project ? `${project.code} · ${project.name}` : '',
        status?.name ?? '',
        task.is_milestone ? t('task_fields.milestone') : '',
        assignee
            ? t('planning.chip.assignee', { name: assignee.name })
            : t('task_fields.no_assignee'),
        taskDatesText(task),
        overdue ? t('planning.chip.overdue') : '',
    ]
        .filter((part) => part !== '')
        .join('. ');
}

/**
 * Tarjeta de una tarea en el calendario del equipo (D-144): color del proyecto (borde
 * izquierdo), título, código del proyecto, estado con icono y texto (nunca solo color), el rombo de
 * los hitos y el avatar o las iniciales del responsable. Pulsarla abre su panel. Quien puede
 * editar la tarea (TaskPolicy::update) la cambia de día:
 * - arrastrándola (ratón, o pulsación larga en táctil),
 * - con el teclado: ← → un día, ↑ ↓ una semana, Intro confirma y Escape cancela.
 * Siempre pasa por la propuesta de desplazar sucesoras (D-057).
 */
export function TeamChip({
    task,
    dragId,
    context,
    draggable = true,
    compact = false,
}: {
    task: TeamCalendarTask;
    /** Id único del arrastre (la misma tarea puede estar en varias listas). */
    dragId: string;
    context: TeamChipContext;
    draggable?: boolean;
    /** Sin la segunda línea de fechas (mes). */
    compact?: boolean;
}) {
    const status = context.statusById.get(task.status_id);
    const project = context.projectById.get(task.project_id);
    const assignee =
        task.assignee_id === null
            ? undefined
            : context.assigneeById.get(task.assignee_id);
    const canEdit = project?.can_update === true;
    const [offset, setOffset] = useState(0);
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: dragId,
        data: { task },
        disabled: !canEdit || !draggable || context.locked,
        attributes: { roleDescription: t('planning.chip.role') },
    });
    const base = anchorDay(task) ?? context.today;
    const target = addDays(base, offset);
    const overdue =
        !task.is_completed &&
        task.due_date !== null &&
        task.due_date < context.today;
    const label = teamChipLabel(task, context);

    const choose = (next: number) => {
        setOffset(next);
        context.onAnnounce(
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

            if (context.locked) {
                context.onAnnounce(t('planning.keyboard.busy'));
            } else {
                choose(offset + steps[event.key]);
            }

            return;
        }

        if (offset === 0) {
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            setOffset(0);
            context.onMove(task, target, true);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            setOffset(0);
            context.onAnnounce(
                t('planning.keyboard.cancelled', { task: task.title }),
            );
        }
    };

    return (
        <button
            type="button"
            ref={setNodeRef}
            {...attributes}
            {...listeners}
            aria-disabled={undefined}
            aria-roledescription={
                canEdit ? attributes['aria-roledescription'] : undefined
            }
            aria-label={label}
            aria-describedby={canEdit ? context.helpId : undefined}
            title={label}
            onClick={() => context.onOpen(task.id)}
            onKeyDown={onKeyDown}
            onBlur={() => setOffset(0)}
            className={cn(
                'flex w-full min-w-0 items-start gap-1.5 border border-l-[3px] bg-card px-1.5 py-1 text-left text-xs hover:bg-accent',
                task.is_completed && 'text-muted-foreground',
                offset !== 0 && 'ring-2 ring-ring',
                isDragging && 'opacity-40',
                canEdit && draggable && !context.locked && 'cursor-grab',
                FOCUS_RING,
            )}
            style={{ borderLeftColor: project?.color }}
            data-test="team-chip"
            data-task-id={task.id}
        >
            <span className="min-w-0 flex-1">
                <span className="flex min-w-0 items-center gap-1">
                    {task.is_milestone ? (
                        <Diamond
                            aria-hidden="true"
                            className="size-3.5 shrink-0 fill-current text-primary-text"
                        />
                    ) : null}
                    <span
                        className={cn(
                            'truncate',
                            task.is_completed && 'line-through',
                        )}
                    >
                        {task.title}
                    </span>
                </span>
                <span className="flex min-w-0 flex-wrap items-center gap-x-1.5 text-muted-foreground">
                    {project ? (
                        <span className="tabular shrink-0">{project.code}</span>
                    ) : null}
                    <span className="inline-flex min-w-0 items-center gap-0.5">
                        <StatusIcon status={status} className="size-3" />
                        <span className="truncate">{status?.name}</span>
                    </span>
                    {!compact ? (
                        <span className="tabular">{taskDatesText(task)}</span>
                    ) : null}
                </span>
            </span>
            {overdue ? (
                <CalendarClock
                    aria-hidden="true"
                    className="mt-0.5 size-3.5 shrink-0 text-danger"
                />
            ) : null}
            {offset !== 0 ? (
                <span
                    aria-hidden="true"
                    className="tabular inline-flex shrink-0 items-center gap-0.5 bg-accent px-1 text-foreground"
                    data-test="team-chip-target"
                >
                    <ArrowRight className="size-3" />
                    {formatDate(target)}
                </span>
            ) : null}
            {assignee ? (
                <UserAvatar user={assignee} className="size-5" />
            ) : null}
        </button>
    );
}
