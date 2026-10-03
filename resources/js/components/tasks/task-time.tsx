import { Plus } from 'lucide-react';
import { useState } from 'react';
import { TimeEntryStatusBadge } from '@/components/domain/badges';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import { Button } from '@/components/ui/button';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TaskPanelData } from '@/types';

/**
 * Horas de la tarea (SPEC §6 y §7): las entradas que quien mira puede ver (D-021), el
 * temporizador y «Añadir horas». Los hitos no llevan horas.
 */
export function TaskTime({ panel }: { panel: TaskPanelData }) {
    const [open, setOpen] = useState(false);
    const task = panel.task;

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm">
                    {/* Un colaborador externo no ve el total de todos (D-134): solo lo suyo. */}
                    {task.logged_minutes === null
                        ? t('task_time.own', {
                              visible: formatMinutes(
                                  panel.time_visible_minutes,
                              ),
                          })
                        : t('task_time.total', {
                              visible: formatMinutes(
                                  panel.time_visible_minutes,
                              ),
                              total: formatMinutes(task.logged_minutes ?? 0),
                          })}
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
                    {panel.time_entries.map((entry) => (
                        <li
                            key={entry.id}
                            className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm"
                        >
                            <span className="tabular w-24 shrink-0">
                                {formatDate(entry.date)}
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
                    ))}
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
