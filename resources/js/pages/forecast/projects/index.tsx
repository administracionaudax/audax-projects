import { Head, Link } from '@inertiajs/react';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { index as boardIndex } from '@/routes/forecast';
import { index, show } from '@/routes/forecast/projects';
import type {
    ForecastListFilter,
    ForecastProjectsPageProps,
} from '@/types/forecast';

const FILTERS: ForecastListFilter[] = ['active', 'lost', 'linked', 'all'];

/**
 * Proyectos previstos (`/prevision/proyectos`, D-281). PROVISIONAL: una tabla con los datos del
 * contrato (ForecastProjectsPageProps).
 */
export default function ForecastProjectsIndex({
    projects,
}: ForecastProjectsPageProps) {
    const title = t('forecast.nav.projects');

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
                    <nav className="flex flex-wrap gap-4 text-sm">
                        {FILTERS.map((filter) => (
                            <Link
                                key={filter}
                                href={index.url({ query: { estado: filter } })}
                            >
                                {t(`forecast.filters.${filter}`)}
                            </Link>
                        ))}
                        <Link href={boardIndex.url()}>
                            {t('forecast.nav.board')}
                        </Link>
                    </nav>
                </header>

                {projects.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('forecast.list.empty')}
                    </p>
                ) : (
                    <table className="text-sm">
                        <thead>
                            <tr>
                                <th className="px-2 py-1 text-left font-normal">
                                    {t('forecast.list.name')}
                                </th>
                                <th className="px-2 py-1 text-left font-normal">
                                    {t('forecast.list.client')}
                                </th>
                                <th className="px-2 py-1 text-left font-normal">
                                    {t('forecast.list.confidence')}
                                </th>
                                <th className="px-2 py-1 text-left font-normal">
                                    {t('forecast.list.dates')}
                                </th>
                                <th className="px-2 py-1 text-right font-normal">
                                    {t('forecast.list.hours')}
                                </th>
                                <th className="px-2 py-1 text-left font-normal">
                                    {t('forecast.list.status')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {projects.map((project) => (
                                <tr key={project.id}>
                                    <td className="px-2 py-1">
                                        <Link href={show.url(project.id)}>
                                            {project.name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-1">
                                        {project.client
                                            ? project.client.name
                                            : t('forecast.list.new_client', {
                                                  name:
                                                      project.prospect_name ??
                                                      '',
                                              })}
                                    </td>
                                    <td className="px-2 py-1">
                                        {t(
                                            `forecast.confidence.${project.confidence}`,
                                        )}
                                    </td>
                                    <td className="px-2 py-1">
                                        {formatDate(project.start_date)} –{' '}
                                        {formatDate(project.end_date)}
                                    </td>
                                    <td className="px-2 py-1 text-right tabular-nums">
                                        {formatMinutes(
                                            project.allocated_minutes ?? 0,
                                        )}
                                    </td>
                                    <td className="px-2 py-1">
                                        {t(`forecast.status.${project.status}`)}
                                        {project.project
                                            ? ` → ${project.project.code}`
                                            : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </>
    );
}

ForecastProjectsIndex.layout = {
    breadcrumbs: [
        { title: t('forecast.title'), href: boardIndex() },
        { title: t('forecast.nav.projects'), href: index() },
    ],
};
