import { Deferred, Head, Link } from '@inertiajs/react';
import { useLastDefined } from '@/hooks/use-last-defined';
import { MoreHorizontal, Plus, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { ChartFrame } from '@/components/charts/chart-frame';
import { ChartLegend } from '@/components/charts/chart-legend';
import { AllocationDialog } from '@/components/forecast/allocation-dialog';
import {
    MiniTimeline,
    timelineRange,
    WhoCell,
} from '@/components/forecast/allocations-table';
import { AssignGapDialog } from '@/components/forecast/assign-gap-dialog';
import { BulletBar, DeviationBadge } from '@/components/forecast/deviation';
import { EstimateVsActualSection } from '@/components/forecast/estimate-vs-actual';
import { ForecastStat } from '@/components/forecast/forecast-stat';
import { HatchDefs } from '@/components/forecast/layer-swatch';
import { PlanVsLoggedChart } from '@/components/forecast/plan-vs-logged-chart';
import { KeywordText } from '@/components/keyword-text';
import { ProjectShell } from '@/components/projects/project-shell';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useAbilities } from '@/hooks/use-auth';
import {
    allocationAmountLabel,
    dateRange,
    deviationPercent,
    formatHours,
    plural,
} from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { show as forecastShow } from '@/routes/forecast/projects';
import { planning } from '@/routes/projects';
import type { Allocation, ProjectPlanningPageProps } from '@/types/forecast';

function todayString(): string {
    return new Date().toISOString().slice(0, 10);
}

/**
 * Pestaña «Planificación» de un proyecto real (`/proyectos/{id}/planificacion`, D-296): el aviso de
 * origen si viene de un previsto, las cifras (plan, imputado, desviación hasta hoy y restante), plan
 * frente a imputado por semana y las asignaciones por persona con su barra de bala y su desviación.
 * Quien gestiona el proyecto añade y edita asignaciones. Si viene de un previsto, debajo, «Estimado
 * frente a real» (D-297).
 */
export default function ProjectPlanning({
    project,
    allocations,
    months,
    weeks,
    totals,
    forecast,
    estimate,
    options: optionsProp,
    can,
}: ProjectPlanningPageProps) {
    // Las opciones diferidas siguen disponibles mientras se vuelven a pedir tras guardar: el
    // diálogo de asignación ya no se desmonta con un error de validación (D-310).
    const options = useLastDefined(optionsProp);
    const abilities = useAbilities();
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Allocation | null>(null);
    const [assigning, setAssigning] = useState<Allocation | null>(null);
    const today = todayString();
    const range = timelineRange(months);
    const people = new Set(
        allocations.map((allocation) => allocation.user?.id).filter(Boolean),
    ).size;
    const deviation = deviationPercent(
        totals.planned_to_date_minutes,
        totals.logged_minutes,
    );
    const futureWeeks = weeks
        .filter(
            (week) =>
                week.from > today || (week.from <= today && today <= week.to),
        )
        .filter((week) => week.planned > 0).length;
    const maxBullet = Math.max(
        1,
        ...allocations.map((allocation) =>
            Math.max(
                allocation.logged_minutes ?? 0,
                allocation.planned_to_date_minutes ?? 0,
            ),
        ),
    );
    const departmentName = (allocation: Allocation) =>
        options?.departments.find(
            (department) => department.id === allocation.user?.department_id,
        )?.name ?? null;

    return (
        <>
            <Head title={`${project.code} · ${t('forecast.planning.title')}`} />
            <HatchDefs />
            <ProjectShell
                project={project}
                tab="planificacion"
                canManage={can.manage}
                actions={
                    can.manage ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setAdding(true)}
                            // Hasta que llegan las personas y departamentos no hay diálogo que abrir.
                            disabled={!options}
                            data-test="add-allocation"
                        >
                            <Plus aria-hidden="true" />
                            {t('forecast.allocation.add')}
                        </Button>
                    ) : null
                }
            >
                <div className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-6">
                    {forecast ? (
                        <p
                            className="border-l-2 border-brand bg-card px-3 py-2 text-sm"
                            data-test="planning-origin"
                        >
                            {t('forecast.planning.origin_long', {
                                name: forecast.name,
                            })}{' '}
                            {forecast.can_view ? (
                                <Link
                                    href={forecastShow.url(forecast.id)}
                                    className="text-primary-text hover:underline"
                                >
                                    {t('forecast.planning.see_forecast')}
                                </Link>
                            ) : null}
                            {' · '}
                            <a
                                href="#estimado"
                                className="text-primary-text hover:underline"
                            >
                                {t('forecast.planning.see_estimate')}
                            </a>
                        </p>
                    ) : null}

                    {allocations.length === 0 ? (
                        <section className="grid gap-2 border bg-card p-6">
                            <h2 className="text-base font-medium">
                                {t('forecast.planning.empty_title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('forecast.planning.empty')}
                            </p>
                        </section>
                    ) : (
                        <>
                            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                <ForecastStat
                                    label={t('forecast.planning.total')}
                                    value={formatHours(totals.planned_minutes)}
                                    detail={`${range ? dateRange(range.from, range.to) : ''} · ${plural('forecast.matrix.people_one', 'forecast.matrix.people_other', people)}`}
                                />
                                <ForecastStat
                                    label={t('forecast.planning.logged')}
                                    value={formatHours(totals.logged_minutes)}
                                    detail={
                                        totals.planned_minutes > 0
                                            ? t('forecast.planning.of_plan', {
                                                  percent: `${Math.round((totals.logged_minutes * 100) / totals.planned_minutes)} %`,
                                              })
                                            : null
                                    }
                                />
                                <ForecastStat
                                    label={t('forecast.planning.deviation')}
                                    value={
                                        <DeviationBadge
                                            percent={deviation}
                                            className="text-2xl font-semibold"
                                        />
                                    }
                                    detail={t(
                                        'forecast.planning.deviation_detail',
                                        {
                                            hours: formatHours(
                                                Math.abs(
                                                    totals.logged_minutes -
                                                        totals.planned_to_date_minutes,
                                                ),
                                            ),
                                            direction: t(
                                                totals.logged_minutes >=
                                                    totals.planned_to_date_minutes
                                                    ? 'forecast.planning.more'
                                                    : 'forecast.planning.less',
                                            ),
                                            plan: formatHours(
                                                totals.planned_to_date_minutes,
                                            ),
                                        },
                                    )}
                                />
                                <ForecastStat
                                    label={t('forecast.planning.remaining')}
                                    value={formatHours(
                                        totals.remaining_minutes,
                                    )}
                                    detail={
                                        futureWeeks > 0
                                            ? t(
                                                  'forecast.planning.remaining_detail',
                                                  {
                                                      weeks: plural(
                                                          'forecast.planning.weeks_one',
                                                          'forecast.planning.weeks_other',
                                                          futureWeeks,
                                                      ),
                                                      pace: formatHours(
                                                          totals.remaining_minutes /
                                                              futureWeeks,
                                                      ),
                                                  },
                                              )
                                            : null
                                    }
                                />
                            </div>

                            <section className="border bg-card p-4">
                                <ChartFrame
                                    title={t('forecast.planning.weeks')}
                                    description={t(
                                        'forecast.planning.weeks_help',
                                    )}
                                    summary={t(
                                        'forecast.planning.weeks_summary',
                                        {
                                            planned: formatHours(
                                                totals.planned_minutes,
                                            ),
                                            logged: formatHours(
                                                totals.logged_minutes,
                                            ),
                                        },
                                    )}
                                    legend={
                                        <ChartLegend
                                            items={[
                                                {
                                                    key: 'plan',
                                                    label: t(
                                                        'forecast.planning.series_plan',
                                                    ),
                                                    color: 'var(--chart-1)',
                                                    shape: 'line',
                                                },
                                                {
                                                    key: 'logged',
                                                    label: t(
                                                        'forecast.planning.logged',
                                                    ),
                                                    color: 'var(--chart-2)',
                                                    shape: 'rect',
                                                },
                                                {
                                                    key: 'current',
                                                    label: t(
                                                        'forecast.planning.current_week',
                                                    ),
                                                    color: 'color-mix(in oklab, var(--chart-2) 45%, var(--card))',
                                                    shape: 'rect',
                                                },
                                                {
                                                    key: 'today',
                                                    label: t(
                                                        'forecast.estimate.today',
                                                    ),
                                                    color: 'var(--brand)',
                                                    shape: 'line',
                                                },
                                            ]}
                                        />
                                    }
                                    table={{
                                        columns: [
                                            {
                                                key: 'week',
                                                label: t(
                                                    'forecast.table.period',
                                                ),
                                            },
                                            {
                                                key: 'planned',
                                                label: t(
                                                    'forecast.planning.planned',
                                                ),
                                                numeric: true,
                                            },
                                            {
                                                key: 'logged',
                                                label: t(
                                                    'forecast.planning.logged',
                                                ),
                                                numeric: true,
                                            },
                                            {
                                                key: 'missing',
                                                label: t(
                                                    'forecast.planning.missing',
                                                ),
                                            },
                                        ],
                                        rows: weeks.map((week) => ({
                                            id: week.key,
                                            week: `${week.key.replace('-W', ' · S')} (${dateRange(week.from, week.to)})`,
                                            planned: formatHours(week.planned),
                                            logged: formatHours(week.logged),
                                            missing: week.missing.join(', '),
                                        })),
                                    }}
                                >
                                    <PlanVsLoggedChart
                                        weeks={weeks}
                                        today={today}
                                    />
                                </ChartFrame>
                            </section>

                            <section
                                aria-labelledby="planning-people"
                                className="grid gap-3 border bg-card p-4"
                            >
                                <div>
                                    <h2
                                        id="planning-people"
                                        className="text-base font-medium"
                                    >
                                        {t('forecast.planning.people')}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {t('forecast.planning.people_help')}
                                    </p>
                                </div>
                                <div className="overflow-x-auto">
                                    <table
                                        className="w-full text-sm"
                                        data-test="planning-table"
                                    >
                                        <thead>
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2 text-left"
                                                >
                                                    {t('forecast.show.who')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2 text-left"
                                                >
                                                    {t('forecast.show.how')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="min-w-40 px-3 py-2 text-left"
                                                >
                                                    {t('forecast.show.dates')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2 text-right"
                                                >
                                                    {t(
                                                        'forecast.planning.planned',
                                                    )}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2 text-right"
                                                >
                                                    {t(
                                                        'forecast.planning.to_date',
                                                    )}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2 text-right"
                                                >
                                                    {t(
                                                        'forecast.planning.logged',
                                                    )}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="min-w-40 px-3 py-2 text-left"
                                                >
                                                    {t(
                                                        'forecast.planning.bullet',
                                                    )}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2 text-right"
                                                >
                                                    {t(
                                                        'forecast.show.deviation',
                                                    )}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-3 py-2"
                                                >
                                                    <span className="sr-only">
                                                        {t(
                                                            'forecast.gaps.actions',
                                                        )}
                                                    </span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {allocations.map((allocation) => (
                                                <tr
                                                    key={allocation.id}
                                                    className="border-b"
                                                    data-test="planning-row"
                                                >
                                                    <td className="px-3 py-2.5 align-middle">
                                                        <WhoCell
                                                            allocation={
                                                                allocation
                                                            }
                                                        />
                                                        {allocation.user &&
                                                        departmentName(
                                                            allocation,
                                                        ) ? (
                                                            <p className="text-xs text-muted-foreground">
                                                                {departmentName(
                                                                    allocation,
                                                                )}
                                                            </p>
                                                        ) : null}
                                                    </td>
                                                    <td className="px-3 py-2.5 align-middle whitespace-nowrap">
                                                        {allocationAmountLabel(
                                                            allocation,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5 align-middle">
                                                        {range ? (
                                                            <MiniTimeline
                                                                range={range}
                                                                start={
                                                                    allocation.start_date
                                                                }
                                                                end={
                                                                    allocation.end_date
                                                                }
                                                                layer="real"
                                                                gap={
                                                                    allocation.is_gap
                                                                }
                                                                today={today}
                                                            />
                                                        ) : null}
                                                        <span className="tabular mt-1 block text-xs text-muted-foreground">
                                                            {dateRange(
                                                                allocation.start_date,
                                                                allocation.end_date,
                                                            )}
                                                            {allocation.overdue
                                                                ? ` · ${t('forecast.planning.overdue')}`
                                                                : ''}
                                                        </span>
                                                    </td>
                                                    <td className="tabular px-3 py-2.5 text-right align-middle">
                                                        {formatHours(
                                                            allocation.planned_minutes,
                                                        )}
                                                    </td>
                                                    <td className="tabular px-3 py-2.5 text-right align-middle">
                                                        {formatHours(
                                                            allocation.planned_to_date_minutes ??
                                                                0,
                                                        )}
                                                    </td>
                                                    <td className="tabular px-3 py-2.5 text-right align-middle">
                                                        {allocation.logged_minutes ===
                                                        null
                                                            ? '—'
                                                            : formatHours(
                                                                  allocation.logged_minutes,
                                                              )}
                                                    </td>
                                                    <td className="px-3 py-2.5 align-middle">
                                                        {allocation.logged_minutes !==
                                                        null ? (
                                                            <BulletBar
                                                                actual={
                                                                    allocation.logged_minutes
                                                                }
                                                                target={
                                                                    allocation.planned_to_date_minutes ??
                                                                    0
                                                                }
                                                                max={maxBullet}
                                                            />
                                                        ) : null}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right align-middle">
                                                        {allocation.logged_minutes !==
                                                        null ? (
                                                            <DeviationBadge
                                                                percent={deviationPercent(
                                                                    allocation.planned_to_date_minutes ??
                                                                        0,
                                                                    allocation.logged_minutes,
                                                                )}
                                                            />
                                                        ) : null}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right align-middle whitespace-nowrap">
                                                        {allocation.is_gap &&
                                                        allocation.can
                                                            .assign ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setAssigning(
                                                                        allocation,
                                                                    )
                                                                }
                                                            >
                                                                <UserPlus aria-hidden="true" />
                                                                {t(
                                                                    'forecast.assign.open',
                                                                )}
                                                            </Button>
                                                        ) : null}
                                                        {allocation.can
                                                            .update &&
                                                        can.manage ? (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() =>
                                                                    setEditing(
                                                                        allocation,
                                                                    )
                                                                }
                                                                aria-label={t(
                                                                    'forecast.show.edit_allocation',
                                                                    {
                                                                        who:
                                                                            allocation
                                                                                .user
                                                                                ?.name ??
                                                                            allocation
                                                                                .department
                                                                                ?.name ??
                                                                            '',
                                                                    },
                                                                )}
                                                            >
                                                                <MoreHorizontal aria-hidden="true" />
                                                            </Button>
                                                        ) : null}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <ChartLegend
                                    items={[
                                        {
                                            key: 'logged',
                                            label: t(
                                                'forecast.planning.logged',
                                            ),
                                            color: 'var(--chart-2)',
                                            shape: 'rect',
                                        },
                                        {
                                            key: 'plan',
                                            label: t(
                                                'forecast.planning.to_date',
                                            ),
                                            color: 'var(--chart-1)',
                                            shape: 'line',
                                        },
                                    ]}
                                />
                            </section>
                        </>
                    )}

                    {forecast ? (
                        <section
                            id="estimado"
                            aria-labelledby="estimate-title"
                            className="grid min-w-0 scroll-mt-4 grid-cols-[minmax(0,1fr)] gap-3"
                        >
                            <h2 id="estimate-title" className="text-lg">
                                <KeywordText
                                    text={t('forecast.estimate.title')}
                                />
                            </h2>
                            <Deferred
                                data="estimate"
                                fallback={<Skeleton className="h-64" />}
                            >
                                {estimate ? (
                                    <EstimateVsActualSection
                                        estimate={estimate}
                                        today={today}
                                    />
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {t('forecast.estimate.not_linked')}
                                    </p>
                                )}
                            </Deferred>
                        </section>
                    ) : null}
                </div>
            </ProjectShell>

            {can.manage && options ? (
                <AllocationDialog
                    key={editing?.id ?? 'new'}
                    container={{ kind: 'project', id: project.id }}
                    allocation={editing ?? undefined}
                    people={options.people}
                    departments={options.departments}
                    defaults={{
                        start: project.start_date ?? today,
                        end: project.due_date ?? null,
                    }}
                    open={adding || editing !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setAdding(false);
                            setEditing(null);
                        }
                    }}
                />
            ) : null}
            {assigning ? (
                <AssignGapDialog
                    allocation={assigning}
                    departmentName={assigning.department?.name ?? ''}
                    open
                    onOpenChange={(open) => (open ? null : setAssigning(null))}
                    showLoad={abilities.viewForecast === true}
                    people={options?.people}
                />
            ) : null}
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
