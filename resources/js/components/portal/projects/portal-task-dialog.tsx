import { CircleCheck, Diamond } from 'lucide-react';
import type { ReactNode } from 'react';
import { TaskStatusBadge } from '@/components/domain/badges';
import { describeDates } from '@/components/gantt/labels';
import type { GanttTask } from '@/components/gantt/types';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TaskDependencyItem } from '@/types/schedule';

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

/**
 * Detalle de una tarea del Gantt del portal, en solo lectura (el portal no tiene ficha de tarea):
 * estado, fechas, si es hito, su tarea padre y sus dependencias. Sin personas, horas, comentarios ni
 * adjuntos.
 */
export function PortalTaskDialog({
    open,
    task,
    tasks,
    dependencies,
    onOpenChange,
    onCloseAutoFocus,
}: {
    open: boolean;
    /** La última tarea abierta (se conserva mientras se cierra el diálogo). */
    task: GanttTask | null;
    tasks: ReadonlyArray<GanttTask>;
    dependencies: ReadonlyArray<TaskDependencyItem>;
    onOpenChange: (open: boolean) => void;
    onCloseAutoFocus?: (event: Event) => void;
}) {
    const byId = new Map(tasks.map((item) => [item.id, item]));
    const title = (id: number) => byId.get(id)?.title ?? `#${id}`;
    const predecessors = task
        ? dependencies
              .filter((dependency) => dependency.successor_task_id === task.id)
              .map((dependency) => title(dependency.predecessor_task_id))
        : [];
    const successors = task
        ? dependencies
              .filter(
                  (dependency) => dependency.predecessor_task_id === task.id,
              )
              .map((dependency) => title(dependency.successor_task_id))
        : [];
    const parent =
        task?.parent_task_id != null ? title(task.parent_task_id) : null;

    return (
        <Dialog open={open && task !== null} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-lg"
                onCloseAutoFocus={onCloseAutoFocus}
                data-test="portal-task-dialog"
            >
                {task ? (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-start gap-2 break-words">
                                {task.is_milestone ? (
                                    <Diamond
                                        aria-hidden="true"
                                        className="mt-1 size-4 shrink-0 text-muted-foreground"
                                    />
                                ) : null}
                                {task.title}
                            </DialogTitle>
                            <DialogDescription>
                                {describeDates(
                                    {
                                        start_date: task.start_date,
                                        due_date: task.due_date,
                                    },
                                    task.is_milestone,
                                )}
                            </DialogDescription>
                        </DialogHeader>

                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Row label={t('portal_projects.task.status')}>
                                {task.status ? (
                                    <TaskStatusBadge
                                        name={task.status.name}
                                        color={task.status.color}
                                        done={task.status.category === 'done'}
                                    />
                                ) : task.is_completed ? (
                                    <span className="flex items-center gap-1.5">
                                        <CircleCheck
                                            aria-hidden="true"
                                            className="size-4 text-success"
                                        />
                                        {t('portal_projects.task.completed')}
                                    </span>
                                ) : (
                                    '—'
                                )}
                            </Row>
                            <Row label={t('portal_projects.task.type')}>
                                {task.is_milestone
                                    ? t('portal_projects.task.milestone')
                                    : parent
                                      ? t('portal_projects.task.subtask')
                                      : t('portal_projects.task.task')}
                            </Row>
                            <Row label={t('portal_projects.task.start')}>
                                {task.start_date
                                    ? formatDate(task.start_date)
                                    : '—'}
                            </Row>
                            <Row label={t('portal_projects.task.due')}>
                                {task.due_date
                                    ? formatDate(task.due_date)
                                    : '—'}
                            </Row>
                            {parent ? (
                                <Row label={t('portal_projects.task.parent')}>
                                    {parent}
                                </Row>
                            ) : null}
                            <Row label={t('portal_projects.task.predecessors')}>
                                {predecessors.length === 0 ? (
                                    '—'
                                ) : (
                                    <ul className="grid gap-0.5">
                                        {predecessors.map((name, index) => (
                                            <li key={`${name}-${index}`}>
                                                {name}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Row>
                            <Row label={t('portal_projects.task.successors')}>
                                {successors.length === 0 ? (
                                    '—'
                                ) : (
                                    <ul className="grid gap-0.5">
                                        {successors.map((name, index) => (
                                            <li key={`${name}-${index}`}>
                                                {name}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Row>
                        </dl>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
