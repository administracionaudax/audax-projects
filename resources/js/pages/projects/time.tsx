import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Clock, Info, Pencil, Plus, TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import { TimeEntryStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { ProjectShell } from '@/components/projects/project-shell';
import { ExportMenu } from '@/components/reports/export-menu';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { time as projectTime } from '@/routes/projects';
import { exportMethod as exportProjectTime } from '@/routes/projects/time';
import type {
    ProjectTimeEntry,
    ProjectTimeFilters,
    ProjectTimePageProps,
    TimeEntryStatus,
} from '@/types';

const ALL = 'all';

/** Filtros con valor, para la URL de la pestaña y la de su exportación. */
function activeFilters(
    filters: ProjectTimeFilters,
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            (entry): entry is [string, string | number] =>
                entry[1] !== null && entry[1] !== '',
        ),
    );
}
const STATUSES: TimeEntryStatus[] = [
    'draft',
    'submitted',
    'approved',
    'locked',
];

/**
 * Pestaña Horas del proyecto (SPEC §6, D-021): entradas con filtros, totales (dentro de bolsa,
 * exceso y facturable) y paginación. Un empleado ve solo las suyas. Se exportan a XLSX o CSV con
 * los mismos filtros (y el mismo alcance) en /proyectos/{id}/horas/exportar (Fase 2, R3).
 */
export default function ProjectTime({
    project,
    canManage,
    scope,
    entries,
    totals,
    filters,
    options,
}: ProjectTimePageProps) {
    const id = useId();

    // Dos niveles: la pestaña ya se ve en la cabecera del proyecto (y en móvil no se amontonan).
    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.projects'), href: urls.projects() },
            { title: project.name, href: projectTime(project.id) },
        ],
    });

    const [editing, setEditing] = useState<ProjectTimeEntry | null>(null);
    const [creating, setCreating] = useState(false);

    const apply = (changes: Partial<ProjectTimeFilters>) => {
        router.get(
            projectTime.url(project.id, {
                query: activeFilters({ ...filters, ...changes }),
            }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    };

    const field = (name: string) => `${id}-${name}`;
    const hasFilters = Object.values(filters).some((value) => value !== null);
    const bankName = (bankId: number | null) =>
        options.banks.find((bank) => bank.id === bankId)?.name;

    return (
        <>
            <Head title={t('hours.project.title', { project: project.name })} />

            <ProjectShell
                project={project}
                tab="horas"
                canManage={canManage}
                actions={
                    <>
                        <Button type="button" onClick={() => setCreating(true)}>
                            <Plus aria-hidden="true" />
                            {t('hours.header.log_time')}
                        </Button>
                        <ExportMenu
                            href={exportProjectTime.url(project.id, {
                                query: activeFilters(filters),
                            })}
                            label={t('hours.project.export')}
                        />
                    </>
                }
            >
                <div className="grid gap-6">
                    {scope !== 'all' ? (
                        <Alert>
                            <Info aria-hidden="true" />
                            <AlertDescription>
                                {scope === 'mine'
                                    ? t('hours.project.scope_mine')
                                    : t('hours.project.scope_team')}
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    <section
                        aria-label={t('hours.project.filters')}
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6"
                    >
                        {scope !== 'mine' && options.people.length > 1 ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor={field('person')}>
                                    {t('hours.table.person')}
                                </Label>
                                <Select
                                    value={
                                        filters.persona !== null
                                            ? String(filters.persona)
                                            : ALL
                                    }
                                    onValueChange={(value) =>
                                        apply({
                                            persona:
                                                value === ALL
                                                    ? null
                                                    : Number(value),
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id={field('person')}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            {t('common.all')}
                                        </SelectItem>
                                        {options.people.map((person) => (
                                            <SelectItem
                                                key={person.id}
                                                value={String(person.id)}
                                            >
                                                {person.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : null}
                        <div className="grid gap-1.5">
                            <Label htmlFor={field('from')}>
                                {t('hours.locks.from')}
                            </Label>
                            <DatePicker
                                id={field('from')}
                                value={filters.desde}
                                onChange={(value) => apply({ desde: value })}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={field('to')}>
                                {t('hours.locks.to')}
                            </Label>
                            <DatePicker
                                id={field('to')}
                                value={filters.hasta}
                                onChange={(value) => apply({ hasta: value })}
                            />
                        </div>
                        {options.banks.length > 0 ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor={field('bank')}>
                                    {t('hours.project.bank')}
                                </Label>
                                <Select
                                    value={
                                        filters.bolsa !== null
                                            ? String(filters.bolsa)
                                            : ALL
                                    }
                                    onValueChange={(value) =>
                                        apply({
                                            bolsa:
                                                value === ALL
                                                    ? null
                                                    : Number(value),
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id={field('bank')}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            {t('common.all')}
                                        </SelectItem>
                                        {options.banks.map((bank) => (
                                            <SelectItem
                                                key={bank.id}
                                                value={String(bank.id)}
                                            >
                                                {bank.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : null}
                        <div className="grid gap-1.5">
                            <Label htmlFor={field('status')}>
                                {t('hours.table.status')}
                            </Label>
                            <Select
                                value={filters.estado ?? ALL}
                                onValueChange={(value) =>
                                    apply({
                                        estado:
                                            value === ALL
                                                ? null
                                                : (value as TimeEntryStatus),
                                    })
                                }
                            >
                                <SelectTrigger
                                    id={field('status')}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        {t('common.all')}
                                    </SelectItem>
                                    {STATUSES.map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {t(`time_entry.status.${status}`)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={field('billable')}>
                                {t('hours.table.billable')}
                            </Label>
                            <Select
                                value={filters.facturable ?? ALL}
                                onValueChange={(value) =>
                                    apply({
                                        facturable:
                                            value === ALL
                                                ? null
                                                : (value as 'si' | 'no'),
                                    })
                                }
                            >
                                <SelectTrigger
                                    id={field('billable')}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        {t('common.all')}
                                    </SelectItem>
                                    <SelectItem value="si">
                                        {t('common.yes')}
                                    </SelectItem>
                                    <SelectItem value="no">
                                        {t('common.no')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        {hasFilters ? (
                            <div className="flex items-end">
                                <Button asChild variant="ghost" size="sm">
                                    <Link
                                        href={projectTime(project.id)}
                                        preserveScroll
                                    >
                                        {t('hours.project.clear_filters')}
                                    </Link>
                                </Button>
                            </div>
                        ) : null}
                    </section>

                    <section aria-label={t('hours.project.totals')}>
                        <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <Stat
                                label={t('hours.project.total')}
                                value={formatMinutes(totals.minutes)}
                                hint={t('hours.project.entries', {
                                    count: totals.entries,
                                })}
                            />
                            <Stat
                                label={t('hours.project.in_bank')}
                                value={formatMinutes(totals.in_bank_minutes)}
                            />
                            <Stat
                                label={t('hours.project.overage')}
                                value={formatMinutes(totals.overage_minutes)}
                                danger={totals.overage_minutes > 0}
                            />
                            <Stat
                                label={t('hours.project.billable')}
                                value={formatMinutes(totals.billable_minutes)}
                            />
                        </dl>
                    </section>

                    {entries.data.length === 0 ? (
                        <EmptyState
                            icon={Clock}
                            title={
                                hasFilters
                                    ? t('hours.project.empty_filtered')
                                    : t('hours.project.empty')
                            }
                        />
                    ) : (
                        <div className="grid gap-3">
                            <div
                                className={cn(
                                    'overflow-x-auto rounded-md border',
                                    FOCUS_RING,
                                )}
                                role="region"
                                aria-label={t('hours.project.table_label')}
                                tabIndex={0}
                            >
                                <table className="w-full min-w-[56rem] text-sm">
                                    <caption className="sr-only">
                                        {t('hours.project.table_label')}
                                    </caption>
                                    <thead>
                                        <tr className="border-b bg-muted text-left">
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.date')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.person')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.task')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.description')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {t('hours.table.hours')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.billable')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.status')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2"
                                            >
                                                <span className="sr-only">
                                                    {t('common.actions')}
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {entries.data.map((entry) => (
                                            <tr
                                                key={entry.id}
                                                className="border-b last:border-b-0 even:bg-muted/60"
                                                data-test="project-time-row"
                                            >
                                                <td className="tabular px-3 py-2 whitespace-nowrap">
                                                    {formatDate(entry.date)}
                                                </td>
                                                <td className="px-3 py-2 whitespace-nowrap">
                                                    {entry.user?.name}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <Link
                                                        href={urls.task(
                                                            project.id,
                                                            entry.task_id,
                                                        )}
                                                        className={cn(
                                                            'rounded-sm hover:underline',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        {entry.task?.title}
                                                    </Link>
                                                    {bankName(
                                                        entry.hour_bank_id,
                                                    ) ? (
                                                        <span className="block text-xs text-muted-foreground">
                                                            {bankName(
                                                                entry.hour_bank_id,
                                                            )}
                                                        </span>
                                                    ) : null}
                                                </td>
                                                <td className="max-w-[18rem] px-3 py-2 break-words text-muted-foreground">
                                                    {entry.description ?? '—'}
                                                </td>
                                                <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                                    {formatMinutes(
                                                        entry.minutes,
                                                    )}
                                                    {entry.overage_minutes >
                                                    0 ? (
                                                        <span className="mt-0.5 flex items-center justify-end gap-1 text-xs">
                                                            <TriangleAlert
                                                                aria-hidden="true"
                                                                className="size-3 text-danger"
                                                            />
                                                            {t(
                                                                'hours.cell.overage',
                                                                {
                                                                    minutes:
                                                                        formatMinutes(
                                                                            entry.overage_minutes,
                                                                        ),
                                                                },
                                                            )}
                                                        </span>
                                                    ) : null}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {entry.is_billable
                                                        ? t('common.yes')
                                                        : t('common.no')}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <TimeEntryStatusBadge
                                                        status={entry.status}
                                                    />
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {entry.can_edit ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setEditing(
                                                                    entry,
                                                                )
                                                            }
                                                            aria-label={t(
                                                                'hours.project.edit_entry',
                                                                {
                                                                    date: formatDate(
                                                                        entry.date,
                                                                    ),
                                                                    minutes:
                                                                        formatMinutes(
                                                                            entry.minutes,
                                                                        ),
                                                                },
                                                            )}
                                                        >
                                                            <Pencil aria-hidden="true" />
                                                            {t('common.edit')}
                                                        </Button>
                                                    ) : null}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <nav
                                aria-label={t('hours.project.pagination')}
                                className="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <span className="text-muted-foreground">
                                    {t('hours.project.showing', {
                                        from: entries.meta.from ?? 0,
                                        to: entries.meta.to ?? 0,
                                        total: entries.meta.total,
                                    })}
                                </span>
                                <div className="flex gap-2">
                                    {entries.links.prev ? (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={entries.links.prev}
                                                preserveScroll
                                                preserveState
                                            >
                                                {t('hours.project.previous')}
                                            </Link>
                                        </Button>
                                    ) : null}
                                    {entries.links.next ? (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={entries.links.next}
                                                preserveScroll
                                                preserveState
                                            >
                                                {t('hours.project.next')}
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            </nav>
                        </div>
                    )}
                </div>
            </ProjectShell>

            <TimeEntryDialog
                open={editing !== null || creating}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                        setCreating(false);
                    }
                }}
                entry={editing}
            />
        </>
    );
}

function Stat({
    label,
    value,
    hint,
    danger = false,
}: {
    label: string;
    value: string;
    hint?: string;
    danger?: boolean;
}) {
    return (
        <div
            className={cn(
                'rounded-md border p-3',
                danger && 'border-danger bg-danger-soft',
            )}
        >
            <dt className="flex items-center gap-1 text-xs text-muted-foreground">
                {danger ? (
                    <TriangleAlert
                        aria-hidden="true"
                        className="size-3.5 text-danger"
                    />
                ) : null}
                {label}
            </dt>
            <dd className="tabular text-xl font-medium">{value}</dd>
            {hint ? (
                <dd className="text-xs text-muted-foreground">{hint}</dd>
            ) : null}
        </div>
    );
}
