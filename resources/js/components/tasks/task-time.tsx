import { usePage } from '@inertiajs/react';
import { Plus, Timer } from 'lucide-react';
import { useState } from 'react';
import { TimeEntryStatusBadge } from '@/components/domain/badges';
import { loggedBreakdown } from '@/components/tasks/task-meta';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import {
    formatElapsed,
    useElapsedSeconds,
} from '@/components/time/use-elapsed';
import { Button } from '@/components/ui/button';
import {
    formatDate,
    formatMinutes,
    formatTime,
    formatTimeRange,
} from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TaskPanelData } from '@/types';

/**
 * Temporizador en marcha en esta tarea: desde qué hora y cuánto lleva (D-172). El contador no se
 * anuncia cada segundo: el texto accesible dice desde cuándo.
 */
function RunningTimer({ startedAt }: { startedAt: string }) {
    const seconds = useElapsedSeconds(startedAt);

    return (
        <p
            className="flex items-center gap-1.5 text-sm"
            data-test="task-timer-running"
        >
            <Timer aria-hidden="true" className="size-4 text-primary" />
            <span>
                {t('task_time.timer_running', {
                    time: formatTime(startedAt),
                })}
            </span>
            <span aria-hidden="true" className="tabular text-muted-foreground">
                {formatElapsed(seconds)}
            </span>
        </p>
    );
}

/**
 * Horas de la tarea (SPEC §6 y §7): el registrado (en una tarea con subtareas, el total con su
 * desglose, D-170), las entradas que quien mira puede ver (D-021) con su franja horaria, el
 * temporizador en marcha (el botón de iniciar y parar va en la cabecera del panel) y «Añadir horas» (por duración o con hora de inicio y fin, D-172). Los hitos no
 * llevan horas.
 */
export function TaskTime({ panel }: { panel: TaskPanelData }) {
    const [open, setOpen] = useState(false);
    const task = panel.task;
    const timer = usePage().props.timer ?? null;
    const running = timer?.task_id === task.id ? timer : null;
    const breakdown = loggedBreakdown(task);
    const hasSubtasks = panel.parent === null && panel.subtasks.length > 0;

    return (
        <div className="grid gap-3">
            {breakdown !== null && hasSubtasks ? (
                <dl
                    className="grid grid-cols-3 gap-2 rounded-md border p-3 text-sm"
                    data-test="task-logged-breakdown"
                >
                    <div className="grid gap-0.5">
                        <dt className="text-xs text-muted-foreground">
                            {t('task_time.breakdown.total')}
                        </dt>
                        <dd className="tabular font-medium">
                            {formatMinutes(breakdown.total)}
                            {panel.effective_estimated_minutes !== null ? (
                                <span className="text-xs font-normal text-muted-foreground">
                                    {' '}
                                    {t('task_time.breakdown.of_estimate', {
                                        estimate: formatMinutes(
                                            panel.effective_estimated_minutes,
                                        ),
                                    })}
                                </span>
                            ) : null}
                        </dd>
                    </div>
                    <div className="grid gap-0.5">
                        <dt className="text-xs text-muted-foreground">
                            {t('task_time.breakdown.own')}
                        </dt>
                        <dd className="tabular">
                            {formatMinutes(breakdown.own)}
                        </dd>
                    </div>
                    <div className="grid gap-0.5">
                        <dt className="text-xs text-muted-foreground">
                            {t('task_time.breakdown.subtasks')}
                        </dt>
                        <dd className="tabular">
                            {formatMinutes(breakdown.subtasks)}
                        </dd>
                    </div>
                </dl>
            ) : null}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm">
                    {/* Un colaborador externo no ve el total de todos (D-134): solo lo suyo. */}
                    {task.logged_minutes === null
                        ? t('task_time.own', {
                              visible: formatMinutes(
                                  panel.time_visible_minutes,
                              ),
                          })
                        : t(
                              hasSubtasks
                                  ? 'task_time.total_direct'
                                  : 'task_time.total',
                              {
                                  visible: formatMinutes(
                                      panel.time_visible_minutes,
                                  ),
                                  total: formatMinutes(
                                      task.logged_minutes ?? 0,
                                  ),
                              },
                          )}
                </p>
                {panel.can.log_time ? (
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setOpen(true)}
                        >
                            <Plus aria-hidden="true" />
                            {t('time_entry_dialog.title')}
                        </Button>
                    </div>
                ) : null}
            </div>
            {running ? <RunningTimer startedAt={running.started_at} /> : null}
            {task.is_milestone ? (
                <p className="text-sm text-muted-foreground">
                    {t('task_time.milestone')}
                </p>
            ) : panel.time_entries.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('task_time.empty')}
                </p>
            ) : (
                <ul
                    className="divide-y rounded-md border"
                    aria-label={t('task_time.list')}
                >
                    {panel.time_entries.map((entry) => {
                        const range = formatTimeRange(
                            entry.started_at,
                            entry.ended_at,
                        );

                        return (
                            <li
                                key={entry.id}
                                className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm"
                                data-test="task-time-entry"
                            >
                                <span className="tabular w-24 shrink-0">
                                    {formatDate(entry.date)}
                                    {range !== '' ? (
                                        <span
                                            className="block text-xs text-muted-foreground"
                                            data-test="task-time-range"
                                        >
                                            <span className="sr-only">
                                                {t(
                                                    'task_time.range_label',
                                                )}{' '}
                                            </span>
                                            {range}
                                        </span>
                                    ) : null}
                                </span>
                                <span className="min-w-0 flex-1 truncate">
                                    {entry.user?.name ?? ''}
                                    {entry.description ? (
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {entry.description}
                                        </span>
                                    ) : null}
                                </span>
                                <span className="tabular font-medium">
                                    {formatMinutes(entry.minutes)}
                                </span>
                                <TimeEntryStatusBadge status={entry.status} />
                            </li>
                        );
                    })}
                </ul>
            )}
            {panel.can.log_time ? (
                <TimeEntryDialog
                    open={open}
                    onOpenChange={setOpen}
                    task={{
                        id: task.id,
                        title: task.title,
                        project_id: task.project_id,
                    }}
                />
            ) : null}
        </div>
    );
}
