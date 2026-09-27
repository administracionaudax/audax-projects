import { router } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import { ganttColors } from '@/components/gantt/colors';
import { ConflictDialog } from '@/components/gantt/conflict-dialog';
import { conflictingDependencies } from '@/components/gantt/conflicts';
import { DatesDialog } from '@/components/gantt/dates-dialog';
import { DependencyDialog } from '@/components/gantt/dependency-dialog';
import { GanttChart } from '@/components/gantt/gantt-chart';
import type { GanttChartHandle } from '@/components/gantt/gantt-chart';
import { GanttLegend } from '@/components/gantt/gantt-legend';
import { GanttTable } from '@/components/gantt/gantt-table';
import type { GanttTaskAction } from '@/components/gantt/gantt-task-menu';
import { GanttToolbar } from '@/components/gantt/gantt-toolbar';
import type { GanttViewMode } from '@/components/gantt/gantt-toolbar';
import { createTimeline, unionSpan } from '@/components/gantt/geometry';
import {
    buildPortfolioRows,
    buildTaskRows,
    spanOfTask,
} from '@/components/gantt/rows';
import type {
    GanttPreferences,
    GanttProject,
    GanttRange,
    GanttTask,
    GanttTaskStatus,
} from '@/components/gantt/types';
import { UnscheduledList } from '@/components/gantt/unscheduled-list';
import { useGanttEditing } from '@/components/gantt/use-gantt-editing';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import type { TaskDependencyItem } from '@/types/schedule';

export type GanttViewProps = {
    /** Nombre del diagrama (y título de la tabla). */
    label: string;
    tasks: ReadonlyArray<GanttTask>;
    dependencies: ReadonlyArray<TaskDependencyItem>;
    statuses: ReadonlyArray<GanttTaskStatus>;
    range: GanttRange;
    today: string;
    preferences: GanttPreferences;
    /** Al cambiar la escala o los colores (para llevarlo a la URL). */
    onPreferencesChange?: (preferences: GanttPreferences) => void;
    /** Props que se recargan tras cada cambio (recarga parcial de Inertia). */
    reload: string[];
    /** Solo lectura: sin arrastrar, sin menú de edición (portal de cliente, F5). */
    readOnly?: boolean;
    /** Gantt multiproyecto: agrupa las tareas por proyecto (plegables). */
    projects?: ReadonlyArray<GanttProject>;
    projectHref?: (project: GanttProject) => string;
    /** Lista «Sin fechas» (Gantt de proyecto). */
    showUnscheduled?: boolean;
    keyboardCommitDelay?: number;
    onOpenTask?: (task: GanttTask) => void;
    /** Qué enseñar si ninguna tarea tiene fechas. */
    emptyChart?: ReactNode;
};

/**
 * Gantt completo (D-060): controles, leyenda, diagrama o tabla, lista «Sin fechas» y los diálogos
 * (conflicto al mover, fechas y dependencias). Lo usan la pestaña Gantt del proyecto y el Gantt
 * multiproyecto; con `readOnly`, también el portal (F5).
 */
export function GanttView({
    label,
    tasks,
    dependencies,
    statuses,
    range,
    today,
    preferences,
    onPreferencesChange,
    reload,
    readOnly = false,
    projects,
    projectHref,
    showUnscheduled = false,
    keyboardCommitDelay,
    onOpenTask,
    emptyChart,
}: GanttViewProps) {
    const chart = useRef<GanttChartHandle>(null);
    const editing = useGanttEditing({ tasks, reload });
    const [scale, setScale] = useState(preferences.scale);
    const [color, setColor] = useState(preferences.color);
    const [view, setView] = useState<GanttViewMode>('chart');
    const [collapsed, setCollapsed] = useState<ReadonlySet<number>>(
        () => new Set(),
    );
    const [datesFor, setDatesFor] = useState<GanttTask | null>(null);
    const [dependencyFor, setDependencyFor] = useState<GanttTask | null>(null);

    // Barra a la que vuelve el foco al cerrar un diálogo abierto desde el diagrama.
    const [returnFocus, setReturnFocus] = useState<number | null>(null);

    if (editing.conflict && editing.conflict.task.id !== returnFocus) {
        setReturnFocus(editing.conflict.task.id);
    }

    const effective = editing.tasks;
    const portfolio = projects
        ? buildPortfolioRows(projects, effective, collapsed)
        : null;
    const single = projects ? null : buildTaskRows(effective);
    const rows = portfolio?.rows ?? single?.rows ?? [];
    const unscheduled = single?.unscheduled ?? [];
    const rowTasks = rows.flatMap((row) =>
        row.kind === 'task' ? [row.task] : [],
    );
    const colors = ganttColors(color, rowTasks, statuses);
    const tasksById = new Map(effective.map((task) => [task.id, task]));
    const conflicts = conflictingDependencies(dependencies, tasksById);
    const parents = new Map(effective.map((task) => [task.id, task.title]));

    const visibleRange =
        unionSpan([
            { start: range.start, end: range.end },
            { start: today, end: today },
            ...effective.map(spanOfTask),
        ]) ?? range;
    const timeline = createTimeline(visibleRange, scale);

    const open = (task: GanttTask) => {
        if (onOpenTask) {
            onOpenTask(task);

            return;
        }

        router.visit(urls.task(task.project_id, task.id));
    };

    const changePreferences = (next: GanttPreferences) => {
        setScale(next.scale);
        setColor(next.color);
        onPreferencesChange?.(next);
    };

    const action = (kind: GanttTaskAction, task: GanttTask) => {
        setReturnFocus(task.id);

        if (kind === 'dates') {
            setDatesFor(task);
        } else if (kind === 'dependency') {
            setDependencyFor(task);
        } else {
            void editing.reschedule(task, {
                start_date: null,
                due_date: null,
            });
        }
    };

    const backToChart = (event: Event) => {
        if (returnFocus !== null && view === 'chart') {
            event.preventDefault();
            chart.current?.focusTask(returnFocus);
        }
    };

    const hasRows = rows.length > 0;

    return (
        <div className="flex min-w-0 flex-col gap-4">
            <GanttToolbar
                scale={scale}
                color={color}
                view={view}
                onScaleChange={(next) =>
                    changePreferences({ scale: next, color })
                }
                onColorChange={(next) =>
                    changePreferences({ scale, color: next })
                }
                onViewChange={setView}
                onToday={() => chart.current?.scrollToDate(today, 'smooth')}
            />

            {hasRows ? (
                <GanttLegend mode={color} entries={colors.legend} />
            ) : null}

            {conflicts.size > 0 ? (
                <p
                    className="flex items-start gap-2 text-sm text-foreground"
                    data-test="gantt-conflicts"
                >
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-destructive-foreground"
                    />
                    {conflicts.size === 1
                        ? t('gantt.conflicts.one')
                        : t('gantt.conflicts.many', { count: conflicts.size })}
                </p>
            ) : null}

            {!hasRows ? (
                emptyChart
            ) : view === 'table' ? (
                <GanttTable
                    caption={label}
                    rows={rows}
                    tasks={effective}
                    dependencies={dependencies}
                    onOpen={open}
                />
            ) : (
                <GanttChart
                    handleRef={chart}
                    label={label}
                    rows={rows}
                    dependencies={dependencies}
                    timeline={timeline}
                    today={today}
                    colors={colors}
                    readOnly={readOnly}
                    saving={editing.saving}
                    keyboardCommitDelay={keyboardCommitDelay}
                    onReschedule={
                        readOnly
                            ? undefined
                            : (task, dates) =>
                                  void editing.reschedule(task, dates)
                    }
                    onLink={
                        readOnly
                            ? undefined
                            : (predecessor, successor) =>
                                  editing.link(predecessor, successor)
                    }
                    onUnlink={readOnly ? undefined : editing.unlink}
                    onOpen={open}
                    onAction={readOnly ? undefined : action}
                    onToggleProject={(projectId) =>
                        setCollapsed((previous) => {
                            const next = new Set(previous);

                            if (next.has(projectId)) {
                                next.delete(projectId);
                            } else {
                                next.add(projectId);
                            }

                            return next;
                        })
                    }
                    projectHref={projectHref}
                />
            )}

            {showUnscheduled ? (
                <UnscheduledList
                    tasks={unscheduled}
                    parents={parents}
                    readOnly={readOnly}
                    onAssign={(task) => {
                        setReturnFocus(null);
                        setDatesFor(task);
                    }}
                    onOpen={open}
                />
            ) : null}

            {readOnly ? null : (
                <>
                    <ConflictDialog
                        conflict={editing.conflict}
                        resolving={editing.resolving}
                        onChoose={editing.resolveConflict}
                        onCloseAutoFocus={backToChart}
                    />
                    <DatesDialog
                        task={datesFor}
                        onOpenChange={(isOpen) => {
                            if (!isOpen) {
                                setDatesFor(null);
                            }
                        }}
                        onSave={(task, dates) =>
                            void editing.reschedule(task, dates)
                        }
                        onCloseAutoFocus={backToChart}
                    />
                    <DependencyDialog
                        task={dependencyFor}
                        candidates={effective}
                        dependencies={dependencies}
                        onLink={(predecessor, successor, callbacks) =>
                            editing.link(predecessor, successor, callbacks)
                        }
                        onUnlink={editing.unlink}
                        onOpenChange={(isOpen) => {
                            if (!isOpen) {
                                setDependencyFor(null);
                            }
                        }}
                        onCloseAutoFocus={backToChart}
                    />
                </>
            )}
        </div>
    );
}
