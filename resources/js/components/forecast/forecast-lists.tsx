import { Link } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import { AssignGapDialog } from '@/components/forecast/assign-gap-dialog';
import { LayerBadge } from '@/components/forecast/layer-badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { allocationAmountLabel, dateRange } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { index as forecastProjects, show } from '@/routes/forecast/projects';
import type { ForecastGap, ForecastProject } from '@/types/forecast';

function ListSkeleton() {
    return (
        <div className="grid gap-2" role="status" aria-label={t('forecast.loading')}>
            <Skeleton className="h-10" />
            <Skeleton className="h-10" />
            <Skeleton className="h-10" />
        </div>
    );
}

/**
 * «Huecos sin persona» (D-293): el departamento, el proyecto con su seguridad, cuánto y cuándo, y
 * «Asignar a…» (con la ocupación de cada candidato en esas fechas).
 */
export function GapsList({ gaps }: { gaps?: ForecastGap[] }) {
    const [assigning, setAssigning] = useState<ForecastGap | null>(null);

    return (
        <section aria-labelledby="forecast-gaps-title" className="flex min-w-0 flex-col gap-3 border bg-card p-4" data-test="forecast-gaps">
            <div>
                <h2 id="forecast-gaps-title" className="text-base font-medium">
                    {t('forecast.gaps.title')}
                </h2>
                <p className="text-sm text-muted-foreground">{t('forecast.gaps.description')}</p>
            </div>
            {gaps === undefined ? (
                <ListSkeleton />
            ) : gaps.length === 0 ? (
                <p className="text-sm text-muted-foreground">{t('forecast.gaps.empty')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr>
                                <th scope="col" className="px-3 py-2 text-left">{t('forecast.gaps.gap')}</th>
                                <th scope="col" className="px-3 py-2 text-left">{t('forecast.gaps.when')}</th>
                                <th scope="col" className="px-3 py-2 text-right">
                                    <span className="sr-only">{t('forecast.gaps.actions')}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {gaps.map((gap) => (
                                <tr key={gap.allocation.id} className="border-b last:border-0" data-test="forecast-gap">
                                    <td className="px-3 py-2 align-top">
                                        <p>{gap.allocation.department?.name}</p>
                                        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                            <LayerBadge layer={gap.container.layer} className="text-foreground" />
                                            {gap.container.kind === 'forecast' ? (
                                                <Link href={show.url(gap.container.id)} className="text-primary-text hover:underline">
                                                    {[gap.container.client_name, gap.container.name].filter(Boolean).join(' · ')}
                                                </Link>
                                            ) : (
                                                <span>{[gap.container.client_name, gap.container.name].filter(Boolean).join(' · ')}</span>
                                            )}
                                        </p>
                                    </td>
                                    <td className="tabular px-3 py-2 align-top whitespace-nowrap">
                                        {allocationAmountLabel(gap.allocation)}
                                        <p className="text-xs text-muted-foreground">
                                            {dateRange(gap.allocation.start_date, gap.allocation.end_date)}
                                        </p>
                                    </td>
                                    <td className="px-3 py-2 text-right align-top">
                                        {gap.allocation.can.assign ? (
                                            <Button type="button" variant="outline" size="sm" onClick={() => setAssigning(gap)}>
                                                <UserPlus aria-hidden="true" />
                                                {t('forecast.assign.open')}
                                                <span className="sr-only">
                                                    {` ${gap.allocation.department?.name ?? ''} · ${gap.container.name}`}
                                                </span>
                                            </Button>
                                        ) : null}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {assigning ? (
                <AssignGapDialog
                    allocation={assigning.allocation}
                    departmentName={assigning.allocation.department?.name ?? ''}
                    open
                    onOpenChange={(open) => (open ? null : setAssigning(null))}
                />
            ) : null}
        </section>
    );
}

/** «Proyectos previstos abiertos» del horizonte, seguros y posibles (D-302). */
export function OpenForecastsList({ forecasts }: { forecasts?: ForecastProject[] }) {
    return (
        <section aria-labelledby="forecast-open-title" className="flex min-w-0 flex-col gap-3 border bg-card p-4" data-test="forecast-open">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 id="forecast-open-title" className="text-base font-medium">
                        {t('forecast.open.title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">{t('forecast.open.description')}</p>
                </div>
                <Link href={forecastProjects.url()} className="text-sm text-primary-text hover:underline">
                    {t('forecast.open.all')}
                </Link>
            </div>
            {forecasts === undefined ? (
                <ListSkeleton />
            ) : forecasts.length === 0 ? (
                <p className="text-sm text-muted-foreground">{t('forecast.open.empty')}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr>
                                <th scope="col" className="px-3 py-2 text-left">{t('forecast.list.name')}</th>
                                <th scope="col" className="px-3 py-2 text-left">{t('forecast.list.confidence')}</th>
                                <th scope="col" className="px-3 py-2 text-left">{t('forecast.list.dates')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {forecasts.map((forecast) => (
                                <tr key={forecast.id} className="border-b last:border-0">
                                    <td className="px-3 py-2 align-top">
                                        <Link href={show.url(forecast.id)} className="text-primary-text hover:underline">
                                            {[forecast.client_name, forecast.name].filter(Boolean).join(' · ')}
                                        </Link>
                                        {forecast.client === null && forecast.prospect_name ? (
                                            <p className="text-xs text-muted-foreground">{t('forecast.badge.new_client')}</p>
                                        ) : null}
                                    </td>
                                    <td className="px-3 py-2 align-top">
                                        <LayerBadge layer={forecast.confidence === 'firm' ? 'firm' : 'tentative'} />
                                    </td>
                                    <td className="tabular px-3 py-2 align-top whitespace-nowrap">
                                        {forecast.start_date ? dateRange(forecast.start_date, forecast.end_date) : t('forecast.dates.none')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
