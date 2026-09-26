import { Head, Link, setLayoutProps, useForm } from '@inertiajs/react';
import {
    Building,
    CircleAlert,
    FolderKanban,
    ListChecks,
    Timer,
    TriangleAlert,
    UserX,
} from 'lucide-react';
import { useId, useState } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import {
    deactivate,
    deactivation,
    edit,
    index as usersIndex,
} from '@/routes/admin/users';
import type { AdminUserDeactivateProps } from '@/types';

type DeactivationForm = {
    default_assignee_id: string;
    assignments: { task_id: number; assignee_user_id: string }[];
};

/**
 * Asistente de baja (SPEC §14). Enseña lo que la persona deja pendiente (tareas abiertas,
 * temporizador, departamentos y proyectos que dirige) y deja elegir a quién pasan sus tareas:
 * todas a la vez o una a una. Al confirmar se para su temporizador, se reasignan las tareas y
 * pierde el acceso; su historial de horas no cambia.
 */
export default function AdminUserDeactivate({
    user,
    blocked,
    tasks,
    timer,
    candidates,
    managedDepartments,
    ownedProjects,
}: AdminUserDeactivateProps) {
    const id = useId();
    const [bulk, setBulk] = useState('');
    const form = useForm<DeactivationForm>({
        default_assignee_id: '',
        assignments: tasks.map((task) => ({
            task_id: task.id,
            assignee_user_id: '',
        })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const generalError = errors.user ?? errors.default_assignee_id;

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.admin'), href: adminIndex() },
            { title: t('admin.users.title'), href: usersIndex() },
            { title: user.name, href: edit(user.id) },
            {
                title: t('admin.deactivation.title'),
                href: deactivation(user.id),
            },
        ],
    });

    const applyToAll = (value: string) => {
        setBulk(value);
        form.setData({
            default_assignee_id: value,
            assignments: form.data.assignments.map((row) => ({
                ...row,
                assignee_user_id: value,
            })),
        });
    };

    const assign = (taskId: number, value: string) => {
        form.setData(
            'assignments',
            form.data.assignments.map((row) =>
                row.task_id === taskId
                    ? { ...row, assignee_user_id: value }
                    : row,
            ),
        );
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            default_assignee_id:
                data.default_assignee_id === ''
                    ? null
                    : Number(data.default_assignee_id),
            assignments: data.assignments.map((row) => ({
                task_id: row.task_id,
                assignee_user_id:
                    row.assignee_user_id === ''
                        ? null
                        : Number(row.assignee_user_id),
            })),
        }));
        form.post(deactivate.url(user.id), { preserveScroll: true });
    };

    const options = (
        <>
            <option value="">{t('admin.deactivation.unassigned')}</option>
            {candidates.map((candidate) => (
                <option key={candidate.id} value={String(candidate.id)}>
                    {candidate.name}
                </option>
            ))}
        </>
    );

    return (
        <>
            <Head
                title={t('admin.deactivation.page_title', { name: user.name })}
            />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight break-words">
                        {t('admin.deactivation.heading', { name: user.name })}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('admin.deactivation.description')}
                    </p>
                </header>

                {blocked ? (
                    <div
                        role="alert"
                        className="flex items-start gap-2 rounded-md bg-danger-soft px-3 py-2 text-sm text-foreground"
                    >
                        <CircleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-danger"
                        />
                        {blocked}
                    </div>
                ) : null}

                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <h2 className="flex items-center gap-2 text-base font-medium">
                                    <ListChecks
                                        aria-hidden="true"
                                        className="size-4 text-muted-foreground"
                                    />
                                    {t('admin.deactivation.tasks_title', {
                                        count: tasks.length,
                                    })}
                                </h2>
                            </CardTitle>
                            <CardDescription>
                                {t('admin.deactivation.tasks_description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            {tasks.length === 0 ? (
                                <EmptyState
                                    icon={ListChecks}
                                    title={t('admin.deactivation.no_tasks')}
                                />
                            ) : (
                                <>
                                    <div className="grid max-w-md gap-2">
                                        <Label htmlFor={`${id}-bulk`}>
                                            {t('admin.deactivation.bulk_label')}
                                        </Label>
                                        <NativeSelect
                                            id={`${id}-bulk`}
                                            value={bulk}
                                            onChange={(event) =>
                                                applyToAll(event.target.value)
                                            }
                                            aria-describedby={`${id}-bulk-help`}
                                        >
                                            {options}
                                        </NativeSelect>
                                        <p
                                            id={`${id}-bulk-help`}
                                            className="text-sm text-muted-foreground"
                                        >
                                            {t('admin.deactivation.bulk_help')}
                                        </p>
                                    </div>

                                    <div
                                        className={cn(
                                            'overflow-x-auto rounded-md border',
                                            FOCUS_RING,
                                        )}
                                        role="region"
                                        aria-label={t(
                                            'admin.deactivation.table_label',
                                        )}
                                        tabIndex={0}
                                    >
                                        <table className="w-full min-w-[36rem] text-sm">
                                            <caption className="sr-only">
                                                {t(
                                                    'admin.deactivation.table_label',
                                                )}
                                            </caption>
                                            <thead>
                                                <tr className="border-b text-left">
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2 font-medium"
                                                    >
                                                        {t(
                                                            'admin.deactivation.columns.task',
                                                        )}
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2 font-medium"
                                                    >
                                                        {t(
                                                            'admin.deactivation.columns.due',
                                                        )}
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2 font-medium"
                                                    >
                                                        {t(
                                                            'admin.deactivation.columns.assignee',
                                                        )}
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {tasks.map((task, index) => {
                                                    const selectId = `${id}-task-${task.id}`;
                                                    const error =
                                                        errors[
                                                            `assignments.${index}.assignee_user_id`
                                                        ];

                                                    return (
                                                        <tr
                                                            key={task.id}
                                                            className="border-b last:border-b-0 even:bg-muted"
                                                            data-test="deactivation-task"
                                                        >
                                                            <td className="px-3 py-2">
                                                                <Link
                                                                    href={urls.task(
                                                                        task
                                                                            .project
                                                                            .id,
                                                                        task.id,
                                                                    )}
                                                                    className={cn(
                                                                        'rounded-sm hover:underline',
                                                                        FOCUS_RING,
                                                                    )}
                                                                >
                                                                    {task.title}
                                                                </Link>
                                                                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                                    <span
                                                                        aria-hidden="true"
                                                                        className="size-2 rounded-full"
                                                                        style={{
                                                                            backgroundColor:
                                                                                task
                                                                                    .project
                                                                                    .color,
                                                                        }}
                                                                    />
                                                                    {
                                                                        task
                                                                            .project
                                                                            .code
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {
                                                                        task
                                                                            .project
                                                                            .name
                                                                    }
                                                                </span>
                                                            </td>
                                                            <td className="tabular px-3 py-2 whitespace-nowrap">
                                                                {task.due_date
                                                                    ? formatDate(
                                                                          task.due_date,
                                                                      )
                                                                    : '—'}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <label
                                                                    htmlFor={
                                                                        selectId
                                                                    }
                                                                    className="sr-only"
                                                                >
                                                                    {t(
                                                                        'admin.deactivation.assignee_for',
                                                                        {
                                                                            task: task.title,
                                                                        },
                                                                    )}
                                                                </label>
                                                                <NativeSelect
                                                                    id={
                                                                        selectId
                                                                    }
                                                                    className="min-w-48"
                                                                    value={
                                                                        form
                                                                            .data
                                                                            .assignments[
                                                                            index
                                                                        ]
                                                                            ?.assignee_user_id ??
                                                                        ''
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        assign(
                                                                            task.id,
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    aria-invalid={
                                                                        error
                                                                            ? true
                                                                            : undefined
                                                                    }
                                                                >
                                                                    {options}
                                                                </NativeSelect>
                                                                <InputError
                                                                    message={
                                                                        error
                                                                    }
                                                                />
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            'admin.deactivation.membership_note',
                                        )}
                                    </p>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <h2 className="text-base font-medium">
                                    {t('admin.deactivation.more_title')}
                                </h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="grid gap-3 text-sm">
                                <li className="flex items-start gap-2">
                                    <Timer
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span>
                                        {timer
                                            ? t(
                                                  'admin.deactivation.timer_running',
                                                  {
                                                      task: timer.task_title,
                                                      elapsed: formatMinutes(
                                                          timer.elapsed_minutes,
                                                      ),
                                                  },
                                              )
                                            : t('admin.deactivation.no_timer')}
                                    </span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <Building
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span>
                                        {managedDepartments.length > 0
                                            ? t(
                                                  'admin.deactivation.departments',
                                                  {
                                                      departments:
                                                          managedDepartments.join(
                                                              ', ',
                                                          ),
                                                  },
                                              )
                                            : t(
                                                  'admin.deactivation.no_departments',
                                              )}
                                    </span>
                                </li>
                                <li className="flex items-start gap-2">
                                    <FolderKanban
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                    {ownedProjects.length > 0 ? (
                                        <div className="grid gap-1">
                                            <span>
                                                {t(
                                                    'admin.deactivation.owned_projects',
                                                )}
                                            </span>
                                            <ul className="grid gap-1">
                                                {ownedProjects.map(
                                                    (project) => (
                                                        <li
                                                            key={project.id}
                                                            className="flex items-center gap-1.5"
                                                        >
                                                            <TriangleAlert
                                                                aria-hidden="true"
                                                                className="size-3.5 text-warning"
                                                            />
                                                            <Link
                                                                href={urls.project(
                                                                    project.id,
                                                                    'ajustes',
                                                                )}
                                                                className={cn(
                                                                    'rounded-sm text-primary-text hover:underline',
                                                                    FOCUS_RING,
                                                                )}
                                                            >
                                                                {project.code} ·{' '}
                                                                {project.name}
                                                            </Link>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    ) : (
                                        <span>
                                            {t(
                                                'admin.deactivation.no_owned_projects',
                                            )}
                                        </span>
                                    )}
                                </li>
                            </ul>
                        </CardContent>
                    </Card>

                    <InputError message={generalError} />

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing || blocked !== null}
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <UserX aria-hidden="true" />
                            )}
                            {t('admin.deactivation.submit', {
                                name: user.name,
                            })}
                        </Button>
                        <Button variant="secondary" asChild>
                            <Link href={edit.url(user.id)}>
                                {t('common.cancel')}
                            </Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
