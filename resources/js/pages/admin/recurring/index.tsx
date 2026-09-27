import { Head, Link } from '@inertiajs/react';
import { Repeat, SearchX, Settings, TriangleAlert } from 'lucide-react';
import { useId } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { useListFilters } from '@/components/admin/use-list-filters';
import { EmptyState } from '@/components/empty-state';
import { ListPagination } from '@/components/projects-list/list-pagination';
import { ActiveBadge } from '@/components/templates/template-badges';
import { TemplatesAdminFrame } from '@/components/templates/templates-admin-frame';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import { index as recurringIndex } from '@/routes/recurring';
import type { RecurringIndexProps } from '@/types/templates';

/**
 * Vista global de las tareas recurrentes (SPEC §14, D-059): todas las reglas con su proyecto, la
 * frase, la próxima fecha, el responsable, el estado y los avisos, con filtros por estado y
 * proyecto. Se gestionan desde los Ajustes de cada proyecto (enlace en cada fila). Solo
 * administración.
 */
export default function RecurringIndex({
    rules,
    filters: initialFilters,
    projects,
}: RecurringIndexProps) {
    const id = useId();
    const { filters, update, reset } = useListFilters(
        recurringIndex.url(),
        {
            estado: initialFilters.estado,
            proyecto:
                initialFilters.proyecto === null
                    ? null
                    : String(initialFilters.proyecto),
        },
        { estado: 'activas' },
        ['rules', 'filters'],
    );
    const filtered = filters.estado !== 'activas' || filters.proyecto !== null;

    return (
        <>
            <Head title={t('recurring.index.title')} />

            <TemplatesAdminFrame
                section="recurring"
                title={t('recurring.index.heading')}
                description={t('recurring.index.description')}
            >
                <div className="grid gap-4">
                    <form
                        role="search"
                        aria-label={t('recurring.index.filters_label')}
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] lg:items-end"
                        onSubmit={(event) => event.preventDefault()}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-status`}>
                                {t('recurring.index.status')}
                            </Label>
                            <NativeSelect
                                id={`${id}-status`}
                                value={filters.estado ?? 'activas'}
                                onChange={(event) =>
                                    update('estado', event.target.value)
                                }
                            >
                                <option value="activas">
                                    {t('recurring.index.status_active')}
                                </option>
                                <option value="inactivas">
                                    {t('recurring.index.status_inactive')}
                                </option>
                                <option value="todas">
                                    {t('recurring.index.status_all')}
                                </option>
                            </NativeSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-project`}>
                                {t('recurring.index.project')}
                            </Label>
                            <NativeSelect
                                id={`${id}-project`}
                                value={filters.proyecto ?? ''}
                                onChange={(event) =>
                                    update(
                                        'proyecto',
                                        event.target.value || null,
                                    )
                                }
                            >
                                <option value="">
                                    {t('recurring.index.all_projects')}
                                </option>
                                {projects.map((project) => (
                                    <option
                                        key={project.id}
                                        value={String(project.id)}
                                    >
                                        {t('recurring.index.project_option', {
                                            code: project.code,
                                            name: project.name,
                                        })}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        {filtered ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={reset}
                            >
                                {t('recurring.index.clear_filters')}
                            </Button>
                        ) : null}
                    </form>

                    {rules.meta.total === 0 ? (
                        <EmptyState
                            icon={filtered ? SearchX : Repeat}
                            title={
                                filtered
                                    ? t('recurring.index.no_results')
                                    : t('recurring.index.empty')
                            }
                            description={t('recurring.index.empty_description')}
                        />
                    ) : (
                        <div
                            role="region"
                            aria-label={t('recurring.index.table_label')}
                            tabIndex={0}
                            className={cn(
                                'overflow-x-auto rounded-md border',
                                FOCUS_RING,
                            )}
                        >
                            <table className="w-full min-w-[56rem] text-sm">
                                <caption className="sr-only">
                                    {t('recurring.index.table_label')}
                                </caption>
                                <thead>
                                    <tr className="border-b text-left">
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('recurring.index.project')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('recurring.index.task')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('recurring.index.next')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('recurring.list.assignee')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('recurring.index.status')}
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
                                    {rules.data.map((rule) => (
                                        <tr
                                            key={rule.id}
                                            className="border-b align-top last:border-b-0 even:bg-muted"
                                            data-test="recurring-row"
                                        >
                                            <td className="px-3 py-2">
                                                {rule.project ? (
                                                    <Link
                                                        href={urls.project(
                                                            rule.project.id,
                                                        )}
                                                        className={cn(
                                                            'rounded-sm text-primary-text hover:underline',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        {rule.project.name}
                                                    </Link>
                                                ) : null}
                                                <span className="block text-xs text-muted-foreground">
                                                    {rule.project?.code}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">
                                                <span className="font-medium">
                                                    {rule.title}
                                                </span>
                                                <span className="block text-muted-foreground">
                                                    {rule.summary}
                                                </span>
                                                {rule.warnings.map(
                                                    (warning) => (
                                                        <span
                                                            key={warning}
                                                            className="mt-1 flex items-start gap-1.5 text-xs text-foreground"
                                                        >
                                                            <TriangleAlert
                                                                aria-hidden="true"
                                                                className="mt-0.5 size-3.5 shrink-0 text-warning"
                                                            />
                                                            {warning}
                                                        </span>
                                                    ),
                                                )}
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {rule.next_date
                                                    ? formatDate(rule.next_date)
                                                    : t(
                                                          'recurring.list.no_next',
                                                      )}
                                            </td>
                                            <td className="px-3 py-2">
                                                {rule.assignee?.name ??
                                                    t('recurring.list.nobody')}
                                            </td>
                                            <td className="px-3 py-2">
                                                <ActiveBadge
                                                    active={rule.is_active}
                                                    kind="rule"
                                                />
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    asChild
                                                >
                                                    <Link
                                                        href={urls.project(
                                                            rule.project_id,
                                                            'ajustes',
                                                        )}
                                                        aria-label={t(
                                                            'recurring.index.manage_label',
                                                            {
                                                                title: rule.title,
                                                                project:
                                                                    rule.project
                                                                        ?.name ??
                                                                    '',
                                                            },
                                                        )}
                                                    >
                                                        <Settings aria-hidden="true" />
                                                    </Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <ListPagination
                        page={rules}
                        label={t('recurring.index.pagination')}
                    />
                </div>
            </TemplatesAdminFrame>
        </>
    );
}

RecurringIndex.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('recurring.index.title'), href: recurringIndex() },
    ],
};
