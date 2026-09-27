import { Link } from '@inertiajs/react';
import { CalendarX, CircleCheck } from 'lucide-react';
import { useState } from 'react';
import { weekdayLongLabel } from '@/components/time/week-days';
import { EmptyState } from '@/components/empty-state';
import type { R1UnloggedDay } from '@/components/reports/r1-types';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Días que se ven antes de «Ver todos». */
export const UNLOGGED_VISIBLE = 10;

/**
 * Días sin imputar de una persona (SPEC §10.5): con jornada y sin ninguna hora, hasta ayer. Cada
 * día abre la hoja semanal de esa semana (la propia o la de la persona, para quien la supervisa).
 */
export function R1UnloggedDays({
    days,
    timesheetHref,
}: {
    days: R1UnloggedDay[];
    timesheetHref: (day: R1UnloggedDay) => string;
}) {
    const [expanded, setExpanded] = useState(false);

    if (days.length === 0) {
        return (
            <EmptyState
                icon={CircleCheck}
                title={t('reports_r1.unlogged.none')}
            />
        );
    }

    const visible = expanded ? days : days.slice(0, UNLOGGED_VISIBLE);

    return (
        <div className="grid gap-3">
            <p className="inline-flex items-center gap-2 text-sm">
                <CalendarX aria-hidden="true" className="size-4 text-warning" />
                {t('reports_r1.unlogged.count', {
                    count: days.length,
                    minutes: formatMinutes(
                        days.reduce(
                            (sum, day) => sum + day.capacity_minutes,
                            0,
                        ),
                    ),
                })}
            </p>
            <ul
                className="grid gap-1 text-sm sm:grid-cols-2"
                data-test="r1-unlogged"
            >
                {visible.map((day) => (
                    <li
                        key={day.date}
                        className="flex items-center justify-between gap-2 rounded-md border px-3 py-1.5"
                    >
                        <Link
                            href={timesheetHref(day)}
                            className={cn(
                                'rounded-sm hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            <span className="capitalize">
                                {weekdayLongLabel(day.date)}
                            </span>{' '}
                            {formatDate(day.date)}
                        </Link>
                        <span className="tabular text-xs text-muted-foreground">
                            {t('reports_r1.unlogged.capacity', {
                                minutes: formatMinutes(day.capacity_minutes),
                            })}
                        </span>
                    </li>
                ))}
            </ul>
            {days.length > UNLOGGED_VISIBLE ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="justify-self-start"
                    aria-expanded={expanded}
                    onClick={() => setExpanded((value) => !value)}
                >
                    {expanded
                        ? t('reports_r1.unlogged.show_less')
                        : t('reports_r1.unlogged.show_all', {
                              count: days.length,
                          })}
                </Button>
            ) : null}
        </div>
    );
}
