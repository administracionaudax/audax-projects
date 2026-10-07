import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { ProjectStatusBadge } from '@/components/domain/badges';
import { HourBankMiniMeter } from '@/components/hour-banks/hour-bank-mini-meter';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { ROW_CLICK_CLASS, rowClickProps } from '@/lib/row-click';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { ProjectListItem } from '@/types';

/**
 * Tabla del listado de proyectos (SPEC §6): código, nombre, cliente, estado, tipo, gestor
 * principal, consumo de bolsas y fechas. En móvil se desplaza dentro de su contenedor.
 */
export function ProjectsTable({
    projects,
    thresholds,
}: {
    projects: ProjectListItem[];
    /** Umbrales configurados en % (config.hour_bank_thresholds; D-035). */
    thresholds?: readonly number[];
}) {
    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={t('projects.table.label')}
            tabIndex={0}
        >
            <table className="w-full min-w-[60rem] text-sm">
                <caption className="sr-only">
                    {t('projects.table.caption')}
                </caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.project')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.client')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.status')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.billing_type')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.owner')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.hour_banks')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('projects.table.dates')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {projects.map((project) => (
                        <tr
                            key={project.id}
                            className={cn(
                                'border-b last:border-0 even:bg-muted',
                                ROW_CLICK_CLASS,
                            )}
                            {...rowClickProps}
                        >
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-normal"
                            >
                                <Link
                                    href={urls.project(project.id)}
                                    data-row-primary
                                    className={cn(
                                        'group inline-flex min-w-0 items-start gap-2 rounded-md',
                                        FOCUS_RING,
                                    )}
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
                            <td className="px-3 py-2">
                                {project.client?.name ?? (
                                    <span className="text-muted-foreground">
                                        {t('projects.table.no_client')}
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2">
                                <ProjectStatusBadge status={project.status} />
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {t(
                                    `project.billing_type.${project.billing_type}`,
                                )}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {project.owner?.name ?? ''}
                            </td>
                            <td className="px-3 py-2">
                                <BankConsumption
                                    project={project}
                                    thresholds={thresholds}
                                />
                            </td>
                            <td className="tabular px-3 py-2 whitespace-nowrap">
                                <ProjectDates project={project} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function BankConsumption({
    project,
    thresholds,
}: {
    project: ProjectListItem;
    thresholds?: readonly number[];
}) {
    const banks = project.hour_banks;

    if (banks === null) {
        return (
            <span className="text-muted-foreground">
                {t('projects.table.not_applicable')}
            </span>
        );
    }

    if (banks.open_count === 0) {
        return (
            <span className="text-muted-foreground">
                {t('projects.table.no_open_banks')}
            </span>
        );
    }

    return (
        <div className="grid gap-1">
            <HourBankMiniMeter
                name={project.name}
                consumed={banks.consumed_minutes}
                total={banks.total_minutes}
                overage={banks.overage_minutes}
                thresholds={thresholds}
            />
            <span className="tabular text-xs text-muted-foreground">
                {t('projects.table.bank_figures', {
                    consumed: formatMinutes(banks.consumed_minutes),
                    total: formatMinutes(banks.total_minutes),
                    count: banks.open_count,
                })}
            </span>
            {banks.overage_minutes > 0 ? (
                <span className="inline-flex items-center gap-1 text-xs font-medium text-danger">
                    <TriangleAlert aria-hidden="true" className="size-3.5" />
                    {t('hour_bank.overage_badge', {
                        minutes: formatMinutes(banks.overage_minutes),
                    })}
                </span>
            ) : null}
        </div>
    );
}

function ProjectDates({ project }: { project: ProjectListItem }) {
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
