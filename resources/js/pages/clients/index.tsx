import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Building2, Plus, SearchX } from 'lucide-react';
import { useId } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { Pagination } from '@/components/admin/pagination';
import { useListFilters } from '@/components/admin/use-list-filters';
import { ClientDialog } from '@/components/clients/client-dialog';
import { ClientStatusBadge } from '@/components/clients/client-status-badge';
import { EmptyState } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SatisfactionTrend } from '@/components/weeklies/insights/client-weekly-panels';
import { ProjectKindBadges } from '@/components/weeklies/insights/project-kind';
import {
    STICKY_TABLE_WRAPPER,
    STICKY_TH,
} from '@/components/weeklies/insights/project-status-view';
import { ClientIcon } from '@/components/weeklies/weekly-ui';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { PROJECT_TAGS, tagLabel } from '@/lib/project-status';
import { cn } from '@/lib/utils';
import { index as clientsIndex, show } from '@/routes/clients';
import type { ClientSort, ClientsIndexProps } from '@/types';

function SortHeader({
    column,
    label,
    current,
    direction,
    onSort,
    className,
}: {
    column: ClientSort;
    label: string;
    current: ClientSort;
    direction: 'asc' | 'desc';
    onSort: (column: ClientSort) => void;
    className?: string;
}) {
    const active = current === column;
    const Icon = direction === 'asc' ? ArrowUp : ArrowDown;

    return (
        <th
            scope="col"
            className={cn(STICKY_TH, 'px-3 py-2 font-medium', className)}
            aria-sort={
                active
                    ? direction === 'asc'
                        ? 'ascending'
                        : 'descending'
                    : undefined
            }
        >
            <button
                type="button"
                onClick={() => onSort(column)}
                className={cn('inline-flex items-center gap-1', FOCUS_RING)}
                aria-label={t('clients.sort_by', { column: label })}
                data-test={`client-sort-${column}`}
            >
                {label}
                {active ? (
                    <Icon aria-hidden="true" className="size-3.5" />
                ) : (
                    <span aria-hidden="true" className="size-3.5" />
                )}
            </button>
        </th>
    );
}

/**
 * Clientes (SPEC §6): búsqueda, filtro de activos, proyectos activos y horas del mes. Crean y
 * editan admins y responsables (D-022); los demás internos los consultan. Con la Weekly (Fase 10,
 * F-096 y F-123 a F-124), la cartera de WeeklySync: icono, último reporte, satisfacción con su
 * tendencia, insignias por tipo de proyecto, orden por nombre, último reporte o satisfacción y
 * filtros por tipo de proyecto, persona y «Mis proyectos».
 */
export default function ClientsIndex({
    clients,
    filters: initialFilters,
    people = [],
    weekly = false,
}: ClientsIndexProps) {
    const id = useId();
    const can = usePage().props.auth?.can;
    const { filters, update, updateMany, reset } = useListFilters(
        clientsIndex.url(),
        {
            q: initialFilters.q,
            estado: initialFilters.estado,
            orden: initialFilters.orden ?? 'nombre',
            dir: initialFilters.dir ?? 'asc',
            tipo: initialFilters.tipo ?? '',
            persona: initialFilters.persona ?? '',
            mios: initialFilters.mios ?? '',
        },
        { estado: 'activos', orden: 'nombre', dir: 'asc' },
        ['clients', 'filters'],
    );
    const filtered =
        filters.q !== '' ||
        filters.estado !== 'activos' ||
        (filters.tipo ?? '') !== '' ||
        (filters.persona ?? '') !== '' ||
        filters.mios === '1';
    const sort = (filters.orden ?? 'nombre') as ClientSort;
    const direction = (filters.dir ?? 'asc') as 'asc' | 'desc';

    const onSort = (column: ClientSort) =>
        updateMany({
            orden: column,
            dir:
                sort === column
                    ? direction === 'asc'
                        ? 'desc'
                        : 'asc'
                    : column === 'nombre'
                      ? 'asc'
                      : 'desc',
        });

    return (
        <>
            <Head title={t('clients.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <h1 className="text-2xl font-normal tracking-tight">
                            <KeywordText text={t('clients.heading')} />
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('clients.description')}
                        </p>
                    </div>
                    {can?.createClients ? (
                        <ClientDialog
                            showFinancials={can.viewFinancials}
                            trigger={
                                <Button data-test="new-client">
                                    <Plus aria-hidden="true" />
                                    {t('clients.new')}
                                </Button>
                            }
                        />
                    ) : null}
                </header>

                <form
                    role="search"
                    aria-label={t('clients.filters_label')}
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto] lg:items-end"
                    onSubmit={(event) => event.preventDefault()}
                >
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-q`}>{t('clients.search')}</Label>
                        <Input
                            id={`${id}-q`}
                            type="search"
                            value={filters.q ?? ''}
                            placeholder={t('clients.search_placeholder')}
                            onChange={(event) =>
                                update('q', event.target.value, true)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-status`}>
                            {t('clients.status_filter')}
                        </Label>
                        <NativeSelect
                            id={`${id}-status`}
                            value={filters.estado ?? 'activos'}
                            onChange={(event) =>
                                update('estado', event.target.value)
                            }
                        >
                            <option value="activos">
                                {t('clients.filter.active')}
                            </option>
                            <option value="inactivos">
                                {t('clients.filter.inactive')}
                            </option>
                            <option value="todos">
                                {t('clients.filter.all')}
                            </option>
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-kind`}>
                            {t('clients.filter_kind')}
                        </Label>
                        <NativeSelect
                            id={`${id}-kind`}
                            value={filters.tipo ?? ''}
                            onChange={(event) =>
                                update('tipo', event.target.value)
                            }
                            data-test="client-filter-kind"
                        >
                            <option value="">
                                {t('clients.filter_kind_all')}
                            </option>
                            {PROJECT_TAGS.map((tag) => (
                                <option key={tag} value={tag}>
                                    {tagLabel(tag)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-person`}>
                            {t('clients.filter_person')}
                        </Label>
                        <NativeSelect
                            id={`${id}-person`}
                            value={filters.persona ?? ''}
                            onChange={(event) =>
                                update('persona', event.target.value)
                            }
                            data-test="client-filter-person"
                        >
                            <option value="">
                                {t('clients.filter_person_all')}
                            </option>
                            {people.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="flex flex-wrap items-center gap-3 lg:h-9">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id={`${id}-mine`}
                                checked={filters.mios === '1'}
                                onCheckedChange={(checked) =>
                                    update('mios', checked === true ? '1' : '')
                                }
                                data-test="client-filter-mine"
                            />
                            <Label htmlFor={`${id}-mine`}>
                                {t('clients.filter_mine')}
                            </Label>
                        </div>
                        {filtered ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={reset}
                            >
                                {t('clients.clear_filters')}
                            </Button>
                        ) : null}
                    </div>
                </form>

                {clients.data.length === 0 ? (
                    <EmptyState
                        icon={filtered ? SearchX : Building2}
                        title={
                            filtered
                                ? t('clients.empty_filtered')
                                : t('clients.empty')
                        }
                        description={
                            filtered
                                ? t('clients.empty_filtered_description')
                                : t('clients.empty_description')
                        }
                    />
                ) : (
                    <div
                        className={cn(STICKY_TABLE_WRAPPER, FOCUS_RING)}
                        role="region"
                        aria-label={t('clients.table_label')}
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[48rem] text-sm">
                            <caption className="sr-only">
                                {t('clients.table_label')}
                            </caption>
                            <thead>
                                <tr className="border-b text-left">
                                    <SortHeader
                                        column="nombre"
                                        label={t('clients.columns.client')}
                                        current={sort}
                                        direction={direction}
                                        onSort={onSort}
                                    />
                                    <th
                                        scope="col"
                                        className={cn(
                                            STICKY_TH,
                                            'px-3 py-2 font-medium',
                                        )}
                                    >
                                        {t('clients.columns.projects')}
                                    </th>
                                    {weekly ? (
                                        <>
                                            <SortHeader
                                                column="ultimo_reporte"
                                                label={t(
                                                    'clients.columns.last_report',
                                                )}
                                                current={sort}
                                                direction={direction}
                                                onSort={onSort}
                                            />
                                            <SortHeader
                                                column="satisfaccion"
                                                label={t(
                                                    'clients.columns.satisfaction',
                                                )}
                                                current={sort}
                                                direction={direction}
                                                onSort={onSort}
                                            />
                                        </>
                                    ) : (
                                        <th
                                            scope="col"
                                            className={cn(
                                                STICKY_TH,
                                                'px-3 py-2 font-medium',
                                            )}
                                        >
                                            {t('clients.columns.contact')}
                                        </th>
                                    )}
                                    <th
                                        scope="col"
                                        className={cn(
                                            STICKY_TH,
                                            'px-3 py-2 text-right font-medium',
                                        )}
                                    >
                                        {t('clients.columns.month_hours')}
                                    </th>
                                    <th
                                        scope="col"
                                        className={cn(
                                            STICKY_TH,
                                            'px-3 py-2 font-medium',
                                        )}
                                    >
                                        {t('clients.status_filter')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {clients.data.map((client) => (
                                    <tr
                                        key={client.id}
                                        className="border-b last:border-b-0 even:bg-muted"
                                        data-test="client-row"
                                    >
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-2">
                                                <ClientIcon
                                                    icon={client.icon}
                                                />
                                                <div className="min-w-0">
                                                    <Link
                                                        href={show.url(
                                                            client.id,
                                                        )}
                                                        className={cn(
                                                            'rounded-sm font-medium hover:underline',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        {client.name}
                                                    </Link>
                                                    {client.tax_id ? (
                                                        <span className="block text-xs text-muted-foreground">
                                                            {client.tax_id}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <ProjectKindBadges
                                                badges={
                                                    client.kind_badges ?? []
                                                }
                                                compact
                                            />
                                            <span className="sr-only">
                                                {t(
                                                    'clients.columns.active_projects',
                                                )}
                                                : {client.active_projects_count}
                                            </span>
                                        </td>
                                        {weekly ? (
                                            <>
                                                <td
                                                    className="tabular px-3 py-2 whitespace-nowrap"
                                                    data-test="client-last-report"
                                                >
                                                    {client.last_report_at ? (
                                                        formatDate(
                                                            client.last_report_at,
                                                        )
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            {t(
                                                                'clients.no_reports',
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 whitespace-nowrap">
                                                    <SatisfactionTrend
                                                        score={
                                                            client.satisfaction_score ??
                                                            50
                                                        }
                                                        trend={
                                                            client.satisfaction_trend
                                                        }
                                                    />
                                                </td>
                                            </>
                                        ) : (
                                            <td className="px-3 py-2">
                                                {client.contact_name ?? (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                                {client.contact_email ? (
                                                    <span className="block text-xs break-all text-muted-foreground">
                                                        {client.contact_email}
                                                    </span>
                                                ) : null}
                                            </td>
                                        )}
                                        <td className="tabular px-3 py-2 text-right">
                                            {formatMinutes(
                                                client.month_minutes,
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <ClientStatusBadge
                                                isActive={client.is_active}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination
                    page={clients}
                    label={t('clients.pagination_label')}
                />
            </div>
        </>
    );
}

ClientsIndex.layout = {
    breadcrumbs: [{ title: t('nav.clients'), href: clientsIndex() }],
};
