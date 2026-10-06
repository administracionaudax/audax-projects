import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarClock, FolderSearch, Plus, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ForecastProjectDialog } from '@/components/forecast/forecast-project-dialog';
import { LayerBadge } from '@/components/forecast/layer-badge';
import { KeywordText } from '@/components/keyword-text';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { dateRange, formatHours } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { index as boardIndex } from '@/routes/forecast';
import { index, show } from '@/routes/forecast/projects';
import type {
    ForecastListFilter,
    ForecastProject,
    ForecastProjectsPageProps,
} from '@/types/forecast';

const FILTERS: ForecastListFilter[] = ['active', 'lost', 'linked', 'all'];

function wantsNew(url: string): boolean {
    return new URL(url, 'http://localhost').searchParams.get('nuevo') === '1';
}

/** Estado del previsto en una línea: «Abierto», «Perdido · Precio», «Vinculado a ARR-WEB». */
function statusLine(forecast: ForecastProject): string {
    if (forecast.status === 'linked' && forecast.project) {
        return t('forecast.show.linked_to', { project: forecast.project.code });
    }

    if (forecast.status === 'lost' && forecast.lost_reason) {
        return `${t('forecast.status.lost')} · ${forecast.lost_reason}`;
    }

    return t(`forecast.status.${forecast.status}`);
}

/**
 * Proyectos previstos (`/prevision/proyectos`, D-281 y D-302): abiertos y confirmados por defecto,
 * perdidos, vinculados o todos; cada uno con su seguridad (con la trama si es posible), fechas, lo
 * asignado frente a la estimación y su estado. «Nuevo proyecto previsto» abre el formulario (también
 * con ?nuevo=1, desde /prevision).
 */
export default function ForecastProjectsIndex({
    projects,
    filters,
    clients,
    can,
}: ForecastProjectsPageProps) {
    const page = usePage();
    const [creating, setCreating] = useState(
        () => can.create && wantsNew(page.url),
    );

    // Quita ?nuevo=1 de la dirección cuando ya han llegado los clientes (prop diferida): cambiar la
    // URL antes cancelaba su carga y el selector se quedaba en «Cargando…».
    useEffect(() => {
        if (wantsNew(page.url) && clients !== undefined) {
            router.replace({
                url: index.url({
                    query: {
                        estado:
                            filters.status === 'active'
                                ? undefined
                                : filters.status,
                    },
                }),
                preserveState: true,
                preserveScroll: true,
            });
        }
    }, [page.url, filters.status, clients]);

    return (
        <>
            <Head title={t('forecast.nav.projects')} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="max-w-2xl space-y-1">
                        <h1 className="text-2xl font-normal tracking-tight">
                            <KeywordText text={t('forecast.list.title')} />
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('forecast.list.description')}
                        </p>
                    </div>
                    {can.create ? (
                        <ForecastProjectDialog
                            clients={clients}
                            open={creating}
                            onOpenChange={setCreating}
                            trigger={
                                <Button type="button">
                                    <Plus aria-hidden="true" />
                                    {t('forecast.index.new')}
                                </Button>
                            }
                        />
                    ) : null}
                </header>

                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={filters.status}
                    onValueChange={(value) =>
                        value
                            ? router.visit(
                                  index.url({
                                      query:
                                          value === 'active'
                                              ? {}
                                              : { estado: value },
                                  }),
                                  { preserveScroll: true },
                              )
                            : null
                    }
                    aria-label={t('forecast.list.filter')}
                    className="flex-wrap justify-start"
                >
                    {FILTERS.map((filter) => (
                        <ToggleGroupItem
                            key={filter}
                            value={filter}
                            className="px-3"
                        >
                            {t(`forecast.filters.${filter}`)}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>

                {projects.length === 0 ? (
                    <EmptyState
                        icon={FolderSearch}
                        title={t('forecast.list.empty')}
                    />
                ) : (
                    <div className="overflow-x-auto border bg-card">
                        <table
                            className="w-full text-sm"
                            data-test="forecast-projects-table"
                        >
                            <thead>
                                <tr>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-left"
                                    >
                                        {t('forecast.list.name')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-left"
                                    >
                                        {t('forecast.list.confidence')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-left"
                                    >
                                        {t('forecast.list.dates')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right"
                                    >
                                        {t('forecast.list.hours')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-left"
                                    >
                                        {t('forecast.list.status')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {projects.map((forecast) => {
                                    const over =
                                        forecast.estimated_minutes !== null &&
                                        (forecast.allocated_minutes ?? 0) >
                                            forecast.estimated_minutes;

                                    return (
                                        <tr
                                            key={forecast.id}
                                            className="border-b last:border-0"
                                            data-test="forecast-project-row"
                                        >
                                            <td className="px-3 py-2.5 align-top">
                                                <Link
                                                    href={show.url(forecast.id)}
                                                    className="text-primary-text hover:underline"
                                                >
                                                    {forecast.name}
                                                </Link>
                                                <p className="text-xs text-muted-foreground">
                                                    {forecast.client_name ??
                                                        '—'}
                                                    {forecast.client === null &&
                                                    forecast.prospect_name
                                                        ? ` · ${t('forecast.badge.new_client')}`
                                                        : ''}
                                                </p>
                                            </td>
                                            <td className="px-3 py-2.5 align-top">
                                                <LayerBadge
                                                    layer={
                                                        forecast.confidence ===
                                                        'firm'
                                                            ? 'firm'
                                                            : 'tentative'
                                                    }
                                                />
                                            </td>
                                            <td className="tabular px-3 py-2.5 align-top whitespace-nowrap">
                                                {forecast.start_date
                                                    ? dateRange(
                                                          forecast.start_date,
                                                          forecast.end_date,
                                                      )
                                                    : t('forecast.dates.none')}
                                                {forecast.starts_in_past ? (
                                                    <p className="flex items-center gap-1 text-xs">
                                                        <CalendarClock
                                                            aria-hidden="true"
                                                            className="size-3 text-warning"
                                                        />
                                                        {t(
                                                            'forecast.list.starts_in_past',
                                                        )}
                                                    </p>
                                                ) : null}
                                            </td>
                                            <td className="tabular px-3 py-2.5 text-right align-top whitespace-nowrap">
                                                {formatHours(
                                                    forecast.allocated_minutes ??
                                                        0,
                                                )}
                                                {forecast.estimated_minutes !==
                                                null ? (
                                                    <p className="flex items-center justify-end gap-1 text-xs text-muted-foreground">
                                                        {over ? (
                                                            <TriangleAlert
                                                                aria-hidden="true"
                                                                className="size-3 text-warning"
                                                            />
                                                        ) : null}
                                                        {t(
                                                            'forecast.list.of_estimate',
                                                            {
                                                                hours: formatHours(
                                                                    forecast.estimated_minutes,
                                                                ),
                                                            },
                                                        )}
                                                    </p>
                                                ) : null}
                                            </td>
                                            <td className="px-3 py-2.5 align-top">
                                                {statusLine(forecast)}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
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
