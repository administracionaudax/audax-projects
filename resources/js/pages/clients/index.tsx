import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, Plus, SearchX } from 'lucide-react';
import { useId } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { Pagination } from '@/components/admin/pagination';
import { useListFilters } from '@/components/admin/use-list-filters';
import { ClientDialog } from '@/components/clients/client-dialog';
import { ClientStatusBadge } from '@/components/clients/client-status-badge';
import { EmptyState } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as clientsIndex, show } from '@/routes/clients';
import type { ClientsIndexProps } from '@/types';

/**
 * Clientes (SPEC §6): búsqueda, filtro de activos, proyectos activos y horas del mes. Crean y
 * editan admins y responsables (D-022); los demás internos los consultan.
 */
export default function ClientsIndex({
    clients,
    filters: initialFilters,
}: ClientsIndexProps) {
    const id = useId();
    const can = usePage().props.auth?.can;
    const { filters, update, reset } = useListFilters(
        clientsIndex.url(),
        { q: initialFilters.q, estado: initialFilters.estado },
        { estado: 'activos' },
        ['clients', 'filters'],
    );
    const filtered = filters.q !== '' || filters.estado !== 'activos';

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
                    className="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] sm:items-end"
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
                    {filtered ? (
                        <Button type="button" variant="ghost" onClick={reset}>
                            {t('clients.clear_filters')}
                        </Button>
                    ) : null}
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
                        className={cn(
                            'overflow-x-auto rounded-md border',
                            FOCUS_RING,
                        )}
                        role="region"
                        aria-label={t('clients.table_label')}
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[40rem] text-sm">
                            <caption className="sr-only">
                                {t('clients.table_label')}
                            </caption>
                            <thead>
                                <tr className="border-b text-left">
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('clients.columns.client')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('clients.columns.contact')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t('clients.columns.active_projects')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t('clients.columns.month_hours')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
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
                                            <Link
                                                href={show.url(client.id)}
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
                                        </td>
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
                                        <td className="tabular px-3 py-2 text-right">
                                            {client.active_projects_count}
                                        </td>
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
