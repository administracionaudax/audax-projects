import { Deferred, Head, router } from '@inertiajs/react';
import type { VisitOptions } from '@inertiajs/core';
import { ArrowRight, CalendarOff, Info, Users, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import type {
    WorkloadColumn,
    WorkloadPageProps,
    WorkloadQuery,
    WorkloadRow,
} from '@/components/workload/types';
import { WorkloadAlerts } from '@/components/workload/workload-alerts';
import { WorkloadCellPanel } from '@/components/workload/workload-cell-panel';
import { WorkloadLegend } from '@/components/workload/workload-legend';
import { cellKey, WorkloadMatrix } from '@/components/workload/workload-matrix';
import { WorkloadToolbar } from '@/components/workload/workload-toolbar';
import { WorkloadTrays } from '@/components/workload/workload-trays';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { index } from '@/routes/workload';
import { HatchDefs } from '@/components/forecast/layer-swatch';
import {
    MyForecastSkeleton,
    MyForecastView,
} from '@/components/forecast/my-forecast';

/** Errores de una visita dentro de la página: un aviso, sin salir de ella. */
function failureHandlers(onFailure?: () => void): Partial<VisitOptions> {
    return {
        onHttpException: (response) => {
            onFailure?.();
            toast.error(
                response.status === 403
                    ? t('workload_page.error_forbidden')
                    : t('workload_page.error_server'),
            );

            return false;
        },
        onNetworkError: () => {
            onFailure?.();
            toast.error(t('workload_page.error_network'));

            return false;
        },
    };
}

/**
 * Vista «Carga» (SPEC §9, D-051, D-052): quién está sobrecargado y cómo repartir el trabajo.
 * - Matriz personas × días (o semanas en 3 meses), agrupada por departamento con totales y el
 *   semáforo de carga en cada celda. Por defecto, la semana que viene.
 * - Horizonte y filtros en la URL; el panel de una celda, con ?celda=persona:fecha (recarga
 *   parcial de la prop `cell`, sin cambiar de página). Al reasignar o replanificar, la matriz se
 *   recalcula.
 * - Bandejas «Sin planificar», «Sin asignar» y «De tus proyectos» (gestores).
 * Qué ve cada uno lo decide el servidor (admin: todo; responsable: su departamento; el resto: su fila).
 */
export default function WorkloadIndex({
    horizon,
    filters,
    matrix,
    people,
    options,
    trays,
    cell,
    my_forecast: myForecast,
    forecast_view,
}: WorkloadPageProps) {
    const [navigating, setNavigating] = useState(false);
    const [loadingKey, setLoadingKey] = useState<string | null>(null);
    const [closing, setClosing] = useState(false);
    const shownCell = closing ? null : cell;
    const openKey = loadingKey ?? shownCell?.key ?? null;
    const rows = matrix.groups.flatMap((group) => group.people);
    // Con la previsión, quien solo ve su fila tiene «Mi carga» por asignaciones (D-305); la
    // matriz por tareas sigue debajo, para sus tareas de las próximas semanas.
    const forecastView = forecast_view === true;

    const visit = (query: WorkloadQuery) =>
        router.visit(index.url({ query }), {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setNavigating(true),
            onFinish: () => setNavigating(false),
            ...failureHandlers(),
        });

    const openCell = (person: WorkloadRow, column: WorkloadColumn) => {
        const key = cellKey(person.id, column);

        setClosing(false);
        setLoadingKey(key);
        router.visit(index.url({ query: { ...filters.query, celda: key } }), {
            only: ['cell'],
            preserveState: true,
            preserveScroll: true,
            onFinish: () =>
                setLoadingKey((current) => (current === key ? null : current)),
            ...failureHandlers(() => setLoadingKey(null)),
        });
    };

    const closeCell = () => {
        // Se cierra al momento; la recarga parcial quita ?celda= de la URL por detrás.
        setClosing(true);
        setLoadingKey(null);
        router.visit(index.url({ query: filters.query }), {
            only: ['cell'],
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setClosing(false),
        });
    };

    const peopleFiltered =
        (filters.query.persona?.length ?? 0) > 0 ||
        (filters.query.departamento?.length ?? 0) > 0;

    // Un sábado o un domingo, «esta semana» ya no tiene días laborables: se dice y se ofrece la que viene.
    const noWorkingDays =
        horizon.key === 'semana-actual' &&
        matrix.columns.length > 0 &&
        matrix.columns.every((column) => column.weekend);

    return (
        <>
            <Head title={t('workload_page.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={
                        forecastView
                            ? t('forecast.my.title')
                            : t(
                                  filters.sees_team
                                      ? 'workload_page.heading_team'
                                      : 'workload_page.heading_own',
                              )
                    }
                    description={
                        forecastView
                            ? t('forecast.my.description')
                            : t(
                                  filters.sees_team
                                      ? 'workload_page.description_team'
                                      : 'workload_page.description_own',
                              )
                    }
                />

                {forecastView ? (
                    <Deferred
                        data="my_forecast"
                        fallback={<MyForecastSkeleton />}
                    >
                        <HatchDefs />
                        <MyForecastView data={myForecast} showHeader={false} />
                    </Deferred>
                ) : null}
                {forecastView ? (
                    <div className="space-y-1 border-t pt-6">
                        <h2 className="text-lg">
                            {t('forecast.my.tasks_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('forecast.my.tasks_description')}
                        </p>
                    </div>
                ) : null}

                <WorkloadToolbar
                    horizon={horizon}
                    filters={filters}
                    options={options}
                    people={people}
                    onChange={visit}
                />

                <section
                    aria-labelledby="workload-matrix-title"
                    className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="workload-matrix-title" className="text-lg">
                            {t('workload_matrix.title')}
                        </h2>
                        {navigating ? (
                            <span
                                className="inline-flex items-center gap-2 text-sm text-muted-foreground"
                                role="status"
                            >
                                <Spinner aria-hidden="true" />
                                {t('workload_page.loading')}
                            </span>
                        ) : null}
                    </div>

                    {rows.length === 0 ? (
                        <EmptyState
                            icon={Users}
                            title={t('workload_page.empty_title')}
                            description={t(
                                peopleFiltered
                                    ? 'workload_page.empty_filtered'
                                    : 'workload_page.empty_description',
                            )}
                        >
                            {peopleFiltered ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        visit({
                                            horizonte: filters.query.horizonte,
                                        })
                                    }
                                >
                                    <X aria-hidden="true" />
                                    {t('workload_filters.clear')}
                                </Button>
                            ) : null}
                        </EmptyState>
                    ) : (
                        <>
                            {filters.sees_team ? (
                                <WorkloadAlerts
                                    rows={rows}
                                    byWeek={horizon.by_week}
                                />
                            ) : null}
                            {noWorkingDays ? (
                                <div
                                    className="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-muted-foreground"
                                    data-test="workload-no-working-days"
                                >
                                    <p className="flex items-start gap-1.5">
                                        <CalendarOff
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0"
                                        />
                                        {t('workload_page.no_working_days')}
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            visit({
                                                ...filters.query,
                                                horizonte: 'semana-que-viene',
                                            })
                                        }
                                    >
                                        <ArrowRight aria-hidden="true" />
                                        {t('workload_page.see_next_week')}
                                    </Button>
                                </div>
                            ) : null}
                            <WorkloadLegend />
                            <WorkloadMatrix
                                matrix={matrix}
                                byWeek={horizon.by_week}
                                from={horizon.from}
                                to={horizon.to}
                                openKey={openKey}
                                loading={navigating}
                                onOpen={openCell}
                            />
                            {matrix.total.planned === 0 && !noWorkingDays ? (
                                <p className="flex items-start gap-1.5 text-sm text-muted-foreground">
                                    <Info
                                        aria-hidden="true"
                                        className="mt-0.5 size-4 shrink-0"
                                    />
                                    {t('workload_page.no_load')}
                                </p>
                            ) : null}
                        </>
                    )}
                </section>

                <WorkloadTrays
                    trays={trays}
                    people={people}
                    seesTeam={filters.sees_team}
                />
            </div>

            <WorkloadCellPanel
                cell={shownCell}
                loading={loadingKey !== null}
                byWeek={horizon.by_week}
                people={people}
                onClose={closeCell}
            />
        </>
    );
}

WorkloadIndex.layout = {
    breadcrumbs: [{ title: t('nav.workload'), href: index() }],
};
