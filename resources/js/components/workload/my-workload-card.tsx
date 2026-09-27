import { Link } from '@inertiajs/react';
import { CalendarClock, CalendarMinus, ListTodo } from 'lucide-react';
import { LoadCell } from '@/components/charts/load-cell';
import type {
    MyWorkloadData,
    MyWorkloadWeek,
} from '@/components/workload/types';
import {
    loadSummary,
    reasonLong,
    reasonShort,
    reducedLong,
} from '@/components/workload/workload-labels';
import { dayMonthLabel } from '@/components/time/week-days';
import { Skeleton } from '@/components/ui/skeleton';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as workloadIndex } from '@/routes/workload';

function extraReason(week: MyWorkloadWeek): string | null {
    if (week.reason) {
        const long = reasonLong(week.reason);

        return long === reasonShort(week.reason) ? null : long;
    }

    return week.reduced ? reducedLong(week.reduced) : null;
}

/** Mientras llega la prop diferida `workload` de Inicio. */
export function MyWorkloadSkeleton() {
    return (
        <div
            className="grid gap-2"
            role="status"
            aria-label={t('workload_home.loading')}
        >
            <Skeleton className="h-11 rounded-[3px]" />
            <Skeleton className="h-11 rounded-[3px]" />
        </div>
    );
}

/**
 * «Mi carga» en Inicio (SPEC §5.1 y §9): esta semana (de hoy al domingo) y la que viene,
 * planificado frente a capacidad con el semáforo, el motivo si hay menos capacidad (festivos,
 * ausencias) y cuántas de mis tareas están vencidas o sin planificar. Solo lo mío (D-021).
 */
export function MyWorkload({ workload }: { workload?: MyWorkloadData }) {
    if (!workload) {
        return <MyWorkloadSkeleton />;
    }

    return (
        <div className="grid gap-3" data-test="my-workload">
            <dl className="grid gap-2">
                {workload.weeks.map((week) => (
                    <div
                        key={week.key}
                        className="grid grid-cols-[minmax(0,1fr)_7.5rem] items-center gap-2"
                    >
                        <dt className="min-w-0 text-sm">
                            <span className="block">
                                {t(
                                    week.key === 'semana-actual'
                                        ? 'workload_home.this_week'
                                        : 'workload_home.next_week',
                                )}
                            </span>
                            <span className="tabular block text-xs text-muted-foreground">
                                {week.from === week.to
                                    ? t('workload_home.only_day', {
                                          date: dayMonthLabel(week.from),
                                      })
                                    : t('workload_home.range', {
                                          from: dayMonthLabel(week.from),
                                          to: dayMonthLabel(week.to),
                                      })}
                            </span>
                            {/* El motivo corto ya va en la celda: aquí, lo que añade información. */}
                            {extraReason(week) ? (
                                <span className="flex items-start gap-1 text-xs text-muted-foreground">
                                    <CalendarMinus
                                        aria-hidden="true"
                                        className="mt-0.5 size-3 shrink-0"
                                    />
                                    {extraReason(week)}
                                </span>
                            ) : null}
                        </dt>
                        <dd>
                            <span className="sr-only">
                                {[
                                    loadSummary(week),
                                    week.reason ? reasonLong(week.reason) : '',
                                ]
                                    .filter(Boolean)
                                    .join('. ')}
                            </span>
                            <div aria-hidden="true">
                                <LoadCell
                                    planned={week.planned}
                                    capacity={week.capacity}
                                    reason={
                                        week.reason
                                            ? reasonShort(week.reason)
                                            : undefined
                                    }
                                />
                            </div>
                        </dd>
                    </div>
                ))}
            </dl>

            {workload.overdue > 0 || workload.unplanned > 0 ? (
                <ul className="grid gap-1 text-sm">
                    {workload.overdue > 0 ? (
                        <li className="flex items-center gap-1.5">
                            <CalendarClock
                                aria-hidden="true"
                                className="size-4 shrink-0 text-danger"
                            />
                            {t('workload_home.overdue', {
                                count: workload.overdue,
                            })}
                        </li>
                    ) : null}
                    {workload.unplanned > 0 ? (
                        <li className="flex items-center gap-1.5">
                            <ListTodo
                                aria-hidden="true"
                                className="size-4 shrink-0 text-warning"
                            />
                            {t('workload_home.unplanned', {
                                count: workload.unplanned,
                            })}
                        </li>
                    ) : null}
                </ul>
            ) : null}

            <Link
                href={workloadIndex()}
                className={cn(
                    'self-start rounded-sm text-sm text-primary-text hover:underline',
                    FOCUS_RING,
                )}
            >
                {t('workload_home.open')}
            </Link>
        </div>
    );
}
