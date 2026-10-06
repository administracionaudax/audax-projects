import { Head } from '@inertiajs/react';
import { formatDate, formatMinutes } from '@/lib/format';
import {
    allocationAmountLabel,
    allocationWho,
    deviationLabel,
} from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { planning } from '@/routes/projects';
import type { ProjectPlanningPageProps } from '@/types/forecast';

/**
 * Pestaña Planificación de un proyecto real (`/proyectos/{id}/planificacion`, D-284). PROVISIONAL:
 * tablas con los datos del contrato (ProjectPlanningPageProps); aún sin enlace en las pestañas.
 */
export default function ProjectPlanning({
    project,
    allocations,
    weeks,
    totals,
    forecast,
    estimate,
}: ProjectPlanningPageProps) {
    const title = `${project.code} · ${t('forecast.planning.title')}`;

    return (
        <>
            <Head title={title} />
            <div className="flex w-full flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight">
                        {title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('forecast.provisional')}
                    </p>
                    {forecast ? (
                        <p className="text-sm">
                            {t('forecast.planning.origin', {
                                name: forecast.name,
                            })}
                        </p>
                    ) : null}
                </header>

                <section className="overflow-x-auto">
                    <table className="text-sm">
                        <thead>
                            <tr>
                                <th className="px-2 py-1 text-left font-normal">
                                    {t('forecast.show.who')}
                                </th>
                                <th className="px-2 py-1 text-left font-normal">
                                    {t('forecast.show.how')}
                                </th>
                                <th className="px-2 py-1 text-left font-normal">
                                    {t('forecast.show.from')}
                                </th>
                                <th className="px-2 py-1 text-left font-normal">
                                    {t('forecast.show.to')}
                                </th>
                                <th className="px-2 py-1 text-right font-normal">
                                    {t('forecast.planning.planned')}
                                </th>
                                <th className="px-2 py-1 text-right font-normal">
                                    {t('forecast.planning.logged')}
                                </th>
                                <th className="px-2 py-1 text-right font-normal">
                                    {t('forecast.planning.remaining')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {allocations.map((allocation) => (
                                <tr key={allocation.id}>
                                    <td className="px-2 py-1">
                                        {allocationWho(allocation)}
                                    </td>
                                    <td className="px-2 py-1">
                                        {allocationAmountLabel(allocation)}
                                    </td>
                                    <td className="px-2 py-1">
                                        {formatDate(allocation.start_date)}
                                    </td>
                                    <td className="px-2 py-1">
                                        {allocation.end_date
                                            ? formatDate(allocation.end_date)
                                            : t('forecast.show.open_end')}
                                    </td>
                                    <td className="px-2 py-1 text-right tabular-nums">
                                        {formatMinutes(
                                            allocation.planned_minutes,
                                        )}
                                    </td>
                                    <td className="px-2 py-1 text-right tabular-nums">
                                        {allocation.logged_minutes === null
                                            ? '—'
                                            : formatMinutes(
                                                  allocation.logged_minutes,
                                              )}
                                    </td>
                                    <td className="px-2 py-1 text-right tabular-nums">
                                        {formatMinutes(
                                            allocation.remaining_minutes ?? 0,
                                        )}
                                        {allocation.overdue
                                            ? ` (${t('forecast.planning.overdue')})`
                                            : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={4} />
                                <td className="px-2 py-1 text-right tabular-nums">
                                    {formatMinutes(totals.planned_minutes)}
                                </td>
                                <td className="px-2 py-1 text-right tabular-nums">
                                    {formatMinutes(totals.logged_minutes)}
                                </td>
                                <td className="px-2 py-1 text-right tabular-nums">
                                    {formatMinutes(totals.remaining_minutes)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </section>

                {weeks.length > 0 ? (
                    <section className="overflow-x-auto">
                        <h2 className="mb-2 text-lg font-normal">
                            {t('forecast.planning.weeks')}
                        </h2>
                        <table className="text-sm">
                            <tbody>
                                {weeks.map((week) => (
                                    <tr key={week.key}>
                                        <th className="px-2 py-1 text-left font-normal">
                                            {week.key}
                                        </th>
                                        <td className="px-2 py-1 text-right tabular-nums">
                                            {formatMinutes(week.planned)}
                                        </td>
                                        <td className="px-2 py-1 text-right tabular-nums">
                                            {formatMinutes(week.logged)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                ) : null}

                {estimate ? (
                    <section className="text-sm">
                        <h2 className="mb-2 text-lg font-normal">
                            {t('forecast.show.estimate')}
                        </h2>
                        <p>
                            {formatMinutes(estimate.totals.estimated)} →{' '}
                            {formatMinutes(estimate.totals.actual)} ·{' '}
                            {deviationLabel(estimate.totals.deviation_percent)}
                        </p>
                    </section>
                ) : null}
            </div>
        </>
    );
}

ProjectPlanning.layout = (props: ProjectPlanningPageProps) => ({
    breadcrumbs: [
        { title: t('nav.projects'), href: urls.projects() },
        { title: props.project.name, href: urls.project(props.project.id) },
        {
            title: t('forecast.planning.title'),
            href: planning(props.project.id),
        },
    ],
});
