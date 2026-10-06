import { Head, Link } from '@inertiajs/react';
import { formatLoadPercent } from '@/components/charts/thresholds';
import { formatDate, formatMinutes } from '@/lib/format';
import { cellLoad, LAYERS } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { index } from '@/routes/forecast';
import { index as projectsIndex } from '@/routes/forecast/projects';
import type { ForecastIndexPageProps, LoadCell } from '@/types/forecast';

function Cell({ cell }: { cell: LoadCell }) {
    const load = cellLoad(cell);

    return (
        <td className="px-2 py-1 text-right tabular-nums">
            {cell.capacity > 0
                ? t('forecast.board.cell', {
                      load: formatMinutes(load),
                      capacity: formatMinutes(cell.capacity),
                      percent: formatLoadPercent(load, cell.capacity),
                  })
                : t('forecast.board.cell_no_capacity', {
                      load: formatMinutes(load),
                  })}
        </td>
    );
}

/**
 * Previsión del equipo (`/prevision`, D-285). PROVISIONAL: una tabla con los datos del contrato
 * (ForecastIndexPageProps); la pantalla definitiva saldrá del diseño de los gráficos de carga.
 */
export default function ForecastIndex({
    board,
    filters,
}: ForecastIndexPageProps) {
    const title = t('forecast.title');
    const query = (granularity: 'semanas' | 'meses') => ({
        query: {
            desde: filters.from,
            meses: filters.months,
            por: granularity,
            ...(filters.department_id
                ? { departamento: filters.department_id }
                : {}),
        },
    });

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
                    <p className="text-sm text-muted-foreground">
                        {t('forecast.board.counts_from', {
                            date: formatDate(board.period.counts_from),
                        })}
                    </p>
                    <nav className="flex gap-4 text-sm">
                        <Link href={index.url(query('meses'))}>
                            {t('forecast.board.by_month')}
                        </Link>
                        <Link href={index.url(query('semanas'))}>
                            {t('forecast.board.by_week')}
                        </Link>
                        <Link href={projectsIndex.url()}>
                            {t('forecast.nav.projects')}
                        </Link>
                    </nav>
                    <p className="text-sm text-muted-foreground">
                        {t('forecast.layers.label')}:{' '}
                        {LAYERS.map((layer) =>
                            t(`forecast.layers.${layer}`),
                        ).join(' · ')}
                    </p>
                </header>

                <section className="overflow-x-auto">
                    <h2 className="mb-2 text-lg font-normal">
                        {t('forecast.board.departments')}
                    </h2>
                    <table className="text-sm">
                        <thead>
                            <tr>
                                <th className="px-2 py-1 text-left">
                                    {t('forecast.board.department')}
                                </th>
                                {board.buckets.map((bucket) => (
                                    <th
                                        key={bucket.key}
                                        className="px-2 py-1 text-right font-normal"
                                    >
                                        {bucket.key}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {board.departments.map((department) => (
                                <tr key={department.id ?? 'none'}>
                                    <th className="px-2 py-1 text-left font-normal">
                                        {department.name ??
                                            t('forecast.board.no_department')}
                                    </th>
                                    {department.cells.map((cell, position) => (
                                        <Cell
                                            key={
                                                board.buckets[position]?.key ??
                                                position
                                            }
                                            cell={cell}
                                        />
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section className="overflow-x-auto">
                    <h2 className="mb-2 text-lg font-normal">
                        {t('forecast.board.people')}
                    </h2>
                    {board.people.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('forecast.board.empty')}
                        </p>
                    ) : (
                        <table className="text-sm">
                            <tbody>
                                {board.people.map((person) => (
                                    <tr key={person.id}>
                                        <th className="px-2 py-1 text-left font-normal">
                                            {person.name}
                                        </th>
                                        {person.cells.map((cell, position) => (
                                            <Cell
                                                key={
                                                    board.buckets[position]
                                                        ?.key ?? position
                                                }
                                                cell={cell}
                                            />
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>
            </div>
        </>
    );
}

ForecastIndex.layout = {
    breadcrumbs: [{ title: t('forecast.title'), href: index() }],
};
