import { Link } from '@inertiajs/react';
import { ListTodo } from 'lucide-react';
import { TaskStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { HourBankTaskRow } from '@/types';

/**
 * Tareas de la bolsa con su estimación, lo imputado EN ESTA bolsa y lo comprometido (lo que
 * falta de las abiertas). Un padre cuya estimación sale de sus subtareas no suma: suman ellas.
 */
export function HourBankTasksTable({
    projectId,
    tasks,
}: {
    projectId: number;
    tasks: HourBankTaskRow[];
}) {
    if (tasks.length === 0) {
        return (
            <EmptyState
                icon={ListTodo}
                title={t('hour_banks.detail.no_tasks')}
                description={t('hour_banks.detail.no_tasks_description')}
            />
        );
    }

    const committed = tasks.reduce(
        (sum, task) => sum + (task.committed_minutes ?? 0),
        0,
    );
    const logged = tasks.reduce((sum, task) => sum + task.logged_minutes, 0);

    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={t('hour_banks.detail.tasks')}
            tabIndex={0}
        >
            <table className="w-full min-w-[44rem] text-sm">
                <caption className="sr-only">
                    {t('hour_banks.detail.tasks')}
                </caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.detail.task')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.detail.status')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.detail.assignee')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('hour_banks.detail.estimated')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('hour_banks.detail.logged')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('hour_banks.detail.committed')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {tasks.map((task) => (
                        <tr
                            key={task.id}
                            className="border-b last:border-0 even:bg-muted"
                        >
                            <th
                                scope="row"
                                className={cn(
                                    'px-3 py-2 text-left font-normal',
                                    task.depth > 0 && 'pl-8',
                                )}
                            >
                                {task.depth > 0 ? (
                                    <span className="sr-only">
                                        {t('hour_banks.detail.subtask')}{' '}
                                    </span>
                                ) : null}
                                <Link
                                    href={urls.task(projectId, task.id)}
                                    className={cn(
                                        'rounded-md text-primary-text hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {task.title}
                                </Link>
                            </th>
                            <td className="px-3 py-2">
                                <TaskStatusBadge
                                    name={task.status.name}
                                    color={task.status.color}
                                    done={task.status.category === 'done'}
                                />
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {task.assignee?.name ?? (
                                    <span className="text-muted-foreground">
                                        {t('hour_banks.detail.unassigned')}
                                    </span>
                                )}
                            </td>
                            <td className="tabular px-3 py-2 text-right">
                                {task.estimated_minutes === null
                                    ? '—'
                                    : formatMinutes(task.estimated_minutes)}
                            </td>
                            <td className="tabular px-3 py-2 text-right">
                                {formatMinutes(task.logged_minutes)}
                            </td>
                            <td className="tabular px-3 py-2 text-right">
                                {task.committed_minutes === null ? (
                                    <span
                                        title={t(
                                            'hour_banks.detail.committed_by_subtasks',
                                        )}
                                    >
                                        <span aria-hidden="true">—</span>
                                        <span className="sr-only">
                                            {t(
                                                'hour_banks.detail.committed_by_subtasks',
                                            )}
                                        </span>
                                    </span>
                                ) : (
                                    formatMinutes(task.committed_minutes)
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t">
                        <th
                            scope="row"
                            colSpan={4}
                            className="px-3 py-2 text-left font-medium"
                        >
                            {t('hour_banks.detail.total')}
                        </th>
                        <td className="tabular px-3 py-2 text-right font-medium">
                            {formatMinutes(logged)}
                        </td>
                        <td className="tabular px-3 py-2 text-right font-medium">
                            {formatMinutes(committed)}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
