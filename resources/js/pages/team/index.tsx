import { Head, Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Settings2, Users } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { EmptyState } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    STICKY_TABLE_WRAPPER,
    STICKY_TH,
} from '@/components/weeklies/insights/project-status-view';
import {
    AbsenceTodayBadge,
    CopyEmailButton,
} from '@/components/weeklies/insights/team-ui';
import { PersonStatusBadge } from '@/components/weeklies/weekly-ui';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as usersIndex } from '@/routes/admin/users';
import { index as teamIndex, show as showPerson } from '@/routes/team';
import { filterTeam, STATUS_ORDER } from '@/lib/team-filter';
import type { TeamSortKey as SortKey } from '@/lib/team-filter';
import type { TeamIndexPageProps } from '@/types/weekly-insights';

const EMPTY_FILTERS = {
    q: '',
    department: '',
    role: '',
    status: '',
    client: '',
};

/**
 * Equipo (F-134 a F-137, TeamView de WeeklySync con las personas de Audax): la plantilla con su
 * departamento, puesto y el estado de su weekly en la semana activa, con buscador, filtros por
 * departamento, rol, estado del reporte y cliente, columnas ordenables y copiar el email. El alta,
 * la edición y la baja siguen en Administración.
 */
export default function TeamIndex({
    members,
    cycle,
    departments,
    clients,
    roles,
    can,
}: TeamIndexPageProps) {
    const id = useId();
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [sort, setSort] = useState<{ key: SortKey; dir: 'asc' | 'desc' }>({
        key: 'name',
        dir: 'asc',
    });
    const rows = useMemo(
        () => filterTeam(members, filters, sort),
        [members, filters, sort],
    );
    const filtered = Object.values(filters).some((value) => value !== '');
    const set = (key: keyof typeof EMPTY_FILTERS, value: string) =>
        setFilters((current) => ({ ...current, [key]: value }));
    const onSort = (key: SortKey) =>
        setSort((current) => ({
            key,
            dir: current.key === key && current.dir === 'asc' ? 'desc' : 'asc',
        }));

    const header = (key: SortKey, label: string) => {
        const active = sort.key === key;
        const Icon = sort.dir === 'asc' ? ArrowUp : ArrowDown;

        return (
            <th
                scope="col"
                className={cn(STICKY_TH, 'px-3 py-2 font-medium')}
                aria-sort={
                    active
                        ? sort.dir === 'asc'
                            ? 'ascending'
                            : 'descending'
                        : undefined
                }
            >
                <button
                    type="button"
                    onClick={() => onSort(key)}
                    className={cn('inline-flex items-center gap-1', FOCUS_RING)}
                    aria-label={t('weeklies.team.sort_by', { column: label })}
                    data-test={`team-sort-${key}`}
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
    };

    return (
        <>
            <Head title={t('weeklies.team.title')} />
            <div className="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <h1 className="text-2xl font-normal tracking-tight">
                            <KeywordText text={t('weeklies.team.heading')} />
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('weeklies.team.description')}
                        </p>
                        <p className="text-sm" data-test="team-cycle">
                            {cycle
                                ? t('weeklies.team.cycle', {
                                      week: cycle.label,
                                  })
                                : t('weeklies.team.no_cycle')}
                        </p>
                    </div>
                    {can.manageUsers ? (
                        <Button variant="outline" asChild>
                            <Link href={usersIndex.url()}>
                                <Settings2 aria-hidden="true" />
                                {t('weeklies.team.manage_users')}
                            </Link>
                        </Button>
                    ) : null}
                </header>

                <form
                    role="search"
                    aria-label={t('weeklies.team.filters_label')}
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))_auto] lg:items-end"
                    onSubmit={(event) => event.preventDefault()}
                >
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-q`}>
                            {t('weeklies.team.search')}
                        </Label>
                        <Input
                            id={`${id}-q`}
                            type="search"
                            value={filters.q}
                            placeholder={t('weeklies.team.search_placeholder')}
                            onChange={(event) => set('q', event.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-department`}>
                            {t('weeklies.team.department')}
                        </Label>
                        <NativeSelect
                            id={`${id}-department`}
                            value={filters.department}
                            onChange={(event) =>
                                set('department', event.target.value)
                            }
                        >
                            <option value="">
                                {t('weeklies.team.all_departments')}
                            </option>
                            {departments.map((department) => (
                                <option
                                    key={department.id}
                                    value={department.id}
                                >
                                    {department.name}
                                </option>
                            ))}
                            <option value="none">
                                {t('weeklies.team.no_department')}
                            </option>
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-role`}>
                            {t('weeklies.team.role')}
                        </Label>
                        <NativeSelect
                            id={`${id}-role`}
                            value={filters.role}
                            onChange={(event) =>
                                set('role', event.target.value)
                            }
                        >
                            <option value="">
                                {t('weeklies.team.all_roles')}
                            </option>
                            {roles.map((role) => (
                                <option key={role} value={role}>
                                    {t(`weeklies.team.role.${role}`)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-status`}>
                            {t('weeklies.team.status')}
                        </Label>
                        <NativeSelect
                            id={`${id}-status`}
                            value={filters.status}
                            onChange={(event) =>
                                set('status', event.target.value)
                            }
                            disabled={cycle === null}
                            data-test="team-filter-status"
                        >
                            <option value="">
                                {t('weeklies.team.all_statuses')}
                            </option>
                            {STATUS_ORDER.map((status) => (
                                <option key={status} value={status}>
                                    {t(`weeklies.person_status.${status}`)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-client`}>
                            {t('weeklies.team.client')}
                        </Label>
                        <NativeSelect
                            id={`${id}-client`}
                            value={filters.client}
                            onChange={(event) =>
                                set('client', event.target.value)
                            }
                        >
                            <option value="">
                                {t('weeklies.team.all_clients')}
                            </option>
                            {clients.map((client) => (
                                <option key={client.id} value={client.id}>
                                    {client.icon ? `${client.icon} ` : ''}
                                    {client.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    {filtered ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setFilters(EMPTY_FILTERS)}
                        >
                            {t('weeklies.team.clear')}
                        </Button>
                    ) : null}
                </form>

                <p className="text-sm text-muted-foreground" aria-live="polite">
                    {t('weeklies.team.count', { count: rows.length })}
                </p>

                {rows.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={t('weeklies.team.empty_filtered')}
                    />
                ) : (
                    <div
                        className={cn(STICKY_TABLE_WRAPPER, FOCUS_RING)}
                        role="region"
                        aria-label={t('weeklies.team.table_label')}
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[52rem] text-sm">
                            <caption className="sr-only">
                                {t('weeklies.team.table_label')}
                            </caption>
                            <thead>
                                <tr className="border-b text-left">
                                    {header(
                                        'name',
                                        t('weeklies.team.column.name'),
                                    )}
                                    {header(
                                        'email',
                                        t('weeklies.team.column.email'),
                                    )}
                                    {header(
                                        'department',
                                        t('weeklies.team.column.department'),
                                    )}
                                    {header(
                                        'job_title',
                                        t('weeklies.team.column.job_title'),
                                    )}
                                    {header(
                                        'status',
                                        t('weeklies.team.column.status'),
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={row.user.id}
                                        className="border-b last:border-b-0 even:bg-muted"
                                        data-test="team-row"
                                    >
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-2">
                                                <UserAvatar
                                                    user={row.user}
                                                    className="size-8"
                                                />
                                                <div className="grid min-w-0 gap-1">
                                                    <Link
                                                        href={showPerson.url(
                                                            row.user.id,
                                                        )}
                                                        className={cn(
                                                            'font-medium hover:underline',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        {row.user.name}
                                                    </Link>
                                                    <AbsenceTodayBadge
                                                        absence={row.absence}
                                                    />
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="inline-flex items-center gap-1 break-all">
                                                {row.email}
                                                <CopyEmailButton
                                                    email={row.email}
                                                    name={row.user.name}
                                                />
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.department?.name ?? (
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        'weeklies.team.no_department',
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.job_title ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.report_status ? (
                                                <PersonStatusBadge
                                                    status={row.report_status}
                                                />
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

TeamIndex.layout = {
    breadcrumbs: [{ title: t('weeklies.team.title'), href: teamIndex() }],
};
