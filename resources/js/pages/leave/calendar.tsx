import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Plus,
    Settings2,
    Trash2,
} from 'lucide-react';
import { useId } from 'react';
import { AbsencesFrame } from '@/components/absences/absences-frame';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { inRange, isoWeekday, monthDays } from '@/lib/leave';
import { cn } from '@/lib/utils';
import { index as mineIndex } from '@/routes/absences';
import {
    destroy as destroyDay,
    index as calendarIndex,
    store as storeDay,
} from '@/routes/absences/calendar';
import { index as holidaysIndex } from '@/routes/admin/holidays';
import type { CalendarDayKind, LeaveCalendarPageProps } from '@/types/leave';

type DayInfo = {
    holiday: LeaveCalendarPageProps['holidays'][number] | null;
    special: LeaveCalendarPageProps['special_days'][number] | null;
    blocked: LeaveCalendarPageProps['special_days'][number] | null;
    absence: LeaveCalendarPageProps['absences'][number] | null;
};

/** Clases de cada marca del calendario: color con su texto en la leyenda y en el nombre del día. */
const MARKS = {
    holiday: 'bg-danger-soft',
    half_day: 'bg-warning-soft',
    blocked: 'bg-neutral-soft line-through decoration-muted-foreground',
    approved: 'bg-success-soft',
    requested: 'bg-info-soft',
} as const;

function dayInfo(date: string, props: LeaveCalendarPageProps): DayInfo {
    return {
        holiday:
            props.holidays.find((holiday) => holiday.date === date) ?? null,
        special:
            props.special_days.find(
                (day) =>
                    day.kind === 'half_day' &&
                    inRange(date, day.start_date, day.end_date),
            ) ?? null,
        blocked:
            props.special_days.find(
                (day) =>
                    day.kind === 'blocked' &&
                    inRange(date, day.start_date, day.end_date),
            ) ?? null,
        absence:
            props.absences.find((absence) =>
                inRange(date, absence.start_date, absence.end_date),
            ) ?? null,
    };
}

function dayLabel(date: string, info: DayInfo): string {
    const parts = [formatDate(date)];

    if (info.holiday) {
        parts.push(
            t('leave.calendar.holiday_label', {
                name: info.holiday.level
                    ? `${info.holiday.name} (${t(`leave.levels.${info.holiday.level}` as TranslationKey).toLowerCase()})`
                    : info.holiday.name,
            }),
        );
    }
    if (info.special) {
        parts.push(
            t('leave.calendar.half_day_label', { name: info.special.name }),
        );
    }
    if (info.blocked) {
        parts.push(
            t('leave.calendar.blocked_label', { name: info.blocked.name }),
        );
    }
    if (info.absence) {
        parts.push(
            t(
                info.absence.status === 'approved'
                    ? 'leave.calendar.absence_approved'
                    : 'leave.calendar.absence_requested',
                {
                    name: info.absence.name,
                },
            ),
        );
    }

    return parts.join(' · ');
}

function Month({
    year,
    month,
    props,
}: {
    year: number;
    month: number;
    props: LeaveCalendarPageProps;
}) {
    const days = monthDays(year, month);
    const offset = isoWeekday(days[0]) - 1;
    const title = new Intl.DateTimeFormat('es-ES', {
        month: 'long',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, 1)));
    const cells: (string | null)[] = [
        ...Array<null>(offset).fill(null),
        ...days,
    ];
    const weeks: (string | null)[][] = [];
    for (let index = 0; index < cells.length; index += 7) {
        weeks.push([
            ...cells.slice(index, index + 7),
            ...Array<null>(Math.max(0, index + 7 - cells.length)).fill(null),
        ]);
    }

    return (
        <section
            className="rounded-md border p-3"
            aria-label={`${title} ${year}`}
        >
            <h3 className="mb-2 text-sm capitalize">{title}</h3>
            <table className="w-full table-fixed text-center text-xs">
                <thead>
                    <tr className="text-muted-foreground">
                        {['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].map(
                            (day) => (
                                <th
                                    key={day}
                                    scope="col"
                                    className="pb-1 font-normal"
                                >
                                    <abbr
                                        title={t(
                                            `leave.calendar.weekdays.${day}` as TranslationKey,
                                        )}
                                    >
                                        {t(
                                            `leave.calendar.weekdays_short.${day}` as TranslationKey,
                                        )}
                                    </abbr>
                                </th>
                            ),
                        )}
                    </tr>
                </thead>
                <tbody>
                    {weeks.map((week, index) => (
                        <tr key={index}>
                            {week.map((date, column) => {
                                if (date === null) {
                                    return <td key={column} />;
                                }

                                const info = dayInfo(date, props);
                                const weekend = column >= 5;
                                const mark = info.holiday
                                    ? MARKS.holiday
                                    : info.absence
                                      ? MARKS[info.absence.status]
                                      : info.special
                                        ? MARKS.half_day
                                        : info.blocked
                                          ? MARKS.blocked
                                          : null;

                                return (
                                    <td key={date} className="p-0.5">
                                        <span
                                            title={dayLabel(date, info)}
                                            aria-label={dayLabel(date, info)}
                                            className={cn(
                                                'tabular flex aspect-square items-center justify-center rounded-sm',
                                                weekend &&
                                                    !mark &&
                                                    'text-muted-foreground',
                                                mark,
                                                date === props.today &&
                                                    'ring-2 ring-ring',
                                            )}
                                            data-test={
                                                info.holiday
                                                    ? 'calendar-holiday'
                                                    : undefined
                                            }
                                        >
                                            {Number(date.slice(8))}
                                        </span>
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </section>
    );
}

function SpecialDayForm({ year }: { year: number }) {
    const id = useId();
    const form = useForm<{
        kind: CalendarDayKind;
        name: string;
        start_date: string;
        end_date: string;
    }>({
        kind: 'blocked',
        name: '',
        start_date: `${year}-12-24`,
        end_date: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            noValidate
            className="grid gap-3 rounded-md border p-4 sm:grid-cols-[repeat(4,minmax(0,1fr))_auto] sm:items-end"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storeDay.url(), {
                    preserveScroll: true,
                    onSuccess: () => form.reset('name', 'end_date'),
                });
            }}
            data-test="special-day-form"
        >
            <Field
                id={`${id}-kind`}
                label={t('leave.calendar.form.kind')}
                error={errors.kind}
            >
                <NativeSelect
                    id={`${id}-kind`}
                    value={form.data.kind}
                    onChange={(event) =>
                        form.setData(
                            'kind',
                            event.target.value as CalendarDayKind,
                        )
                    }
                >
                    <option value="blocked">
                        {t('leave.calendar.kinds.blocked')}
                    </option>
                    <option value="half_day">
                        {t('leave.calendar.kinds.half_day')}
                    </option>
                </NativeSelect>
            </Field>
            <Field
                id={`${id}-name`}
                label={t('leave.calendar.form.name')}
                error={errors.name}
            >
                <Input
                    id={`${id}-name`}
                    value={form.data.name}
                    maxLength={150}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={errors.name ? true : undefined}
                    aria-describedby={describedBy(`${id}-name`, {
                        error: errors.name,
                    })}
                />
            </Field>
            <Field
                id={`${id}-start`}
                label={t('leave.calendar.form.start')}
                error={errors.start_date}
            >
                <Input
                    id={`${id}-start`}
                    type="date"
                    value={form.data.start_date}
                    onChange={(event) =>
                        form.setData('start_date', event.target.value)
                    }
                />
            </Field>
            <Field
                id={`${id}-end`}
                label={t('leave.calendar.form.end')}
                optional={t('absences.form.notes_optional')}
                error={errors.end_date}
            >
                <Input
                    id={`${id}-end`}
                    type="date"
                    value={form.data.end_date}
                    onChange={(event) =>
                        form.setData('end_date', event.target.value)
                    }
                />
            </Field>
            <Button
                type="submit"
                disabled={form.processing || form.data.name.trim() === ''}
            >
                {form.processing ? <Spinner /> : <Plus aria-hidden="true" />}
                {t('leave.calendar.form.add')}
            </Button>
        </form>
    );
}

/**
 * «Calendario laboral» (`/ausencias/calendario`, Fase 11, R3; W-038, W-039 y W-076; L-23; D-366 y
 * D-367): el año con los festivos (nacionales, autonómicos, locales y de empresa, con su fuente),
 * los días de media jornada y los bloqueados para las vacaciones, y las ausencias de quien mira. La
 * empresa lo tiene a la vista de la plantilla (art. 34.6 ET). RR. HH. gestiona los días especiales.
 */
export default function LeaveCalendar(props: LeaveCalendarPageProps) {
    const { year } = props;
    const visit = (next: number) =>
        router.get(
            calendarIndex.url({
                query: next === props.current_year ? {} : { anio: next },
            }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={t('leave.calendar.title', { year })} />
            <AbsencesFrame
                section="calendar"
                canTeam={false}
                title={t('leave.calendar.heading', { year })}
                description={t('leave.calendar.description', {
                    center: props.work_center,
                })}
                actions={
                    props.can.holidays ? (
                        <Button asChild variant="outline">
                            <Link
                                href={holidaysIndex.url({
                                    query: { anio: year },
                                })}
                            >
                                <Settings2 aria-hidden="true" />
                                {t('leave.calendar.manage_holidays')}
                            </Link>
                        </Button>
                    ) : null
                }
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div
                        className="flex items-center gap-1"
                        role="group"
                        aria-label={t('leave.balances.year')}
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label={t('leave.balances.previous_year')}
                            onClick={() => visit(year - 1)}
                        >
                            <ChevronLeft aria-hidden="true" />
                        </Button>
                        <span className="tabular min-w-16 text-center text-lg">
                            {year}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label={t('leave.balances.next_year')}
                            onClick={() => visit(year + 1)}
                        >
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    </div>
                    <ul
                        className="flex flex-wrap gap-x-4 gap-y-1 text-sm"
                        aria-label={t('leave.calendar.legend')}
                    >
                        {(
                            [
                                'holiday',
                                'half_day',
                                'blocked',
                                'approved',
                                'requested',
                            ] as const
                        ).map((mark) => (
                            <li
                                key={mark}
                                className="flex items-center gap-1.5"
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'inline-block size-3.5 rounded-sm border',
                                        MARKS[mark],
                                    )}
                                />
                                {t(
                                    `leave.calendar.marks.${mark}` as TranslationKey,
                                )}
                            </li>
                        ))}
                    </ul>
                </div>

                <div
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
                    data-test="leave-calendar"
                >
                    {Array.from({ length: 12 }, (_, index) => (
                        <Month
                            key={index}
                            year={year}
                            month={index + 1}
                            props={props}
                        />
                    ))}
                </div>

                <section
                    aria-labelledby="leave-holidays-heading"
                    className="grid gap-2"
                >
                    <h2 id="leave-holidays-heading" className="text-lg">
                        {t('leave.calendar.holidays', {
                            count: props.holidays.length,
                            year,
                        })}
                    </h2>
                    {props.holidays.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('leave.calendar.no_holidays')}
                        </p>
                    ) : (
                        <ul
                            className="grid gap-1 text-sm"
                            data-test="leave-holiday-list"
                        >
                            {props.holidays.map((holiday) => (
                                <li
                                    key={holiday.date}
                                    className="grid gap-x-3 sm:grid-cols-[7rem_1fr_auto]"
                                >
                                    <span className="tabular">
                                        {formatDate(holiday.date)}
                                    </span>
                                    <span>
                                        {holiday.name}
                                        {holiday.level ? (
                                            <span className="text-muted-foreground">
                                                {' · '}
                                                {t(
                                                    `leave.levels.${holiday.level}` as TranslationKey,
                                                )}
                                            </span>
                                        ) : null}
                                    </span>
                                    {holiday.source ? (
                                        <span className="text-xs text-muted-foreground sm:text-right">
                                            {holiday.source}
                                        </span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section
                    aria-labelledby="leave-special-heading"
                    className="grid gap-2"
                >
                    <h2 id="leave-special-heading" className="text-lg">
                        {t('leave.calendar.special_days')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('leave.calendar.special_description')}
                    </p>
                    {props.special_days.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('leave.calendar.no_special_days')}
                        </p>
                    ) : (
                        <ul className="grid gap-1 text-sm">
                            {props.special_days.map((day) => (
                                <li
                                    key={day.id}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-1"
                                >
                                    <span className="tabular">
                                        {day.start_date === day.end_date
                                            ? formatDate(day.start_date)
                                            : `${formatDate(day.start_date)} – ${formatDate(day.end_date)}`}
                                    </span>
                                    <span>{day.name}</span>
                                    <span className="text-muted-foreground">
                                        ·{' '}
                                        {t(
                                            `leave.calendar.kinds.${day.kind}` as TranslationKey,
                                        )}
                                    </span>
                                    {props.can.manage ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-label={t(
                                                'leave.calendar.delete_label',
                                                { name: day.name },
                                            )}
                                            onClick={() =>
                                                router.delete(
                                                    destroyDay.url(day.id),
                                                    {
                                                        preserveScroll: true,
                                                        onError:
                                                            toastVisitErrors,
                                                    },
                                                )
                                            }
                                        >
                                            <Trash2 aria-hidden="true" />
                                            {t('leave.calendar.delete')}
                                        </Button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                    {props.can.manage ? <SpecialDayForm year={year} /> : null}
                </section>
            </AbsencesFrame>
        </>
    );
}

LeaveCalendar.layout = {
    breadcrumbs: [
        { title: t('absences.mine.title'), href: mineIndex() },
        { title: t('leave.calendar.breadcrumb'), href: calendarIndex() },
    ],
};
