import { Link } from '@inertiajs/react';
import { CornerDownRight, TriangleAlert } from 'lucide-react';
import {
    CollapsibleGroupHeading,
    GroupFoldControls,
} from '@/components/collapsible-group';
import {
    HourBankStatusBadge,
    ProjectStatusBadge,
} from '@/components/domain/badges';
import { HourBankMiniMeter } from '@/components/hour-banks/hour-bank-mini-meter';
import type { CollapsedGroups } from '@/hooks/use-collapsed-groups';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { ROW_CLICK_CLASS, rowClickProps } from '@/lib/row-click';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { ProjectClientGroup, ProjectTreeBank, Project } from '@/types';

/** Lo que pinta una fila de proyecto del árbol (el listado y la ficha del cliente). */
export type TreeProject = Pick<
    Project,
    | 'id'
    | 'code'
    | 'name'
    | 'color'
    | 'billing_type'
    | 'status'
    | 'owner'
    | 'start_date'
    | 'due_date'
> & {
    /** Bolsas abiertas (activas y agotadas); null si no es de bolsas o no se pueden ver. */
    open_banks?: ProjectTreeBank[] | null;
};

function BankFigures({
    bank,
    thresholds,
}: {
    bank: ProjectTreeBank;
    thresholds?: readonly number[];
}) {
    return (
        <div className="grid min-w-40 gap-1">
            <HourBankMiniMeter
                name={bank.name}
                consumed={bank.consumed_minutes}
                total={bank.total_minutes}
                overage={bank.overage_minutes}
                thresholds={thresholds}
            />
            <span className="tabular text-xs text-muted-foreground">
                {t('projects.tree.bank_figures', {
                    consumed: formatMinutes(bank.consumed_minutes),
                    total: formatMinutes(bank.total_minutes),
                })}
            </span>
            {bank.overage_minutes > 0 ? (
                <span className="inline-flex items-center gap-1 text-xs font-medium text-danger">
                    <TriangleAlert aria-hidden="true" className="size-3.5" />
                    {t('hour_bank.overage_badge', {
                        minutes: formatMinutes(bank.overage_minutes),
                    })}
                </span>
            ) : null}
        </div>
    );
}

function Dates({ project }: { project: TreeProject }) {
    if (!project.start_date && !project.due_date) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="grid gap-0.5 text-xs">
            {project.start_date ? (
                <span>
                    {t('projects.table.start', {
                        date: formatDate(project.start_date),
                    })}
                </span>
            ) : null}
            {project.due_date ? (
                <span>
                    {t('projects.table.due', {
                        date: formatDate(project.due_date),
                    })}
                </span>
            ) : null}
        </span>
    );
}

/**
 * Proyectos de un cliente con sus bolsas abiertas debajo (D-322): código, nombre, tipo, estado,
 * gestor, fechas y el consumo de cada bolsa. Cada fila (de proyecto o de bolsa) se abre entera con
 * un clic (D-324). Lo usan el listado por clientes y la ficha del cliente.
 */
export function ProjectTreeTable({
    projects,
    label,
    thresholds,
}: {
    projects: TreeProject[];
    /** Nombre accesible de la tabla. */
    label: string;
    thresholds?: readonly number[];
}) {
    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={label}
            tabIndex={0}
        >
            <table className="w-full min-w-[52rem] text-sm">
                <caption className="sr-only">{label}</caption>
                <thead>
                    <tr className="border-b text-left text-xs text-muted-foreground">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.project')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.billing_type')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.status')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.owner')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.tree.consumption')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.dates')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {projects.flatMap((project) => [
                        <tr
                            key={`project-${project.id}`}
                            className={cn(
                                'border-b last:border-0',
                                ROW_CLICK_CLASS,
                            )}
                            {...rowClickProps}
                            data-test="tree-project"
                            data-project-id={project.id}
                        >
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-normal"
                            >
                                <Link
                                    href={urls.project(project.id)}
                                    className={cn(
                                        'group inline-flex min-w-0 items-start gap-2 rounded-md',
                                        FOCUS_RING,
                                    )}
                                    data-row-primary
                                >
                                    <span
                                        aria-hidden="true"
                                        className="mt-1.5 size-2.5 shrink-0 rounded-full"
                                        style={{
                                            backgroundColor: project.color,
                                        }}
                                    />
                                    <span className="min-w-0">
                                        <span className="block text-xs font-medium text-muted-foreground">
                                            {project.code}
                                        </span>
                                        <span className="block text-primary-text group-hover:underline">
                                            {project.name}
                                        </span>
                                    </span>
                                </Link>
                            </th>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {t(
                                    `project.billing_type.${project.billing_type}`,
                                )}
                            </td>
                            <td className="px-3 py-2">
                                <ProjectStatusBadge status={project.status} />
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {project.owner?.name ?? '—'}
                            </td>
                            <td className="px-3 py-2 text-xs text-muted-foreground">
                                {project.open_banks === undefined ||
                                project.open_banks === null
                                    ? '—'
                                    : project.open_banks.length === 0
                                      ? t('projects.table.no_open_banks')
                                      : t('projects.tree.open_banks', {
                                            count: project.open_banks.length,
                                        })}
                            </td>
                            <td className="tabular px-3 py-2 whitespace-nowrap">
                                <Dates project={project} />
                            </td>
                        </tr>,
                        ...(project.open_banks ?? []).map((bank) => (
                            <tr
                                key={`bank-${bank.id}`}
                                className={cn(
                                    'border-b bg-muted/50 last:border-0',
                                    ROW_CLICK_CLASS,
                                )}
                                {...rowClickProps}
                                data-test="tree-bank"
                            >
                                <th
                                    scope="row"
                                    className="py-2 pr-3 pl-7 text-left font-normal"
                                >
                                    <span className="inline-flex min-w-0 items-start gap-1.5">
                                        <CornerDownRight
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                        <Link
                                            href={urls.hourBank(
                                                project.id,
                                                bank.id,
                                            )}
                                            className={cn(
                                                'rounded-md hover:underline',
                                                FOCUS_RING,
                                            )}
                                            data-row-primary
                                        >
                                            <span className="sr-only">
                                                {t('projects.tree.bank')}{' '}
                                            </span>
                                            {bank.name}
                                        </Link>
                                    </span>
                                </th>
                                <td className="px-3 py-2 text-xs text-muted-foreground">
                                    {t('projects.tree.bank')}
                                </td>
                                <td className="px-3 py-2">
                                    <HourBankStatusBadge status={bank.status} />
                                </td>
                                <td className="px-3 py-2" />
                                <td className="px-3 py-2">
                                    <BankFigures
                                        bank={bank}
                                        thresholds={thresholds}
                                    />
                                </td>
                                <td className="tabular px-3 py-2 text-xs whitespace-nowrap">
                                    {bank.end_date
                                        ? t('projects.tree.bank_until', {
                                              date: formatDate(bank.end_date),
                                          })
                                        : null}
                                </td>
                            </tr>
                        )),
                    ])}
                </tbody>
            </table>
        </div>
    );
}

export function groupLabel(group: ProjectClientGroup, company: string): string {
    return group.kind === 'client' && group.client
        ? group.client.name
        : group.kind === 'internal'
          ? t('projects.tree.internal_group', { company })
          : t('projects.table.no_client');
}

/** ¿Nace desplegado? Con pocos proyectos, con una búsqueda o con un solo cliente, todo (D-322). */
export function treeExpandedByDefault(
    groups: ProjectClientGroup[],
    filtered: boolean,
): boolean {
    const total = groups.reduce((sum, group) => sum + group.projects.length, 0);

    return filtered || groups.length === 1 || total <= 25;
}

/**
 * Listado de proyectos jerarquizado por cliente (D-322): cada cliente (y el grupo de los internos,
 * con el nombre de la empresa) es un grupo plegable con sus proyectos y bolsas.
 */
export function ProjectsTree({
    groups,
    company,
    folds,
    expandedByDefault,
    thresholds,
}: {
    groups: ProjectClientGroup[];
    company: string;
    folds: CollapsedGroups;
    expandedByDefault: boolean;
    thresholds?: readonly number[];
}) {
    const keys = groups.map((group) => group.key);
    const isCollapsed = (key: string) =>
        folds.isCollapsed(key, !expandedByDefault);

    return (
        <div className="flex flex-col gap-3" data-test="projects-tree">
            {groups.length > 1 ? (
                <GroupFoldControls
                    className="self-end"
                    onExpandAll={() => folds.setMany(keys, false)}
                    onCollapseAll={() => folds.setMany(keys, true)}
                    allExpanded={keys.every((key) => !isCollapsed(key))}
                    allCollapsed={keys.every(isCollapsed)}
                />
            ) : null}
            <ul className="flex flex-col gap-2">
                {groups.map((group) => {
                    const collapsed = isCollapsed(group.key);
                    const label = groupLabel(group, company);
                    const headingId = `project-group-${group.key}`;
                    const contentId = `project-group-content-${group.key}`;
                    const banks = group.projects.reduce(
                        (sum, project) =>
                            sum + (project.open_banks?.length ?? 0),
                        0,
                    );

                    return (
                        <li
                            key={group.key}
                            className="rounded-md border"
                            data-test="project-group"
                            data-collapsed={collapsed ? 'true' : undefined}
                        >
                            <section aria-labelledby={headingId}>
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                    <CollapsibleGroupHeading
                                        id={headingId}
                                        contentId={contentId}
                                        expanded={!collapsed}
                                        onToggle={() =>
                                            folds.setCollapsed(
                                                group.key,
                                                !collapsed,
                                            )
                                        }
                                        label={label}
                                        count={t(
                                            banks > 0
                                                ? 'projects.tree.count_with_banks'
                                                : 'projects.tree.count',
                                            {
                                                count: group.projects.length,
                                                banks,
                                            },
                                        )}
                                        className="min-w-0"
                                        data-test="project-group-toggle"
                                    />
                                    {group.client ? (
                                        <Link
                                            href={urls.client(group.client.id)}
                                            className={cn(
                                                'ml-auto rounded-md text-xs text-primary-text hover:underline',
                                                FOCUS_RING,
                                            )}
                                            aria-label={t(
                                                'projects.tree.client_link',
                                                { client: group.client.name },
                                            )}
                                        >
                                            {t(
                                                'projects.tree.client_link_short',
                                            )}
                                        </Link>
                                    ) : null}
                                </div>
                                <div
                                    id={contentId}
                                    hidden={collapsed}
                                    className="border-t p-2"
                                >
                                    {collapsed ? null : (
                                        <ProjectTreeTable
                                            projects={group.projects}
                                            label={t('projects.tree.table', {
                                                group: label,
                                            })}
                                            thresholds={thresholds}
                                        />
                                    )}
                                </div>
                            </section>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
