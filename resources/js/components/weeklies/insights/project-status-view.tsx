import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Info, SearchX } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    consumedText,
    ProjectCodeBadge,
    ProjectDeltaIndicator,
    ProjectKindBadges,
    ProjectKindChip,
    ProjectProgressBar,
} from '@/components/weeklies/insights/project-kind';
import { ClientIcon } from '@/components/weeklies/weekly-ui';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    compareEntries,
    expectedPercent,
    isOverBudget,
    kindLabel,
    PREFIX_TO_TAG,
    PROJECT_TAGS,
    progressPercent,
    tagLabel,
} from '@/lib/project-status';
import { cn } from '@/lib/utils';
import { show as showClient } from '@/routes/clients';
import { show as showProject } from '@/routes/projects';
import type {
    ProjectKindTag,
    ProjectStatusClient,
    ProjectStatusEntry,
} from '@/types/weekly-insights';

type View = 'cards' | 'table';
type SortKey = 'client' | 'kind' | 'progress';

/** Clases de una tabla con la cabecera fija al desplazar (F-020). */
export const STICKY_TABLE_WRAPPER = 'max-h-[70vh] overflow-auto border';
export const STICKY_TH = 'sticky top-0 z-10 bg-card';

function barLabel(entry: ProjectStatusEntry): string {
    return entry.budget_minutes && entry.budget_minutes > 0
        ? t('weeklies.project_status.bar', {
              consumed: formatMinutes(entry.consumed_minutes),
              budget: formatMinutes(entry.budget_minutes),
              percent: Math.round(progressPercent(entry)),
          })
        : t('weeklies.project_status.no_budget');
}

function ExpectedLine({ entry }: { entry: ProjectStatusEntry }) {
    const percent = expectedPercent(entry);

    if (entry.billing_type !== 'monthly_fee' || percent === null) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
            <span>
                {t('weeklies.project_status.expected', {
                    time: formatMinutes(entry.expected_minutes ?? 0),
                    percent,
                })}
            </span>
            <ProjectDeltaIndicator entry={entry} />
        </div>
    );
}

/** Tarjeta de un proyecto en la vista por cliente (renderProjectEntry de WeeklySync). */
export function ProjectStatusCard({ entry }: { entry: ProjectStatusEntry }) {
    const progress = progressPercent(entry);
    const hasBudget = (entry.budget_minutes ?? 0) > 0;

    return (
        <article
            className="grid min-w-0 gap-2 border bg-card p-3"
            data-test="project-status-card"
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <ProjectCodeBadge code={entry.code} />
                        <ProjectKindChip code={entry.kind_code} />
                    </div>
                    <Link
                        href={showProject.url(entry.project_id)}
                        className={cn(
                            'block truncate text-sm hover:underline',
                            FOCUS_RING,
                        )}
                    >
                        {entry.name}
                    </Link>
                </div>
                <div className="shrink-0 pl-2 text-right">
                    <p className="tabular text-sm font-semibold">
                        {consumedText(entry)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {hasBudget
                            ? t('weeklies.project_status.consumed_pct', {
                                  percent: Math.round(progress),
                              })
                            : t('weeklies.project_status.no_budget')}
                    </p>
                </div>
            </div>
            {hasBudget ? (
                <ProjectProgressBar
                    progress={progress}
                    expected={
                        entry.billing_type === 'monthly_fee'
                            ? expectedPercent(entry)
                            : null
                    }
                    over={isOverBudget(entry)}
                    label={barLabel(entry)}
                />
            ) : null}
            <ExpectedLine entry={entry} />
            {entry.week_minutes > 0 ? (
                <p className="text-xs text-muted-foreground">
                    {t('weeklies.project_status.week', {
                        time: formatMinutes(entry.week_minutes),
                    })}
                </p>
            ) : null}
        </article>
    );
}

function SortButton({
    column,
    label,
    sort,
    onSort,
}: {
    column: SortKey;
    label: string;
    sort: { key: SortKey; dir: 'asc' | 'desc' };
    onSort: (key: SortKey) => void;
}) {
    const active = sort.key === column;
    const Icon = sort.dir === 'asc' ? ArrowUp : ArrowDown;

    return (
        <button
            type="button"
            onClick={() => onSort(column)}
            className={cn('inline-flex items-center gap-1', FOCUS_RING)}
            aria-label={t('weeklies.project_status.sort_by', {
                column: label,
            })}
        >
            {label}
            {active ? (
                <Icon aria-hidden="true" className="size-3.5" />
            ) : (
                <span aria-hidden="true" className="size-3.5" />
            )}
        </button>
    );
}

/**
 * «Estado de proyectos» (F-119 a F-121, ProjectStatusView de WeeklySync sin la subida por OCR): la
 * cartera por cliente o en tabla, con filtros por cliente y tipo y la tabla ordenable por cliente,
 * tipo o progreso.
 */
export function ProjectStatusView({
    clients,
}: {
    clients: ProjectStatusClient[];
}) {
    const id = useId();
    const [view, setView] = useState<View>('cards');
    const [clientFilter, setClientFilter] = useState('');
    const [kindFilter, setKindFilter] = useState<ProjectKindTag | ''>('');
    const [sort, setSort] = useState<{ key: SortKey; dir: 'asc' | 'desc' }>({
        key: 'client',
        dir: 'asc',
    });

    const withProjects = useMemo(
        () => clients.filter((group) => group.projects.length > 0),
        [clients],
    );
    const presentTags = useMemo(() => {
        const tags = new Set<ProjectKindTag>();
        withProjects.forEach((group) =>
            group.projects.forEach((entry) =>
                tags.add(PREFIX_TO_TAG[entry.kind_code]),
            ),
        );

        return PROJECT_TAGS.filter((tag) => tags.has(tag));
    }, [withProjects]);

    const filtered = useMemo(
        () =>
            withProjects
                .filter(
                    (group) =>
                        clientFilter === '' ||
                        String(group.client.id) === clientFilter,
                )
                .map((group) => ({
                    ...group,
                    projects: group.projects
                        .filter(
                            (entry) =>
                                kindFilter === '' ||
                                PREFIX_TO_TAG[entry.kind_code] === kindFilter,
                        )
                        .sort(compareEntries),
                }))
                .filter((group) => group.projects.length > 0),
        [withProjects, clientFilter, kindFilter],
    );

    const rows = useMemo(() => {
        const flat = filtered.flatMap((group) =>
            group.projects.map((entry) => ({ client: group.client, entry })),
        );
        const factor = sort.dir === 'asc' ? 1 : -1;

        return flat.sort((a, b) => {
            if (sort.key === 'kind') {
                return factor * compareEntries(a.entry, b.entry);
            }

            if (sort.key === 'progress') {
                return (
                    factor *
                    (progressPercent(a.entry) - progressPercent(b.entry))
                );
            }

            return (
                factor * a.client.name.localeCompare(b.client.name, 'es') ||
                compareEntries(a.entry, b.entry)
            );
        });
    }, [filtered, sort]);

    const filteredOn = clientFilter !== '' || kindFilter !== '';
    const onSort = (key: SortKey) =>
        setSort((current) => ({
            key,
            dir: current.key === key && current.dir === 'asc' ? 'desc' : 'asc',
        }));

    if (withProjects.length === 0) {
        return (
            <EmptyState
                icon={SearchX}
                title={t('weeklies.project_status.empty_title')}
                description={t('weeklies.project_status.empty_description')}
            />
        );
    }

    return (
        <div className="grid min-w-0 gap-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <form
                    role="search"
                    aria-label={t('weeklies.project_status.filters_label')}
                    className="grid w-full gap-3 sm:w-auto sm:grid-cols-[14rem_12rem_auto] sm:items-end"
                    onSubmit={(event) => event.preventDefault()}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${id}-client`}>
                            {t('weeklies.project_status.filter_client')}
                        </Label>
                        <NativeSelect
                            id={`${id}-client`}
                            value={clientFilter}
                            onChange={(event) =>
                                setClientFilter(event.target.value)
                            }
                            data-test="project-status-client"
                        >
                            <option value="">
                                {t('weeklies.project_status.all_clients')}
                            </option>
                            {withProjects.map((group) => (
                                <option
                                    key={group.client.id}
                                    value={group.client.id}
                                >
                                    {group.client.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${id}-kind`}>
                            {t('weeklies.project_status.filter_kind')}
                        </Label>
                        <NativeSelect
                            id={`${id}-kind`}
                            value={kindFilter}
                            onChange={(event) =>
                                setKindFilter(
                                    event.target.value as ProjectKindTag | '',
                                )
                            }
                            data-test="project-status-kind"
                        >
                            <option value="">
                                {t('weeklies.project_status.all_kinds')}
                            </option>
                            {presentTags.map((tag) => (
                                <option key={tag} value={tag}>
                                    {tagLabel(tag)}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    {filteredOn ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                setClientFilter('');
                                setKindFilter('');
                            }}
                        >
                            {t('weeklies.project_status.clear')}
                        </Button>
                    ) : null}
                </form>
                <div
                    role="group"
                    aria-label={t('weeklies.project_status.view_label')}
                    className="inline-flex border"
                >
                    {(['cards', 'table'] as const).map((option) => (
                        <button
                            key={option}
                            type="button"
                            aria-pressed={view === option}
                            onClick={() => setView(option)}
                            className={cn(
                                'px-3 py-1.5 text-sm',
                                view === option
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                                FOCUS_RING,
                            )}
                            data-test={`project-status-view-${option}`}
                        >
                            {option === 'cards'
                                ? t('weeklies.project_status.view_cards')
                                : t('weeklies.project_status.view_table')}
                        </button>
                    ))}
                </div>
            </div>

            {filtered.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('weeklies.project_status.empty_filtered')}
                </p>
            ) : view === 'cards' ? (
                <div className="grid gap-4">
                    {filtered.map((group) => (
                        <section
                            key={group.client.id}
                            aria-labelledby={`${id}-client-${group.client.id}`}
                            className="border"
                            data-test="project-status-client-card"
                        >
                            <header className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/50 p-3">
                                <div className="flex min-w-0 items-center gap-2">
                                    <ClientIcon
                                        icon={group.client.icon}
                                        className="size-9 text-xl"
                                    />
                                    <h2
                                        id={`${id}-client-${group.client.id}`}
                                        className="min-w-0 truncate text-base"
                                    >
                                        <Link
                                            href={showClient.url(
                                                group.client.id,
                                            )}
                                            className={cn(
                                                'hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {group.client.name}
                                        </Link>
                                    </h2>
                                </div>
                                <ProjectKindBadges badges={group.badges} />
                            </header>
                            <div className="grid gap-3 p-3 xl:grid-cols-2">
                                {group.projects.map((entry) => (
                                    <ProjectStatusCard
                                        key={entry.project_id}
                                        entry={entry}
                                    />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            ) : (
                <div
                    className={cn(STICKY_TABLE_WRAPPER, FOCUS_RING)}
                    role="region"
                    aria-label={t('weeklies.project_status.table_label')}
                    tabIndex={0}
                >
                    <table className="w-full min-w-[60rem] text-sm">
                        <caption className="sr-only">
                            {t('weeklies.project_status.table_label')}
                        </caption>
                        <thead>
                            <tr className="border-b text-left text-muted-foreground">
                                <th
                                    scope="col"
                                    className={cn(
                                        STICKY_TH,
                                        'px-3 py-2 font-medium',
                                    )}
                                    aria-sort={
                                        sort.key === 'client'
                                            ? sort.dir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    }
                                >
                                    <SortButton
                                        column="client"
                                        label={t(
                                            'weeklies.project_status.column.client',
                                        )}
                                        sort={sort}
                                        onSort={onSort}
                                    />
                                </th>
                                <th
                                    scope="col"
                                    className={cn(
                                        STICKY_TH,
                                        'px-3 py-2 font-medium',
                                    )}
                                >
                                    {t(
                                        'weeklies.project_status.column.project',
                                    )}
                                </th>
                                <th
                                    scope="col"
                                    className={cn(
                                        STICKY_TH,
                                        'px-3 py-2 font-medium',
                                    )}
                                    aria-sort={
                                        sort.key === 'kind'
                                            ? sort.dir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    }
                                >
                                    <SortButton
                                        column="kind"
                                        label={t(
                                            'weeklies.project_status.column.kind',
                                        )}
                                        sort={sort}
                                        onSort={onSort}
                                    />
                                </th>
                                <th
                                    scope="col"
                                    className={cn(
                                        STICKY_TH,
                                        'px-3 py-2 font-medium',
                                    )}
                                >
                                    {t('weeklies.project_status.column.hours')}
                                </th>
                                <th
                                    scope="col"
                                    className={cn(
                                        STICKY_TH,
                                        'px-3 py-2 font-medium',
                                    )}
                                    aria-sort={
                                        sort.key === 'progress'
                                            ? sort.dir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    }
                                >
                                    <SortButton
                                        column="progress"
                                        label={t(
                                            'weeklies.project_status.column.progress',
                                        )}
                                        sort={sort}
                                        onSort={onSort}
                                    />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(({ client, entry }) => {
                                const progress = progressPercent(entry);
                                const hasBudget =
                                    (entry.budget_minutes ?? 0) > 0;
                                const expected = expectedPercent(entry);

                                return (
                                    <tr
                                        key={entry.project_id}
                                        className="border-b align-middle last:border-b-0"
                                        data-test="project-status-row"
                                    >
                                        <td className="px-3 py-2">
                                            <div className="flex min-w-44 items-center gap-2">
                                                <ClientIcon
                                                    icon={client.icon}
                                                />
                                                <span>{client.name}</span>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex min-w-64 items-center gap-2">
                                                <Link
                                                    href={showProject.url(
                                                        entry.project_id,
                                                    )}
                                                    className={cn(
                                                        'tabular border px-1.5 py-0.5 text-xs font-semibold whitespace-nowrap hover:underline',
                                                        FOCUS_RING,
                                                    )}
                                                >
                                                    {entry.code} | {entry.name}
                                                </Link>
                                                {entry.billing_type ===
                                                    'monthly_fee' &&
                                                expected !== null ? (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <button
                                                                type="button"
                                                                className={cn(
                                                                    'inline-flex size-5 items-center justify-center border text-muted-foreground',
                                                                    FOCUS_RING,
                                                                )}
                                                                aria-label={`${t(
                                                                    'weeklies.project_status.expected_short',
                                                                    {
                                                                        time: formatMinutes(
                                                                            entry.expected_minutes ??
                                                                                0,
                                                                        ),
                                                                        percent:
                                                                            expected,
                                                                    },
                                                                )}`}
                                                            >
                                                                <Info
                                                                    aria-hidden="true"
                                                                    className="size-3"
                                                                />
                                                            </button>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            {t(
                                                                'weeklies.project_status.expected_short',
                                                                {
                                                                    time: formatMinutes(
                                                                        entry.expected_minutes ??
                                                                            0,
                                                                    ),
                                                                    percent:
                                                                        expected,
                                                                },
                                                            )}
                                                        </TooltipContent>
                                                    </Tooltip>
                                                ) : null}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <ProjectKindChip
                                                code={entry.kind_code}
                                            />
                                            <span className="sr-only">
                                                {kindLabel(entry.kind_code)}
                                            </span>
                                        </td>
                                        <td className="tabular px-3 py-2 whitespace-nowrap">
                                            {hasBudget
                                                ? consumedText(entry)
                                                : `${formatMinutes(entry.consumed_minutes)} / ${t('weeklies.project_status.no_budget')}`}
                                        </td>
                                        <td className="min-w-60 px-3 py-2">
                                            <div className="grid gap-1">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="tabular text-xs font-semibold">
                                                        {hasBudget
                                                            ? `${Math.round(progress)} %`
                                                            : '—'}
                                                    </span>
                                                    {entry.billing_type ===
                                                    'monthly_fee' ? (
                                                        <ProjectDeltaIndicator
                                                            entry={entry}
                                                        />
                                                    ) : null}
                                                </div>
                                                {hasBudget ? (
                                                    <ProjectProgressBar
                                                        size="sm"
                                                        progress={progress}
                                                        expected={
                                                            entry.billing_type ===
                                                            'monthly_fee'
                                                                ? expected
                                                                : null
                                                        }
                                                        over={isOverBudget(
                                                            entry,
                                                        )}
                                                        label={barLabel(entry)}
                                                    />
                                                ) : null}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
