import { Head, Link, router } from '@inertiajs/react';
import {
    CirclePause,
    CirclePlay,
    Copy,
    Download,
    LayoutTemplate,
    Pencil,
    Plus,
    RotateCcw,
    SearchX,
    Trash2,
} from 'lucide-react';
import { useId, useState } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { useListFilters } from '@/components/admin/use-list-filters';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { ListPagination } from '@/components/projects-list/list-pagination';
import {
    ActiveBadge,
    templateStatsText,
} from '@/components/templates/template-badges';
import { ImportTemplateDialog } from '@/components/templates/import-template-dialog';
import { TemplatesAdminFrame } from '@/components/templates/templates-admin-frame';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import {
    create,
    destroy,
    edit,
    exportMethod,
    index as templatesIndex,
    restore,
    status,
} from '@/routes/templates';
import type { TemplateRow, TemplatesIndexProps } from '@/types/templates';

/**
 * Plantillas de proyecto (SPEC §14, D-058): listado con sus cifras, filtros (búsqueda, activas o
 * inactivas y papelera), crear, importar, editar, duplicar, exportar, activar o desactivar,
 * enviar a la papelera y recuperar. Solo administración.
 */
export default function TemplatesIndex({
    templates,
    filters: initialFilters,
    trashedCount,
}: TemplatesIndexProps) {
    const id = useId();
    const trash = initialFilters.papelera;
    const { filters, update, reset } = useListFilters(
        templatesIndex.url(),
        {
            q: initialFilters.q,
            estado: initialFilters.estado,
            papelera: trash ? '1' : null,
        },
        { estado: 'todas' },
        ['templates', 'filters', 'trashedCount'],
    );
    const filtered = filters.q !== '' || filters.estado !== 'todas';
    const empty = templates.meta.total === 0;

    return (
        <>
            <Head title={t('templates.index.title')} />

            <TemplatesAdminFrame
                section="templates"
                title={t('templates.index.heading')}
                description={t('templates.index.description')}
                actions={
                    <>
                        <ImportTemplateDialog />
                        <Button asChild>
                            <Link href={create()}>
                                <Plus aria-hidden="true" />
                                {t('templates.actions.new')}
                            </Link>
                        </Button>
                    </>
                }
            >
                <div className="grid gap-4">
                    <form
                        role="search"
                        aria-label={t('templates.index.filters_label')}
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] lg:items-end"
                        onSubmit={(event) => event.preventDefault()}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-q`}>
                                {t('templates.index.search')}
                            </Label>
                            <Input
                                id={`${id}-q`}
                                type="search"
                                value={filters.q ?? ''}
                                placeholder={t(
                                    'templates.index.search_placeholder',
                                )}
                                onChange={(event) =>
                                    update('q', event.target.value, true)
                                }
                            />
                        </div>
                        {trash ? null : (
                            <div className="grid gap-2">
                                <Label htmlFor={`${id}-status`}>
                                    {t('templates.index.status')}
                                </Label>
                                <NativeSelect
                                    id={`${id}-status`}
                                    value={filters.estado ?? 'todas'}
                                    onChange={(event) =>
                                        update('estado', event.target.value)
                                    }
                                >
                                    <option value="todas">
                                        {t('templates.index.status_all')}
                                    </option>
                                    <option value="activas">
                                        {t('templates.index.status_active')}
                                    </option>
                                    <option value="inactivas">
                                        {t('templates.index.status_inactive')}
                                    </option>
                                </NativeSelect>
                            </div>
                        )}
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                variant={trash ? 'default' : 'outline'}
                                aria-pressed={trash}
                                onClick={() =>
                                    update('papelera', trash ? null : '1')
                                }
                            >
                                <Trash2 aria-hidden="true" />
                                {t('templates.index.trash', {
                                    count: trashedCount,
                                })}
                            </Button>
                            {filtered ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={reset}
                                >
                                    {t('templates.index.clear_filters')}
                                </Button>
                            ) : null}
                        </div>
                    </form>

                    {empty && !filtered && !trash ? (
                        <EmptyState
                            icon={LayoutTemplate}
                            title={t('templates.index.empty_title')}
                            description={t('templates.index.empty_description')}
                        >
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus aria-hidden="true" />
                                    {t('templates.actions.new')}
                                </Link>
                            </Button>
                        </EmptyState>
                    ) : empty ? (
                        <EmptyState
                            icon={trash ? Trash2 : SearchX}
                            title={
                                trash
                                    ? t('templates.index.trash_empty')
                                    : t('templates.index.no_results')
                            }
                            description={
                                trash
                                    ? t(
                                          'templates.index.trash_empty_description',
                                      )
                                    : t(
                                          'templates.index.no_results_description',
                                      )
                            }
                        />
                    ) : (
                        <TemplatesTable rows={templates.data} trash={trash} />
                    )}

                    <ListPagination
                        page={templates}
                        label={t('templates.index.pagination')}
                    />
                </div>
            </TemplatesAdminFrame>
        </>
    );
}

TemplatesIndex.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('templates.index.title'), href: templatesIndex() },
    ],
};

function TemplatesTable({
    rows,
    trash,
}: {
    rows: TemplateRow[];
    trash: boolean;
}) {
    return (
        <div
            role="region"
            aria-label={t('templates.index.table_label')}
            tabIndex={0}
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
        >
            <table className="w-full min-w-[46rem] text-sm">
                <caption className="sr-only">
                    {t('templates.index.table_label')}
                </caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('templates.index.name')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('templates.index.contents')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('templates.index.status')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {trash
                                ? t('templates.index.deleted_at')
                                : t('templates.index.updated_at')}
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
                    {rows.map((row) => (
                        <tr
                            key={row.id}
                            className="border-b align-top last:border-b-0 even:bg-muted"
                            data-test="template-row"
                        >
                            <td className="px-3 py-2">
                                {trash ? (
                                    <span className="font-medium">
                                        {row.name}
                                    </span>
                                ) : (
                                    <Link
                                        href={edit(row.id)}
                                        className={cn(
                                            'rounded-sm font-medium text-primary-text hover:underline',
                                            FOCUS_RING,
                                        )}
                                    >
                                        {row.name}
                                    </Link>
                                )}
                                {row.description ? (
                                    <span className="mt-0.5 line-clamp-2 block text-muted-foreground">
                                        {row.description}
                                    </span>
                                ) : null}
                            </td>
                            <td className="px-3 py-2 text-muted-foreground">
                                {templateStatsText(row.stats)}
                            </td>
                            <td className="px-3 py-2">
                                <ActiveBadge
                                    active={row.is_active}
                                    trashed={trash}
                                />
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                {formatDateTime(
                                    trash ? row.deleted_at : row.updated_at,
                                )}
                            </td>
                            <td className="px-3 py-2">
                                <div className="flex justify-end gap-1">
                                    {trash ? (
                                        <RestoreButton row={row} />
                                    ) : (
                                        <RowActions row={row} />
                                    )}
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function RowActions({ row }: { row: TemplateRow }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <>
            <Button variant="ghost" size="icon" className="size-8" asChild>
                <Link
                    href={edit(row.id)}
                    aria-label={t('templates.actions.edit_label', {
                        name: row.name,
                    })}
                >
                    <Pencil aria-hidden="true" />
                </Link>
            </Button>
            <Button variant="ghost" size="icon" className="size-8" asChild>
                <Link
                    href={create({ query: { desde: row.id } })}
                    aria-label={t('templates.actions.duplicate_label', {
                        name: row.name,
                    })}
                >
                    <Copy aria-hidden="true" />
                </Link>
            </Button>
            <Button variant="ghost" size="icon" className="size-8" asChild>
                <a
                    href={exportMethod.url(row.id)}
                    download
                    aria-label={t('templates.actions.export_label', {
                        name: row.name,
                    })}
                >
                    <Download aria-hidden="true" />
                </a>
            </Button>
            <Button
                variant="ghost"
                size="icon"
                className="size-8"
                aria-label={
                    row.is_active
                        ? t('templates.actions.deactivate_label', {
                              name: row.name,
                          })
                        : t('templates.actions.activate_label', {
                              name: row.name,
                          })
                }
                onClick={() =>
                    router.put(
                        status.url(row.id),
                        { is_active: !row.is_active },
                        { preserveScroll: true, onError: toastVisitErrors },
                    )
                }
            >
                {row.is_active ? (
                    <CirclePause aria-hidden="true" />
                ) : (
                    <CirclePlay aria-hidden="true" />
                )}
            </Button>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                trigger={
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={t('templates.actions.delete_label', {
                            name: row.name,
                        })}
                    >
                        <Trash2 aria-hidden="true" />
                    </Button>
                }
                title={t('templates.actions.delete_title', { name: row.name })}
                description={t('templates.actions.delete_description')}
                confirmLabel={t('templates.actions.delete')}
                processing={processing}
                onConfirm={() =>
                    router.delete(destroy.url(row.id), {
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
        </>
    );
}

function RestoreButton({ row }: { row: TemplateRow }) {
    return (
        <Button
            variant="outline"
            size="sm"
            aria-label={t('templates.actions.restore_label', {
                name: row.name,
            })}
            onClick={() =>
                router.post(
                    restore.url(row.id),
                    {},
                    { preserveScroll: true, onError: toastVisitErrors },
                )
            }
        >
            <RotateCcw aria-hidden="true" />
            {t('templates.actions.restore')}
        </Button>
    );
}
