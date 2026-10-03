import { Head, Link } from '@inertiajs/react';
import { CalendarCheck2, ListChecks } from 'lucide-react';
import { PriorityBadge, TaskStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { TaskDates } from '@/components/tasks/task-meta';
import { TimerButton } from '@/components/time/timer-button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { index as myTasksIndex } from '@/routes/my-tasks';
import type { MyTaskItem, MyTasksPageProps, TaskStatus } from '@/types';

function MyTaskRow({
    task,
    status,
    today,
}: {
    task: MyTaskItem;
    status: TaskStatus | undefined;
    today: string;
}) {
    return (
        <li
            className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:gap-4"
            data-test="my-task"
        >
            <div className="min-w-0 flex-1">
                <Link
                    href={urls.task(task.project.id, task.id)}
                    className={cn(
                        'block truncate rounded-md text-sm font-medium hover:underline',
                        FOCUS_RING,
                    )}
                >
                    {task.title}
                </Link>
                <p className="flex min-w-0 flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span className="inline-flex min-w-0 items-center gap-1">
                        <span
                            aria-hidden="true"
                            className="size-2 shrink-0 rounded-full"
                            style={{ backgroundColor: task.project.color }}
                        />
                        <span className="truncate">
                            {task.project.code} · {task.project.name}
                        </span>
                    </span>
                    {task.parent ? (
                        <span className="truncate">
                            {t('my_tasks.in_parent', {
                                task: task.parent.title,
                            })}
                        </span>
                    ) : null}
                    {task.hour_bank ? (
                        <span className="truncate">{task.hour_bank.name}</span>
                    ) : null}
                </p>
            </div>
            <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                {status ? (
                    <TaskStatusBadge
                        name={status.name}
                        color={status.color}
                        done={status.category === 'done'}
                    />
                ) : null}
                {task.priority !== 'normal' ? (
                    <PriorityBadge priority={task.priority} />
                ) : null}
                <TaskDates task={task} today={today} className="text-xs" />
                {task.logged_minutes ? (
                    <span className="tabular text-xs text-muted-foreground">
                        {t('my_tasks.logged', {
                            minutes: formatMinutes(task.logged_minutes),
                        })}
                    </span>
                ) : null}
                {!task.is_milestone ? <TimerButton task={task} /> : null}
            </div>
        </li>
    );
}

/**
 * Mis tareas (SPEC §6, D-037): las tareas abiertas que tengo asignadas, en Vencidas, Hoy,
 * Esta semana, Próximas y Sin fecha, con acceso al temporizador y al panel de cada tarea.
 */
export default function MyTasks({
    sections,
    statuses,
    today,
}: MyTasksPageProps) {
    const statusById = new Map(statuses.map((status) => [status.id, status]));
    const total = sections.reduce(
        (sum, section) => sum + section.tasks.length,
        0,
    );

    return (
        <>
            <Head title={t('nav.my_tasks')} />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('my_tasks.heading')}
                    description={t('my_tasks.description')}
                />

                {total === 0 ? (
                    <EmptyState
                        icon={CalendarCheck2}
                        title={t('my_tasks.empty')}
                        description={t('my_tasks.empty_description')}
                    />
                ) : (
                    sections.map((section) => {
                        const headingId = `my-tasks-${section.key}`;

                        return (
                            <section
                                key={section.key}
                                aria-labelledby={headingId}
                                className="flex flex-col gap-2"
                                data-test={`my-tasks-${section.key}`}
                            >
                                <h2
                                    id={headingId}
                                    className="flex items-center gap-2 text-base font-medium"
                                >
                                    {t(`my_tasks.section.${section.key}`)}
                                    <span className="text-sm font-normal text-muted-foreground">
                                        {t('task_list.group_count', {
                                            count: section.tasks.length,
                                        })}
                                    </span>
                                </h2>
                                {section.tasks.length === 0 ? (
                                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <ListChecks
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        {t(
                                            `my_tasks.section_empty.${section.key}`,
                                        )}
                                    </p>
                                ) : (
                                    <ul className="divide-y border-y">
                                        {section.tasks.map((task) => (
                                            <MyTaskRow
                                                key={task.id}
                                                task={task}
                                                status={statusById.get(
                                                    task.status_id,
                                                )}
                                                today={today}
                                            />
                                        ))}
                                    </ul>
                                )}
                            </section>
                        );
                    })
                )}
            </div>
        </>
    );
}

MyTasks.layout = {
    breadcrumbs: [{ title: t('nav.my_tasks'), href: myTasksIndex() }],
};
