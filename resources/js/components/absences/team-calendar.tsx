import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Clock, Flag } from 'lucide-react';
import {
    ABSENCE_TYPES,
    absenceStatusLabel,
    absenceTypeLabel,
} from '@/components/absences/absence-meta';
import type {
    CalendarAbsence,
    TeamCalendar,
} from '@/components/absences/types';
import {
    weekdayLongLabel,
    weekdayShortLabel,
} from '@/components/time/week-days';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes, LOCALE } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Icono de ejemplo de la leyenda «Ausencia aprobada» (cada celda lleva el de su tipo). */
const LegendApprovedIcon = ABSENCE_TYPES.vacation.icon;

const monthFormatter = new Intl.DateTimeFormat(LOCALE, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

/** "2026-10" → "octubre de 2026". */
export function monthLabel(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return monthFormatter.format(new Date(Date.UTC(year, number - 1, 1)));
}

function isWeekend(date: string): boolean {
    const [year, month, day] = date.split('-').map(Number);
    const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();

    return weekday === 0 || weekday === 6;
}

/** Texto de una ausencia en una celda («Vacaciones (aprobada)», «Permiso, 2:00 h (solicitada)»). */
export function calendarCellLabel(absence: CalendarAbsence): string {
    const replacements = {
        type: absenceTypeLabel(absence.type),
        status: absenceStatusLabel(absence.status).toLowerCase(),
    };

    return absence.partial_minutes !== null
        ? t('absences.calendar.cell_partial', {
              ...replacements,
              minutes: formatMinutes(absence.partial_minutes),
          })
        : t('absences.calendar.cell', replacements);
}

/**
 * Calendario mensual del equipo (D-049): una fila por persona y una columna por día, con las
 * ausencias aprobadas (fondo e icono del tipo), las solicitadas (borde discontinuo) y los
 * festivos. Cada celda con contenido tiene su texto para lectores de pantalla y su título al
 * pasar el ratón. En el móvil la tabla se desliza en horizontal dentro de su propio marco.
 */
export function TeamCalendarView({
    calendar,
    today,
    monthUrl,
}: {
    calendar: TeamCalendar;
    today: string;
    /** URL de otro mes ("2026-11"), con los filtros de la página. */
    monthUrl: (month: string) => string;
}) {
    const holidays = new Map(
        calendar.holidays.map((holiday) => [holiday.date, holiday.name]),
    );
    const cells = new Map<string, CalendarAbsence>();

    for (const absence of calendar.absences) {
        for (const day of calendar.days) {
            if (day < absence.start_date || day > absence.end_date) {
                continue;
            }

            const key = `${absence.user_id}|${day}`;
            const existing = cells.get(key);

            // Si coinciden una aprobada y una solicitada, manda la aprobada.
            if (!existing || existing.status !== 'approved') {
                cells.set(key, absence);
            }
        }
    }

    const label = monthLabel(calendar.month);

    return (
        <section
            aria-labelledby="calendar-heading"
            className="grid min-w-0 gap-3"
            data-test="team-calendar"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="calendar-heading" className="text-lg">
                    {t('absences.calendar.heading')}{' '}
                    <span className="text-muted-foreground">· {label}</span>
                </h2>
                <nav
                    aria-label={t('absences.calendar.heading')}
                    className="flex flex-wrap items-center gap-2"
                >
                    <Button asChild variant="outline" size="sm">
                        <Link
                            href={monthUrl(calendar.previous)}
                            preserveScroll
                            aria-label={`${t('absences.calendar.previous')}: ${monthLabel(calendar.previous)}`}
                        >
                            <ChevronLeft aria-hidden="true" />
                            {t('absences.calendar.previous')}
                        </Link>
                    </Button>
                    {calendar.month !== calendar.current ? (
                        <Button asChild variant="ghost" size="sm">
                            <Link
                                href={monthUrl(calendar.current)}
                                preserveScroll
                            >
                                {t('absences.calendar.today')}
                            </Link>
                        </Button>
                    ) : null}
                    <Button asChild variant="outline" size="sm">
                        <Link
                            href={monthUrl(calendar.next)}
                            preserveScroll
                            aria-label={`${t('absences.calendar.next')}: ${monthLabel(calendar.next)}`}
                        >
                            {t('absences.calendar.next')}
                            <ChevronRight aria-hidden="true" />
                        </Link>
                    </Button>
                </nav>
            </div>

            <ul
                className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                aria-label={t('absences.calendar.legend')}
            >
                <li className="flex items-center gap-1.5">
                    <span className="flex size-5 items-center justify-center rounded-[3px] bg-success-soft">
                        <LegendApprovedIcon
                            aria-hidden="true"
                            className="size-3.5 text-success"
                        />
                    </span>
                    {t('absences.calendar.legend_approved')}
                </li>
                <li className="flex items-center gap-1.5">
                    <span className="flex size-5 items-center justify-center rounded-[3px] border border-dashed border-warning bg-warning-soft">
                        <Clock
                            aria-hidden="true"
                            className="size-3.5 text-warning"
                        />
                    </span>
                    {t('absences.calendar.legend_requested')}
                </li>
                <li className="flex items-center gap-1.5">
                    <span className="flex size-5 items-center justify-center rounded-[3px] bg-neutral-soft">
                        <Flag
                            aria-hidden="true"
                            className="size-3.5 text-muted-foreground"
                        />
                    </span>
                    {t('absences.calendar.legend_holiday')}
                </li>
                <li className="flex items-center gap-1.5">
                    <span className="size-5 rounded-[3px] bg-muted" />
                    {t('absences.calendar.legend_weekend')}
                </li>
            </ul>

            <div
                className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
                role="region"
                aria-label={t('absences.calendar.table_label', {
                    month: label,
                })}
                tabIndex={0}
            >
                <table className="w-max min-w-full border-collapse text-xs">
                    <caption className="sr-only">
                        {t('absences.calendar.table_label', { month: label })}
                    </caption>
                    <thead>
                        <tr className="border-b">
                            <th
                                scope="col"
                                className="sticky left-0 z-10 min-w-28 bg-background px-3 py-2 text-left text-sm font-medium sm:min-w-36"
                            >
                                {t('absences.calendar.person')}
                            </th>
                            {calendar.days.map((day) => {
                                const holiday = holidays.get(day);

                                return (
                                    <th
                                        key={day}
                                        scope="col"
                                        aria-current={
                                            day === today ? 'date' : undefined
                                        }
                                        title={holiday}
                                        className={cn(
                                            'w-8 px-0.5 py-1 text-center font-normal',
                                            isWeekend(day) && 'bg-muted',
                                            holiday && 'bg-neutral-soft',
                                            day === today &&
                                                'text-primary-text underline underline-offset-4',
                                        )}
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="block text-muted-foreground capitalize"
                                        >
                                            {weekdayShortLabel(day).charAt(0)}
                                        </span>
                                        <span
                                            aria-hidden="true"
                                            className="tabular block"
                                        >
                                            {Number(day.slice(8, 10))}
                                        </span>
                                        <span className="sr-only">
                                            {weekdayLongLabel(day)}{' '}
                                            {formatDate(day)}
                                            {holiday
                                                ? `. ${t('absences.calendar.holiday', { name: holiday })}`
                                                : ''}
                                        </span>
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody>
                        {calendar.people.map((person) => (
                            <tr
                                key={person.id}
                                className="border-b last:border-b-0"
                                data-test="calendar-row"
                            >
                                <th
                                    scope="row"
                                    className="sticky left-0 z-10 max-w-36 bg-background px-3 py-1.5 text-left text-sm font-normal sm:max-w-48"
                                >
                                    <span className="flex items-center gap-2">
                                        {person.department ? (
                                            <span
                                                aria-hidden="true"
                                                className="size-2 shrink-0 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        person.department.color,
                                                }}
                                            />
                                        ) : null}
                                        <span className="truncate">
                                            {person.name}
                                        </span>
                                    </span>
                                </th>
                                {calendar.days.map((day) => {
                                    const absence = cells.get(
                                        `${person.id}|${day}`,
                                    );
                                    const holiday = holidays.get(day);

                                    return (
                                        <td
                                            key={day}
                                            className={cn(
                                                'h-9 w-8 p-0.5 text-center',
                                                isWeekend(day) && 'bg-muted',
                                                holiday && 'bg-neutral-soft',
                                            )}
                                        >
                                            <CalendarCell
                                                absence={absence}
                                                holiday={holiday}
                                            />
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {calendar.absences.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('absences.calendar.none_this_month')}
                </p>
            ) : null}
            <p className="text-xs text-muted-foreground md:hidden">
                {t('absences.calendar.scroll_hint')}
            </p>
        </section>
    );
}

function CalendarCell({
    absence,
    holiday,
}: {
    absence: CalendarAbsence | undefined;
    holiday: string | undefined;
}) {
    if (absence) {
        const Icon =
            absence.status === 'approved'
                ? ABSENCE_TYPES[absence.type].icon
                : Clock;
        const text = calendarCellLabel(absence);

        return (
            <span
                title={text}
                data-test="calendar-absence"
                data-status={absence.status}
                className={cn(
                    'flex size-7 items-center justify-center rounded-[3px]',
                    absence.status === 'approved'
                        ? 'bg-success-soft'
                        : 'border border-dashed border-warning bg-warning-soft',
                )}
            >
                <Icon
                    aria-hidden="true"
                    className={cn(
                        'size-3.5',
                        absence.status === 'approved'
                            ? 'text-success'
                            : 'text-warning',
                    )}
                />
                <span className="sr-only">{text}</span>
            </span>
        );
    }

    if (holiday) {
        return (
            <span
                title={t('absences.calendar.holiday', { name: holiday })}
                className="flex size-7 items-center justify-center"
            >
                <Flag
                    aria-hidden="true"
                    className="size-3.5 text-muted-foreground"
                />
                <span className="sr-only">
                    {t('absences.calendar.holiday', { name: holiday })}
                </span>
            </span>
        );
    }

    return null;
}
