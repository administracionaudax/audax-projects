import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarClock, ChevronLeft, ChevronRight } from 'lucide-react';
import { CorrectionCard } from '@/components/people/correction-card';
import { DayDetailSheet } from '@/components/people/day-detail';
import {
    DayStatusBadge,
    Figure,
    IncidentList,
    PeopleFrame,
} from '@/components/people/people-ui';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { formatMinutes } from '@/lib/format';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import {
    formatDifference,
    modeLabel,
    monthLabel,
    segmentsLabel,
    shiftMonth,
    shortDayLabel,
    tCount,
} from '@/lib/people';
import { cn } from '@/lib/utils';
import { show as teamShow } from '@/routes/people/team';
import { index as workdayIndex } from '@/routes/people/workday';
import type { WorkdayDay, WorkdayPageProps } from '@/types/people';

/** Lo previsto del día: la jornada teórica o por qué no la hay. */
function expectedLabel(day: WorkdayDay): string {
    if (!day.registered) {
        return t('people.workday.not_registered');
    }

    if (!day.employed) {
        return t('people.workday.not_employed');
    }

    if (day.holiday) {
        return t('people.workday.holiday', { name: day.holiday });
    }

    if (day.absence && day.absence.partial_minutes === null) {
        return t('people.workday.absence');
    }

    if (day.expected_minutes === 0) {
        return t('people.workday.rest_day');
    }

    return formatMinutes(day.expected_minutes);
}

/**
 * «Mi jornada» (`/personas/jornada`) y la jornada de una persona del equipo
 * (`/personas/equipo/{persona}`), PLAN-FASE-11 §6.1 y §6.2 (D-341): como «Mi presencia» de Woffu,
 * el diario del mes con lo previsto, los tramos, la comida, lo trabajado, la diferencia, el modo y
 * el estado de cada día; arriba, hoy, la semana y el mes. Cada día se abre en un panel con su
 * historial y sus correcciones.
 */
export default function WorkdayPage(props: WorkdayPageProps) {
    const { subject, month, today, days, totals, week, today_day, detail } =
        props;
    const page = usePage();
    const { people } = page.props;
    const currentMonth = today.slice(0, 7);
    const title = subject.is_me
        ? t('people.workday.title')
        : t('people.workday.title_of', { name: subject.name });

    const baseUrl = (query: Record<string, string>) =>
        subject.is_me
            ? workdayIndex.url({ query })
            : teamShow.url(subject.id, { query });

    const openDay = (date: string) =>
        router.get(
            baseUrl({ mes: month, dia: date }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['detail'],
            },
        );

    const closeDay = () =>
        router.get(
            baseUrl(month === currentMonth ? {} : { mes: month }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['detail'],
            },
        );

    const listed = [...days].reverse().filter((day) => day.date <= today);
    const hasRecords = days.some((day) => day.workdays.length > 0);

    return (
        <>
            <Head title={title} />
            <PeopleFrame
                section={subject.is_me ? 'workday' : 'person'}
                title={title}
                description={
                    subject.is_me
                        ? t('people.workday.description')
                        : t('people.workday.description_of', {
                              name: subject.name,
                          })
                }
            >
                {subject.is_me && people && people.clock === null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('people.workday.not_subject')}
                    </p>
                ) : null}

                {props.awaiting_me.length > 0 ? (
                    <section
                        aria-labelledby="awaiting-heading"
                        className="grid gap-3 rounded-md border border-info p-4"
                        data-test="awaiting-me"
                    >
                        <div className="space-y-1">
                            <h2 id="awaiting-heading" className="text-lg">
                                {tCount(
                                    'people.workday.awaiting_title',
                                    props.awaiting_me.length,
                                )}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('people.workday.awaiting_hint')}
                            </p>
                        </div>
                        {props.awaiting_me.map((correction) => (
                            <CorrectionCard
                                key={correction.id}
                                correction={correction}
                                showPerson
                            />
                        ))}
                    </section>
                ) : null}

                <section
                    aria-label={t('people.workday.month')}
                    className="grid gap-4 md:grid-cols-3"
                >
                    <SummaryCard
                        title={t('people.workday.today')}
                        worked={today_day?.worked_minutes ?? 0}
                        expected={today_day?.expected_minutes ?? 0}
                        difference={null}
                        extra={
                            today_day ? (
                                <DayStatusBadge
                                    status={today_day.status}
                                    className="justify-self-start"
                                />
                            ) : null
                        }
                        testId="summary-today"
                    />
                    <SummaryCard
                        title={t('people.workday.week')}
                        worked={week.worked_minutes}
                        expected={week.expected_minutes}
                        difference={week.difference_minutes}
                        testId="summary-week"
                    />
                    <SummaryCard
                        title={t('people.workday.month')}
                        worked={totals.worked_minutes}
                        expected={totals.expected_minutes}
                        difference={totals.difference_minutes}
                        extra={
                            <span className="text-xs text-muted-foreground">
                                {tCount(
                                    'people.workday.incident_days',
                                    totals.incident_days,
                                )}
                                {totals.excess_minutes > 0
                                    ? ` · ${t('people.workday.excess')} ${formatMinutes(totals.excess_minutes)}`
                                    : ''}
                            </span>
                        }
                        testId="summary-month"
                    />
                </section>

                <section aria-labelledby="diary-heading" className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2
                            id="diary-heading"
                            className="text-lg first-letter:uppercase"
                        >
                            {monthLabel(month)}
                        </h2>
                        <nav
                            aria-label={t('people.workday.month_nav')}
                            className="flex items-center gap-1"
                        >
                            <Button
                                asChild
                                variant="outline"
                                size="icon"
                                aria-label={t('people.workday.previous_month')}
                            >
                                <Link
                                    href={baseUrl({
                                        mes: shiftMonth(month, -1),
                                    })}
                                    preserveScroll
                                >
                                    <ChevronLeft aria-hidden="true" />
                                </Link>
                            </Button>
                            <Button
                                asChild
                                variant="outline"
                                aria-current={
                                    month === currentMonth ? 'date' : undefined
                                }
                            >
                                <Link href={baseUrl({})}>
                                    {t('people.workday.this_month')}
                                </Link>
                            </Button>
                            {month < currentMonth ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    size="icon"
                                    aria-label={t('people.workday.next_month')}
                                >
                                    <Link
                                        href={baseUrl({
                                            mes: shiftMonth(month, 1),
                                        })}
                                        preserveScroll
                                    >
                                        <ChevronRight aria-hidden="true" />
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    size="icon"
                                    disabled
                                    aria-label={t('people.workday.next_month')}
                                >
                                    <ChevronRight aria-hidden="true" />
                                </Button>
                            )}
                        </nav>
                    </div>

                    {!hasRecords && month === currentMonth ? (
                        <EmptyState
                            icon={CalendarClock}
                            title={t('people.workday.empty')}
                            description={
                                subject.is_me
                                    ? t('people.workday.empty_description')
                                    : undefined
                            }
                        />
                    ) : null}

                    <DiaryTable days={listed} onOpen={openDay} />
                    <DiaryCards days={listed} onOpen={openDay} />
                </section>
            </PeopleFrame>

            <DayDetailSheet
                detail={detail}
                subject={subject}
                open={detail !== null}
                onClose={closeDay}
            />
        </>
    );
}

function SummaryCard({
    title,
    worked,
    expected,
    difference,
    extra,
    testId,
}: {
    title: string;
    worked: number;
    expected: number;
    difference: number | null;
    extra?: React.ReactNode;
    testId: string;
}) {
    return (
        <div className="grid gap-3 rounded-md border p-4" data-test={testId}>
            <h3 className="text-sm text-muted-foreground">{title}</h3>
            <dl className="grid grid-cols-2 gap-3">
                <Figure
                    label={t('people.workday.worked')}
                    value={formatMinutes(worked)}
                    hint={t('people.workday.of_expected', {
                        expected: formatMinutes(expected),
                    })}
                />
                {difference !== null ? (
                    <Figure
                        label={t('people.workday.difference')}
                        value={formatDifference(difference)}
                        tone={difference < 0 ? 'negative' : undefined}
                    />
                ) : null}
            </dl>
            {extra}
        </div>
    );
}

function DiaryTable({
    days,
    onOpen,
}: {
    days: WorkdayDay[];
    onOpen: (date: string) => void;
}) {
    return (
        <div className="hidden overflow-x-auto rounded-md border md:block">
            <table className="w-full text-sm" data-test="workday-diary">
                <caption className="sr-only">
                    {t('people.workday.caption')}
                </caption>
                <thead>
                    <tr className="border-b text-left text-xs text-muted-foreground">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('people.workday.col_day')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('people.workday.col_schedule')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('people.workday.col_records')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('people.workday.col_pause')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('people.workday.col_worked')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('people.workday.col_difference')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('people.workday.col_mode')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('people.workday.col_status')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {days.map((day) => (
                        <tr
                            key={day.date}
                            className={cn(
                                'border-b last:border-0 hover:bg-accent/50',
                                day.status === 'off' && 'text-muted-foreground',
                            )}
                            data-test="diary-row"
                            data-date={day.date}
                            data-status={day.status}
                        >
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-normal"
                            >
                                <button
                                    type="button"
                                    onClick={() => onOpen(day.date)}
                                    className={cn(
                                        'rounded-sm capitalize hover:underline',
                                        FOCUS_RING,
                                    )}
                                    aria-label={t('people.workday.open_day', {
                                        date: shortDayLabel(day.date),
                                    })}
                                    data-test="diary-open"
                                >
                                    {shortDayLabel(day.date)}
                                </button>
                            </th>
                            <td className="tabular px-3 py-2 text-xs">
                                {expectedLabel(day)}
                            </td>
                            <td className="tabular px-3 py-2">
                                {day.workdays.length > 0 ? (
                                    segmentsLabel(day.workdays, day.date)
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                                <IncidentList
                                    incidents={day.incidents}
                                    className="mt-1"
                                />
                            </td>
                            <td className="tabular px-3 py-2 text-right">
                                {day.pause_minutes > 0
                                    ? formatMinutes(day.pause_minutes)
                                    : ''}
                            </td>
                            <td className="tabular px-3 py-2 text-right">
                                {day.workdays.length > 0
                                    ? formatMinutes(day.worked_minutes)
                                    : ''}
                            </td>
                            <td
                                className={cn(
                                    'tabular px-3 py-2 text-right',
                                    (day.difference_minutes ?? 0) < 0 &&
                                        'text-danger',
                                )}
                            >
                                {day.expected_minutes > 0 ||
                                day.worked_minutes > 0
                                    ? formatDifference(day.difference_minutes)
                                    : ''}
                            </td>
                            <td className="px-3 py-2 text-xs">
                                {day.modes.map(modeLabel).join(', ')}
                            </td>
                            <td className="px-3 py-2">
                                <DayStatusBadge status={day.status} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** En el móvil, cada día es una tarjeta que se puede pulsar entera. */
function DiaryCards({
    days,
    onOpen,
}: {
    days: WorkdayDay[];
    onOpen: (date: string) => void;
}) {
    return (
        <ul className="grid gap-2 md:hidden" data-test="workday-cards">
            {days.map((day) => (
                <li key={day.date}>
                    <button
                        type="button"
                        onClick={() => onOpen(day.date)}
                        className={cn(
                            'grid w-full gap-1 rounded-md border p-3 text-left text-sm hover:bg-accent/50',
                            day.status === 'off' && 'text-muted-foreground',
                            FOCUS_RING,
                        )}
                        aria-label={t('people.workday.open_day', {
                            date: shortDayLabel(day.date),
                        })}
                        data-test="diary-card"
                        data-status={day.status}
                    >
                        <span className="flex items-center justify-between gap-2">
                            <span className="capitalize">
                                {shortDayLabel(day.date)}
                            </span>
                            <DayStatusBadge status={day.status} />
                        </span>
                        <span className="tabular text-xs text-muted-foreground">
                            {day.workdays.length > 0
                                ? segmentsLabel(day.workdays, day.date)
                                : expectedLabel(day)}
                        </span>
                        {day.workdays.length > 0 ? (
                            <span className="tabular flex gap-3 text-xs">
                                <span>
                                    {t('people.workday.worked')}{' '}
                                    {formatMinutes(day.worked_minutes)}
                                </span>
                                <span
                                    className={cn(
                                        (day.difference_minutes ?? 0) < 0 &&
                                            'text-danger',
                                    )}
                                >
                                    {formatDifference(day.difference_minutes)}
                                </span>
                            </span>
                        ) : null}
                        <IncidentList incidents={day.incidents} />
                    </button>
                </li>
            ))}
        </ul>
    );
}

WorkdayPage.layout = {
    breadcrumbs: [{ title: t('people.workday.title'), href: workdayIndex() }],
};
