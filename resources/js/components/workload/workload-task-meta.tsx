import { CalendarClock, CalendarDays, Hourglass, Wallet } from 'lucide-react';
import type { WorkloadTask } from '@/components/workload/types';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Lo que se ve de una tarea en el panel de una celda y en las bandejas: proyecto (con su color),
 * cliente, bolsa, restante frente a la estimación, fechas y si está vencida (con icono y texto).
 */
export function WorkloadTaskMeta({
    task,
    hideMissing = false,
    className,
}: {
    task: WorkloadTask;
    /** Sin «Sin estimación» ni «Sin entrega» (la bandeja «Sin planificar» ya lo señala). */
    hideMissing?: boolean;
    className?: string;
}) {
    return (
        <dl
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground',
                className,
            )}
        >
            <div className="inline-flex min-w-0 items-center gap-1">
                <dt className="sr-only">{t('workload_task.project')}</dt>
                <dd className="inline-flex min-w-0 items-center gap-1">
                    <span
                        aria-hidden="true"
                        className="size-2 shrink-0 rounded-full"
                        style={{ backgroundColor: task.project.color }}
                    />
                    <span className="truncate">
                        {task.project.code} · {task.project.name}
                        {task.client ? ` · ${task.client}` : ''}
                    </span>
                </dd>
            </div>

            <div className="inline-flex items-center gap-1">
                <dt className="inline-flex items-center gap-1">
                    <Wallet aria-hidden="true" className="size-3.5" />
                    <span className="sr-only">{t('workload_task.bank')}</span>
                </dt>
                <dd>{task.hour_bank ?? t('workload_task.no_bank')}</dd>
            </div>

            {hideMissing && !task.estimated_minutes ? null : (
                <div className="inline-flex items-center gap-1">
                    <dt className="inline-flex items-center gap-1">
                        <Hourglass aria-hidden="true" className="size-3.5" />
                        <span className="sr-only">
                            {t('workload_task.remaining_label')}
                        </span>
                    </dt>
                    <dd className="tabular">
                        {task.estimated_minutes
                            ? t('workload_task.remaining', {
                                  remaining: formatMinutes(
                                      task.remaining_minutes,
                                  ),
                                  estimate: formatMinutes(
                                      task.estimated_minutes,
                                  ),
                              })
                            : t('workload_task.no_estimate')}
                    </dd>
                </div>
            )}

            {hideMissing && !task.start_date && !task.due_date ? null : (
                <div className="inline-flex items-center gap-1">
                    <dt className="inline-flex items-center gap-1">
                        <CalendarDays aria-hidden="true" className="size-3.5" />
                        <span className="sr-only">
                            {t('workload_task.dates')}
                        </span>
                    </dt>
                    <dd className="tabular">
                        {task.start_date && task.due_date
                            ? t('workload_task.date_range', {
                                  start: formatDate(task.start_date),
                                  due: formatDate(task.due_date),
                              })
                            : task.due_date
                              ? t('workload_task.due_on', {
                                    date: formatDate(task.due_date),
                                })
                              : t('workload_task.no_due')}
                    </dd>
                </div>
            )}

            {task.overdue ? (
                <div className="inline-flex items-center gap-1 rounded-md bg-danger-soft px-1.5 py-0.5 text-foreground">
                    <dt className="sr-only">{t('workload_task.status')}</dt>
                    <dd className="inline-flex items-center gap-1">
                        <CalendarClock
                            aria-hidden="true"
                            className="size-3.5 text-danger"
                        />
                        {t('workload_task.overdue')}
                    </dd>
                </div>
            ) : null}
        </dl>
    );
}
