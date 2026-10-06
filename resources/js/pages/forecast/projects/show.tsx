import { Head } from '@inertiajs/react';
import { formatLoadPercent } from '@/components/charts/thresholds';
import { formatDate, formatMinutes } from '@/lib/format';
import {
    allocationAmountLabel,
    allocationWho,
    deviationLabel,
} from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { index as boardIndex } from '@/routes/forecast';
import { index, show } from '@/routes/forecast/projects';
import type { ForecastProjectPageProps, ImpactCell } from '@/types/forecast';

function ImpactRow({
    name,
    cells,
    keys,
}: {
    name: string;
    cells: ImpactCell[];
    keys: string[];
}) {
    return (
        <tr>
            <th className="px-2 py-1 text-left font-normal">{name}</th>
            {cells.map((cell, position) => (
                <td
                    key={keys[position] ?? position}
                    className="px-2 py-1 text-right tabular-nums"
                >
                    {formatLoadPercent(cell.without, cell.capacity)} →{' '}
                    {formatLoadPercent(cell.with, cell.capacity)}
                </td>
            ))}
        </tr>
    );
}

/**
 * Ficha de un proyecto previsto (`/prevision/proyectos/{id}`, D-281 a D-287). PROVISIONAL: tablas
 * con los datos del contrato (ForecastProjectPageProps); el impacto y «estimado frente a real»
 * llegan en una petición aparte.
 */
export default function ForecastProjectShow({
    forecast,
    allocations,
    months,
    totals,
    impact,
    estimate,
}: ForecastProjectPageProps) {
    return (
        <>
            <Head title={forecast.name} />
            <div className="flex w-full flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight">
                        {forecast.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('forecast.provisional')}
                    </p>
                    <p className="text-sm">
                        {forecast.client_name ?? ''} ·{' '}
                        {t(`forecast.confidence.${forecast.confidence}`)} ·{' '}
                        {t(`forecast.status.${forecast.status}`)}
                        {forecast.project
                            ? ` · ${t('forecast.show.linked_to', { project: forecast.project.code })}`
                            : ''}
                    </p>
                    {forecast.starts_in_past ? (
                        <p className="text-warning-text text-sm">
                            {t('forecast.show.starts_in_past')}
                        </p>
                    ) : null}
                </header>

                <section className="overflow-x-auto">
                    <h2 className="mb-2 text-lg font-normal">
                        {t('forecast.show.allocations')}
                    </h2>
                    {allocations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('forecast.show.no_allocations')}
                        </p>
                    ) : (
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
                                        {t('forecast.show.total')}
                                    </th>
                                    {months.map((month) => (
                                        <th
                                            key={month}
                                            className="px-2 py-1 text-right font-normal"
                                        >
                                            {month}
                                        </th>
                                    ))}
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
                                                ? formatDate(
                                                      allocation.end_date,
                                                  )
                                                : t('forecast.show.open_end')}
                                        </td>
                                        <td className="px-2 py-1 text-right tabular-nums">
                                            {formatMinutes(
                                                allocation.planned_minutes,
                                            )}
                                        </td>
                                        {months.map((month) => (
                                            <td
                                                key={month}
                                                className="px-2 py-1 text-right tabular-nums"
                                            >
                                                {allocation.months[month]
                                                    ? formatMinutes(
                                                          allocation.months[
                                                              month
                                                          ],
                                                      )
                                                    : '—'}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    <p className="mt-2 text-sm">
                        {t('forecast.show.allocated', {
                            allocated: formatMinutes(totals.allocated_minutes),
                            estimated:
                                totals.estimated_minutes === null
                                    ? '—'
                                    : formatMinutes(totals.estimated_minutes),
                        })}
                    </p>
                </section>

                {impact && impact.buckets.length > 0 ? (
                    <section className="overflow-x-auto">
                        <h2 className="mb-2 text-lg font-normal">
                            {t('forecast.show.impact')}
                        </h2>
                        <table className="text-sm">
                            <thead>
                                <tr>
                                    <th />
                                    {impact.buckets.map((bucket) => (
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
                                {impact.departments.map((row) => (
                                    <ImpactRow
                                        key={`department-${row.id ?? 'none'}`}
                                        name={
                                            row.name ??
                                            t('forecast.board.no_department')
                                        }
                                        cells={row.cells}
                                        keys={impact.buckets.map(
                                            (bucket) => bucket.key,
                                        )}
                                    />
                                ))}
                                {impact.people.map((row) => (
                                    <ImpactRow
                                        key={`person-${row.id}`}
                                        name={row.name}
                                        cells={row.cells}
                                        keys={impact.buckets.map(
                                            (bucket) => bucket.key,
                                        )}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </section>
                ) : null}

                {estimate ? (
                    <section className="space-y-1 text-sm">
                        <h2 className="mb-2 text-lg font-normal">
                            {t('forecast.show.estimate')}
                        </h2>
                        <p>
                            {t('forecast.show.estimated')}:{' '}
                            {formatMinutes(estimate.totals.estimated)} ·{' '}
                            {t('forecast.show.actual')}:{' '}
                            {formatMinutes(estimate.totals.actual)} ·{' '}
                            {t('forecast.show.deviation')}:{' '}
                            {deviationLabel(estimate.totals.deviation_percent)}
                        </p>
                    </section>
                ) : null}
            </div>
        </>
    );
}

ForecastProjectShow.layout = (props: ForecastProjectPageProps) => ({
    breadcrumbs: [
        { title: t('forecast.title'), href: boardIndex() },
        { title: t('forecast.nav.projects'), href: index() },
        { title: props.forecast.name, href: show(props.forecast.id) },
    ],
});
