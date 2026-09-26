import { Head, router } from '@inertiajs/react';
import { CircleSlash, ListTodo, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AdminPage } from '@/components/admin/admin-page';
import { ReorderButtons } from '@/components/admin/reorder-buttons';
import { TaskTypeDialog } from '@/components/admin/task-type-dialog';
import { TaskTypeIcon } from '@/components/admin/task-type-icon';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import {
    destroy,
    index as taskTypesIndex,
    move,
} from '@/routes/admin/task-types';
import type { AdminTaskType, AdminTaskTypesProps } from '@/types';

function DeleteTaskType({ taskType }: { taskType: AdminTaskType }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    // Un tipo en uso no se borra: se desactiva (y si ya lo está, no hay nada que hacer).
    if (taskType.in_use && !taskType.is_active) {
        return null;
    }

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={setOpen}
            trigger={
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={
                        taskType.in_use
                            ? t('admin.task_types.deactivate_label', {
                                  name: taskType.name,
                              })
                            : t('admin.task_types.delete_label', {
                                  name: taskType.name,
                              })
                    }
                >
                    {taskType.in_use ? (
                        <CircleSlash aria-hidden="true" />
                    ) : (
                        <Trash2 aria-hidden="true" />
                    )}
                </Button>
            }
            title={
                taskType.in_use
                    ? t('admin.task_types.deactivate_title', {
                          name: taskType.name,
                      })
                    : t('admin.task_types.delete_title', {
                          name: taskType.name,
                      })
            }
            description={
                taskType.in_use
                    ? t('admin.task_types.deactivate_description', {
                          count: taskType.tasks_count,
                      })
                    : t('admin.task_types.delete_description')
            }
            confirmLabel={
                taskType.in_use
                    ? t('admin.task_types.deactivate')
                    : t('common.delete')
            }
            processing={processing}
            onConfirm={() =>
                router.delete(destroy.url(taskType.id), {
                    preserveScroll: true,
                    onError: toastVisitErrors,
                    onStart: () => setProcessing(true),
                    onFinish: () => {
                        setProcessing(false);
                        setOpen(false);
                    },
                })
            }
        />
    );
}

/** Tipos de tarea (SPEC §4.3): catálogo ordenado con color, icono, departamento y facturación. */
export default function AdminTaskTypes({
    taskTypes,
    departments,
    palette,
    icons,
}: AdminTaskTypesProps) {
    const departmentName = (id: number | null) =>
        departments.find((department) => department.id === id)?.name ?? null;

    return (
        <>
            <Head title={t('admin.task_types.title')} />

            <AdminPage
                section="task-types"
                title={t('admin.task_types.heading')}
                description={t('admin.task_types.description')}
                actions={
                    <TaskTypeDialog
                        palette={palette}
                        icons={icons}
                        departments={departments}
                        trigger={
                            <Button>
                                <Plus aria-hidden="true" />
                                {t('admin.task_types.new')}
                            </Button>
                        }
                    />
                }
            >
                {taskTypes.length === 0 ? (
                    <EmptyState
                        icon={ListTodo}
                        title={t('admin.task_types.empty')}
                        description={t('admin.task_types.empty_description')}
                    />
                ) : (
                    <div
                        className={cn(
                            'overflow-x-auto rounded-md border',
                            FOCUS_RING,
                        )}
                        role="region"
                        aria-label={t('admin.task_types.table_label')}
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[42rem] text-sm">
                            <caption className="sr-only">
                                {t('admin.task_types.table_label')}
                            </caption>
                            <thead>
                                <tr className="border-b text-left">
                                    <th
                                        scope="col"
                                        className="w-20 px-3 py-2 font-medium"
                                    >
                                        {t('admin.reorder.column')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('admin.form.name')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('admin.task_types.department')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('admin.task_types.billable_column')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('admin.filters.status')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        <span className="sr-only">
                                            {t('common.actions')}
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {taskTypes.map((taskType, index) => (
                                    <tr
                                        key={taskType.id}
                                        className="border-b last:border-b-0 even:bg-muted"
                                        data-test="task-type-row"
                                    >
                                        <td className="px-3 py-1.5">
                                            <ReorderButtons
                                                name={taskType.name}
                                                url={move.url(taskType.id)}
                                                isFirst={index === 0}
                                                isLast={
                                                    index ===
                                                    taskTypes.length - 1
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="inline-flex items-center gap-2 font-medium">
                                                <TaskTypeIcon
                                                    name={taskType.icon}
                                                    color={taskType.color}
                                                />
                                                {taskType.name}
                                            </span>
                                            {taskType.tasks_count > 0 ? (
                                                <span className="block text-xs text-muted-foreground">
                                                    {t(
                                                        'admin.task_types.tasks_count',
                                                        {
                                                            count: taskType.tasks_count,
                                                        },
                                                    )}
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="px-3 py-2">
                                            {departmentName(
                                                taskType.department_id,
                                            ) ?? (
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        'admin.task_types.any_department',
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {taskType.is_billable_default
                                                ? t('common.yes')
                                                : t('common.no')}
                                        </td>
                                        <td className="px-3 py-2">
                                            {taskType.is_active ? (
                                                <StatusBadge
                                                    tone="success"
                                                    icon={ListTodo}
                                                >
                                                    {t(
                                                        'admin.task_types.active_badge',
                                                    )}
                                                </StatusBadge>
                                            ) : (
                                                <StatusBadge
                                                    tone="neutral"
                                                    icon={CircleSlash}
                                                >
                                                    {t(
                                                        'admin.task_types.inactive_badge',
                                                    )}
                                                </StatusBadge>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex justify-end gap-1">
                                                <TaskTypeDialog
                                                    taskType={taskType}
                                                    palette={palette}
                                                    icons={icons}
                                                    departments={departments}
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            aria-label={t(
                                                                'admin.task_types.edit_label',
                                                                {
                                                                    name: taskType.name,
                                                                },
                                                            )}
                                                        >
                                                            <Pencil aria-hidden="true" />
                                                        </Button>
                                                    }
                                                />
                                                <DeleteTaskType
                                                    taskType={taskType}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </AdminPage>
        </>
    );
}

AdminTaskTypes.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('admin.task_types.title'), href: taskTypesIndex() },
    ],
};
