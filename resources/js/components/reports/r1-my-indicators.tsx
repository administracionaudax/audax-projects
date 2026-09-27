import { Link } from '@inertiajs/react';
import { Gauge, Info } from 'lucide-react';
import { CHART_COLORS } from '@/components/charts/chart-config';
import { EmptyState } from '@/components/empty-state';
import type { MyIndicators } from '@/components/reports/r1-types';
import { reportUrls } from '@/components/reports/r1-urls';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type ShareRow = { id: string; name: string; minutes: number };

/** Las filas del top y, si queda algo, «Otras» con el resto de las horas. */
export function shareRows(
    rows: { key: string | null; name: string; logged_minutes: number }[],
    total: number,
): ShareRow[] {
    const shares = rows.map((row) => ({
        id: row.key ?? 'none',
        name: row.name,
        minutes: row.logged_minutes,
    }));
    const rest = total - shares.reduce((sum, row) => sum + row.minutes, 0);

    if (rest > 0) {
        shares.push({
            id: 'rest',
            name: t('reports_r1.home.rest'),
            minutes: rest,
        });
    }

    return shares;
}

function Stat({
    label,
    definition,
    value,
    detail,
}: {
    label: string;
    definition: string;
    value: string;
    detail?: string;
}) {
    return (
        <div className="grid content-start gap-0.5">
            <dt className="flex items-center gap-1 text-xs text-muted-foreground">
                {label}
                <Tooltip>
                    <TooltipTrigger
                        type="button"
                        aria-label={t('reports.kpi.definition', { label })}
                        className={cn(
                            'rounded-[3px] p-0.5 hover:text-foreground',
                            FOCUS_RING,
                        )}
                    >
                        <Info aria-hidden="true" className="size-3.5" />
                    </TooltipTrigger>
                    <TooltipContent className="max-w-72">
                        {definition}
                    </TooltipContent>
                </Tooltip>
            </dt>
            <dd className="tabular text-xl">{value}</dd>
            {detail ? (
                <dd className="text-xs text-muted-foreground">{detail}</dd>
            ) : null}
        </div>
    );
}

function ShareList({
    title,
    rows,
    total,
}: {
    title: string;
    rows: ShareRow[];
    total: number;
}) {
    return (
        <div className="grid content-start gap-2">
            <h3 className="text-sm font-medium">{title}</h3>
            <ul className="grid gap-2">
                {rows.map((row) => {
                    const share = total > 0 ? row.minutes / total : 0;

                    return (
                        <li key={row.id} className="grid gap-1 text-sm">
                            <span className="flex items-baseline justify-between gap-2">
                                <span
                                    className={cn(
                                        'min-w-0 truncate',
                                        row.id === 'rest' &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {row.name}
                                </span>
                                <span className="tabular shrink-0 text-xs text-muted-foreground">
                                    {formatMinutes(row.minutes)} ·{' '}
                                    {formatPercent(share, 0)}
                                </span>
                            </span>
                            <span
                                aria-hidden="true"
                                className="h-1.5 w-full rounded-[3px] bg-neutral-soft"
                            >
                                <span
                                    className="block h-full rounded-[3px]"
                                    style={{
                                        width: `${Math.min(share, 1) * 100}%`,
                                        backgroundColor: CHART_COLORS[0],
                                    }}
                                />
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * «Mis indicadores» de Inicio (SPEC §5.1): del mes en curso y solo lo mío. Ocupación (contra
 * mi capacidad del mes, SPEC §10; con la transcurrida hasta ayer como dato), facturabilidad y
 * precisión de estimación (con su definición) y el reparto de mis horas por cliente y proyecto,
 * con enlace a mi informe personal.
 */
export function R1MyIndicators({
    indicators,
    userId,
}: {
    indicators: MyIndicators;
    userId: number;
}) {
    const { estimation } = indicators;
    const noData = indicators.logged_minutes === 0 && estimation.tasks === 0;

    return (
        <div className="grid gap-4" data-test="r1-my-indicators">
            <p className="text-xs text-muted-foreground">
                {t('reports_r1.home.period', {
                    from: formatDate(indicators.from),
                    to: formatDate(indicators.to),
                })}
            </p>

            {noData ? (
                <EmptyState
                    icon={Gauge}
                    title={t('reports_r1.home.empty')}
                    description={t('reports_r1.home.empty_description')}
                />
            ) : (
                <>
                    <dl className="grid grid-cols-1 gap-3 min-[420px]:grid-cols-3">
                        <Stat
                            label={t('reports.metric.occupancy.label')}
                            definition={t(
                                'reports.metric.occupancy.definition',
                            )}
                            value={
                                indicators.occupancy === null
                                    ? '—'
                                    : formatPercent(indicators.occupancy)
                            }
                            detail={
                                indicators.capacity_to_date_minutes <
                                indicators.capacity_minutes
                                    ? t('reports_r1.home.of_capacity_to_date', {
                                          logged: formatMinutes(
                                              indicators.logged_minutes,
                                          ),
                                          capacity: formatMinutes(
                                              indicators.capacity_minutes,
                                          ),
                                          to_date: formatMinutes(
                                              indicators.capacity_to_date_minutes,
                                          ),
                                      })
                                    : t('reports_r1.home.of_capacity', {
                                          logged: formatMinutes(
                                              indicators.logged_minutes,
                                          ),
                                          capacity: formatMinutes(
                                              indicators.capacity_minutes,
                                          ),
                                      })
                            }
                        />
                        <Stat
                            label={t('reports.metric.billability.label')}
                            definition={t(
                                'reports.metric.billability.definition',
                            )}
                            value={
                                indicators.billability === null
                                    ? '—'
                                    : formatPercent(indicators.billability)
                            }
                            detail={t('reports_r1.home.billable', {
                                minutes: formatMinutes(
                                    indicators.billable_minutes,
                                ),
                            })}
                        />
                        <Stat
                            label={t('reports.metric.estimation.label')}
                            definition={t(
                                'reports.metric.estimation.definition',
                            )}
                            value={
                                estimation.accuracy === null
                                    ? '—'
                                    : formatPercent(estimation.accuracy)
                            }
                            detail={
                                estimation.tasks === 0
                                    ? t('reports_r1.kpi.estimation_none')
                                    : t('reports_r1.home.estimation', {
                                          tasks: estimation.tasks,
                                      })
                            }
                        />
                    </dl>

                    {indicators.logged_minutes > 0 ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ShareList
                                title={t('reports_r1.home.by_client')}
                                rows={shareRows(
                                    indicators.clients,
                                    indicators.logged_minutes,
                                )}
                                total={indicators.logged_minutes}
                            />
                            <ShareList
                                title={t('reports_r1.home.by_project')}
                                rows={shareRows(
                                    indicators.projects,
                                    indicators.logged_minutes,
                                )}
                                total={indicators.logged_minutes}
                            />
                        </div>
                    ) : null}
                </>
            )}

            <Link
                href={reportUrls.person(userId)}
                className={cn(
                    'self-start rounded-sm text-sm text-primary-text hover:underline',
                    FOCUS_RING,
                )}
            >
                {t('reports_r1.home.open_report')}
            </Link>
        </div>
    );
}
