import { CircleCheck } from 'lucide-react';
import { memo } from 'react';
import { barFill } from '@/components/gantt/colors';
import {
    BAR_HEIGHT,
    MILESTONE_SIZE,
    ROW_HEIGHT,
    SUMMARY_HEIGHT,
} from '@/components/gantt/geometry';
import type { Box } from '@/components/gantt/geometry';
import type { GanttProject, GanttTask } from '@/components/gantt/types';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { FOCUS_RING } from '@/lib/focus-ring';
import { cn } from '@/lib/utils';

export type BarVariant = 'bar' | 'milestone' | 'summary';

/** Porcentaje de horas imputadas sobre la estimación (null sin estimación). */
export function progressPercent(task: GanttTask): number | null {
    if (!task.estimated_minutes || task.estimated_minutes <= 0) {
        return null;
    }

    return Math.round((task.logged_minutes / task.estimated_minutes) * 100);
}

/**
 * Marca de una tarea en el diagrama: barra (con título, % de horas y responsable si caben),
 * rombo (hito) o resumen de sus subtareas. Es un botón enfocable con tabindex itinerante: el
 * teclado y el ratón los gestiona GanttChart por delegación (data-task-id y data-gantt-part).
 */
export const GanttTaskBar = memo(function GanttTaskBar({
    task,
    variant,
    x,
    width,
    top,
    color,
    dashed,
    active,
    editable,
    linkable,
    saving,
    dragging,
    label,
    describedBy,
}: {
    task: GanttTask;
    variant: BarVariant;
    x: number;
    width: number;
    top: number;
    /** Color de la marca (token o color del estado) y borde discontinuo (sin responsable). */
    color: string;
    dashed: boolean;
    active: boolean;
    /** Se puede mover y redimensionar (no en solo lectura ni en los resúmenes). */
    editable: boolean;
    /** Muestra el conector para crear dependencias arrastrando. */
    linkable: boolean;
    saving: boolean;
    dragging: boolean;
    label: string;
    describedBy: string;
}) {
    const initials = useInitials();
    const common = {
        role: 'button',
        tabIndex: active ? 0 : -1,
        'data-task-id': task.id,
        'data-gantt-part': 'bar',
        'data-test': 'gantt-bar',
        'aria-label': label,
        'aria-describedby': describedBy,
        'aria-busy': saving || undefined,
    } as const;

    if (variant === 'milestone') {
        return (
            <div
                {...common}
                data-variant="milestone"
                className={cn(
                    'group/bar absolute rounded-[2px]',
                    FOCUS_RING,
                    editable && 'cursor-grab',
                    dragging && 'z-10',
                )}
                style={{
                    left: x,
                    top: top + (ROW_HEIGHT - MILESTONE_SIZE) / 2,
                    width: MILESTONE_SIZE,
                    height: MILESTONE_SIZE,
                    touchAction: 'pan-x pan-y',
                }}
            >
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0.5 rotate-45 rounded-[2px] border border-foreground"
                    style={{
                        backgroundColor: color,
                        borderStyle: dashed ? 'dashed' : 'solid',
                    }}
                />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute top-1/2 left-full ml-2 flex -translate-y-1/2 items-center gap-1 text-xs whitespace-nowrap text-foreground"
                >
                    {task.is_completed ? (
                        <CircleCheck className="size-3 text-success" />
                    ) : null}
                    {task.title}
                    {saving ? <Spinner className="size-3" /> : null}
                </span>
                {linkable ? <Connector color={color} /> : null}
            </div>
        );
    }

    if (variant === 'summary') {
        return (
            <div
                {...common}
                data-variant="summary"
                className={cn(
                    'group/bar absolute rounded-[2px] bg-muted-foreground',
                    FOCUS_RING,
                )}
                style={{
                    left: x,
                    top: top + (ROW_HEIGHT - SUMMARY_HEIGHT) / 2 - 2,
                    width: width,
                    height: SUMMARY_HEIGHT,
                }}
            >
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute top-full left-0 border-x-4 border-t-[5px] border-x-transparent border-t-muted-foreground"
                />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute top-full right-0 border-x-4 border-t-[5px] border-x-transparent border-t-muted-foreground"
                />
                {linkable ? (
                    <Connector color="var(--muted-foreground)" />
                ) : null}
            </div>
        );
    }

    const percent = progressPercent(task);
    const showTitle = width >= 44;
    const showPercent = percent !== null && width >= 150;
    const showAssignee = task.assignee !== null && width >= 84;

    return (
        <div
            {...common}
            data-variant="bar"
            className={cn(
                'group/bar absolute flex items-center gap-1 overflow-visible rounded-md border px-1.5 text-xs text-foreground select-none',
                FOCUS_RING,
                editable && 'cursor-grab active:cursor-grabbing',
                dragging && 'z-10',
            )}
            style={{
                left: x,
                top: top + (ROW_HEIGHT - BAR_HEIGHT) / 2,
                width: width,
                height: BAR_HEIGHT,
                backgroundColor: barFill(color),
                borderColor: color,
                borderStyle: dashed ? 'dashed' : 'solid',
                touchAction: 'pan-x pan-y',
            }}
        >
            {task.is_completed && width >= 28 ? (
                <CircleCheck
                    aria-hidden="true"
                    className="pointer-events-none size-3 shrink-0 text-success"
                />
            ) : null}
            {showTitle ? (
                <span
                    aria-hidden="true"
                    className="pointer-events-none min-w-0 truncate"
                >
                    {task.title}
                </span>
            ) : null}
            {showPercent || showAssignee || saving ? (
                <span
                    aria-hidden="true"
                    className="pointer-events-none ml-auto flex shrink-0 items-center gap-1"
                >
                    {showPercent ? (
                        <span className="tabular-nums">{percent} %</span>
                    ) : null}
                    {showAssignee && task.assignee ? (
                        <span className="flex size-4 items-center justify-center rounded-full border bg-card text-[9px] leading-none">
                            {initials(task.assignee.name)}
                        </span>
                    ) : null}
                    {saving ? <Spinner className="size-3" /> : null}
                </span>
            ) : null}
            {percent !== null ? (
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute bottom-0 left-0 h-[3px] rounded-b-[2px]"
                    style={{
                        width: `${Math.min(percent, 100)}%`,
                        backgroundColor: color,
                    }}
                />
            ) : null}
            {editable ? (
                <>
                    <span
                        aria-hidden="true"
                        data-gantt-part="start"
                        className="absolute inset-y-0 -left-1 w-2.5 cursor-ew-resize"
                        style={{ touchAction: 'none' }}
                    />
                    <span
                        aria-hidden="true"
                        data-gantt-part="end"
                        className="absolute inset-y-0 -right-1 w-2.5 cursor-ew-resize"
                        style={{ touchAction: 'none' }}
                    />
                </>
            ) : null}
            {linkable ? <Connector color={color} /> : null}
        </div>
    );
});

/** Conector del final de la barra: arrastrándolo hasta otra barra se crea una dependencia. */
function Connector({ color }: { color: string }) {
    return (
        <span
            aria-hidden="true"
            data-gantt-part="connector"
            data-test="gantt-connector"
            className="absolute top-1/2 -right-4 size-3 -translate-y-1/2 cursor-crosshair rounded-full border-2 bg-card opacity-0 group-hover/bar:opacity-100 group-focus-visible/bar:opacity-100 hover:opacity-100 focus-visible:opacity-100 [@media(pointer:coarse)]:opacity-100"
            style={{ borderColor: color, touchAction: 'none' }}
        />
    );
}

/** Barra resumen de un proyecto (Gantt multiproyecto): de la primera a la última fecha. */
export function GanttProjectBar({
    project,
    box,
    top,
}: {
    project: GanttProject;
    box: Box;
    top: number;
}) {
    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute rounded-[2px] border"
            style={{
                left: box.x,
                top: top + (ROW_HEIGHT - 10) / 2,
                width: box.width,
                height: 10,
                backgroundColor: barFill(project.color),
                borderColor: project.color,
            }}
        />
    );
}
