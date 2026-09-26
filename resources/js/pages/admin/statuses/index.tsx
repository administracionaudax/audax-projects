import { Head } from '@inertiajs/react';
import { CircleDot, Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { AdminPage } from '@/components/admin/admin-page';
import { ReorderButtons } from '@/components/admin/reorder-buttons';
import {
    categoryLabel,
    DeleteStatusDialog,
    StatusDialog,
} from '@/components/admin/status-dialogs';
import { TaskStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { index as statusesIndex, move } from '@/routes/admin/statuses';
import type { AdminStatusesProps } from '@/types';

/**
 * Estados de tarea (SPEC §4.3): conjunto global y ordenado. La categoría (por hacer, en curso o
 * hecha) es lo que usan los cálculos; siempre hay un estado por defecto y al menos uno por hacer
 * y otro hecho.
 */
export default function AdminStatuses({
    statuses,
    palette,
}: AdminStatusesProps) {
    return (
        <>
            <Head title={t('admin.statuses.title')} />

            <AdminPage
                section="statuses"
                title={t('admin.statuses.heading')}
                description={t('admin.statuses.description')}
                actions={
                    <StatusDialog
                        palette={palette}
                        trigger={
                            <Button>
                                <Plus aria-hidden="true" />
                                {t('admin.statuses.new')}
                            </Button>
                        }
                    />
                }
            >
                {statuses.length === 0 ? (
                    <EmptyState
                        icon={CircleDot}
                        title={t('admin.statuses.empty')}
                    />
                ) : (
                    <div
                        className={cn(
                            'overflow-x-auto rounded-md border',
                            FOCUS_RING,
                        )}
                        role="region"
                        aria-label={t('admin.statuses.table_label')}
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[38rem] text-sm">
                            <caption className="sr-only">
                                {t('admin.statuses.table_label')}
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
                                        {t('admin.statuses.category_label')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t('admin.statuses.tasks')}
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
                                {statuses.map((status, index) => (
                                    <tr
                                        key={status.id}
                                        className="border-b last:border-b-0 even:bg-muted"
                                        data-test="status-row"
                                    >
                                        <td className="px-3 py-1.5">
                                            <ReorderButtons
                                                name={status.name}
                                                url={move.url(status.id)}
                                                isFirst={index === 0}
                                                isLast={
                                                    index ===
                                                    statuses.length - 1
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="inline-flex flex-wrap items-center gap-2">
                                                <TaskStatusBadge
                                                    name={status.name}
                                                    color={status.color}
                                                    done={
                                                        status.category ===
                                                        'done'
                                                    }
                                                />
                                                {status.is_default ? (
                                                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                        <Star
                                                            aria-hidden="true"
                                                            className="size-3.5"
                                                        />
                                                        {t(
                                                            'admin.statuses.default_badge',
                                                        )}
                                                    </span>
                                                ) : null}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            {categoryLabel(status.category)}
                                        </td>
                                        <td className="tabular px-3 py-2 text-right">
                                            {status.tasks_count}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex justify-end gap-1">
                                                <StatusDialog
                                                    status={status}
                                                    palette={palette}
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            aria-label={t(
                                                                'admin.statuses.edit_label',
                                                                {
                                                                    name: status.name,
                                                                },
                                                            )}
                                                        >
                                                            <Pencil aria-hidden="true" />
                                                        </Button>
                                                    }
                                                />
                                                {status.is_default ? null : (
                                                    <DeleteStatusDialog
                                                        status={status}
                                                        statuses={statuses}
                                                        trigger={
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-8"
                                                                aria-label={t(
                                                                    'admin.statuses.delete_label',
                                                                    {
                                                                        name: status.name,
                                                                    },
                                                                )}
                                                            >
                                                                <Trash2 aria-hidden="true" />
                                                            </Button>
                                                        }
                                                    />
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <p className="mt-4 text-sm text-muted-foreground">
                    {t('admin.statuses.rules')}
                </p>
            </AdminPage>
        </>
    );
}

AdminStatuses.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('admin.statuses.title'), href: statusesIndex() },
    ],
};
