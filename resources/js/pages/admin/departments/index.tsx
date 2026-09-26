import { Head, router } from '@inertiajs/react';
import { Building, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AdminPage } from '@/components/admin/admin-page';
import { ColorDot } from '@/components/admin/color-picker';
import { DepartmentDialog } from '@/components/admin/department-dialog';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { destroy, index as departmentsIndex } from '@/routes/admin/departments';
import type { AdminDepartment, AdminDepartmentsProps } from '@/types';

function DeleteDepartment({ department }: { department: AdminDepartment }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    if (!department.can_delete) {
        return (
            <Button
                variant="ghost"
                size="icon"
                className="size-8 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                aria-disabled
                aria-label={t('admin.departments.delete_label', {
                    name: department.name,
                })}
                aria-describedby={`department-${department.id}-locked`}
            >
                <Trash2 aria-hidden="true" />
            </Button>
        );
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
                    aria-label={t('admin.departments.delete_label', {
                        name: department.name,
                    })}
                >
                    <Trash2 aria-hidden="true" />
                </Button>
            }
            title={t('admin.departments.delete_title', {
                name: department.name,
            })}
            description={t('admin.departments.delete_description')}
            confirmLabel={t('common.delete')}
            processing={processing}
            onConfirm={() =>
                router.delete(destroy.url(department.id), {
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

/** Departamentos (SPEC §4.1, D-024): nombre, color, varios responsables y cuántas personas tiene. */
export default function AdminDepartments({
    departments,
    managerOptions,
    palette,
}: AdminDepartmentsProps) {
    return (
        <>
            <Head title={t('admin.departments.title')} />

            <AdminPage
                section="departments"
                title={t('admin.departments.heading')}
                description={t('admin.departments.description')}
                actions={
                    <DepartmentDialog
                        palette={palette}
                        managerOptions={managerOptions}
                        trigger={
                            <Button>
                                <Plus aria-hidden="true" />
                                {t('admin.departments.new')}
                            </Button>
                        }
                    />
                }
            >
                {departments.length === 0 ? (
                    <EmptyState
                        icon={Building}
                        title={t('admin.departments.empty')}
                        description={t('admin.departments.empty_description')}
                    />
                ) : (
                    <div
                        className={cn(
                            'overflow-x-auto rounded-md border',
                            FOCUS_RING,
                        )}
                        role="region"
                        aria-label={t('admin.departments.table_label')}
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[40rem] text-sm">
                            <caption className="sr-only">
                                {t('admin.departments.table_label')}
                            </caption>
                            <thead>
                                <tr className="border-b text-left">
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
                                        {t('admin.departments.managers')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t('admin.departments.people')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t('admin.departments.open_banks')}
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
                                {departments.map((department) => (
                                    <tr
                                        key={department.id}
                                        className="border-b align-top last:border-b-0 even:bg-muted"
                                        data-test="department-row"
                                    >
                                        <td className="px-3 py-2">
                                            <span className="inline-flex items-center gap-2 font-medium">
                                                <ColorDot
                                                    color={department.color}
                                                />
                                                {department.name}
                                            </span>
                                            {!department.can_delete ? (
                                                <span
                                                    id={`department-${department.id}-locked`}
                                                    className="mt-1 block text-xs text-muted-foreground"
                                                >
                                                    {t(
                                                        'admin.departments.cannot_delete',
                                                    )}
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="px-3 py-2">
                                            {department.managers.length > 0 ? (
                                                department.managers
                                                    .map(
                                                        (manager) =>
                                                            manager.name,
                                                    )
                                                    .join(', ')
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        'admin.departments.no_managers',
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="tabular px-3 py-2 text-right">
                                            {department.users_count}
                                            {department.inactive_users_count >
                                            0 ? (
                                                <span className="block text-xs text-muted-foreground">
                                                    {t(
                                                        'admin.departments.inactive_people',
                                                        {
                                                            count: department.inactive_users_count,
                                                        },
                                                    )}
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="tabular px-3 py-2 text-right">
                                            {department.open_hour_banks_count}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex justify-end gap-1">
                                                <DepartmentDialog
                                                    department={department}
                                                    palette={palette}
                                                    managerOptions={
                                                        managerOptions
                                                    }
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            aria-label={t(
                                                                'admin.departments.edit_label',
                                                                {
                                                                    name: department.name,
                                                                },
                                                            )}
                                                        >
                                                            <Pencil aria-hidden="true" />
                                                        </Button>
                                                    }
                                                />
                                                <DeleteDepartment
                                                    department={department}
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

AdminDepartments.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('admin.departments.title'), href: departmentsIndex() },
    ],
};
