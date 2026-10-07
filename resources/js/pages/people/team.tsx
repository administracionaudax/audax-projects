import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Inbox, Users } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { EmptyState } from '@/components/empty-state';
import { DayStatusBadge, PeopleFrame } from '@/components/people/people-ui';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import { dayMonthLabel, weekdayShortLabel } from '@/components/time/week-days';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { STICKY_TABLE_WRAPPER } from '@/components/weeklies/insights/project-status-view';
import { normalize } from '@/lib/day-plan';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes, formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { formatDifference, tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import { index as pendingIndex } from '@/routes/people/pending';
import { index as teamIndex, show as teamShow } from '@/routes/people/team';
import type { TeamNowState, TeamWorkdayPageProps } from '@/types/people';

const NOW_TONE: Record<TeamNowState, string> = {
    working: 'bg-success',
    paused: 'bg-warning',
    closed: 'bg-muted-foreground',
    not_clocked: 'bg-danger',
    absent: 'bg-info',
    not_working: 'bg-border',
};

/**
 * «Jornada del equipo» (`/personas/equipo`, PLAN-FASE-11 §6.2; D-341): para el responsable (su
 * departamento) y RR. HH. (toda la plantilla), persona × día de la semana con lo trabajado frente a
 * la teórica y el estado del día, y cómo está cada uno ahora (W-023). Cada celda abre el diario de
 * esa persona en ese día. Los fichajes los hace cada persona: aquí solo se proponen correcciones.
 */
export default function TeamWorkdayPage(props: TeamWorkdayPageProps) {
    const {
        week,
        dates,
        today,
        people,
        departments,
        department_id: departmentId,
    } = props;
    const id = useId();
    const [search, setSearch] = useState('');
    const visible = useMemo(() => {
        const needle = normalize(search.trim());

        return needle === ''
            ? people
            : people.filter((person) =>
                  normalize(person.name).includes(needle),
              );
    }, [people, search]);
    const departmentQuery: Record<string, string> =
        departmentId === null ? {} : { departamento: String(departmentId) };

    return (
        <>
            <Head title={t('people.team.title')} />
            <PeopleFrame
                section="team"
                title={t('people.team.title')}
                description={t('people.team.description')}
                actions={
                    <nav
                        aria-label={t('people.team.week_nav')}
                        className="flex items-center gap-1"
                    >
                        <Button
                            asChild
                            variant="outline"
                            size="icon"
                            aria-label={t('people.team.previous_week')}
                        >
                            <Link
                                href={teamIndex.url({
                                    query: {
                                        semana: props.previous_week,
                                        ...departmentQuery,
                                    },
                                })}
                                preserveScroll
                            >
                                <ChevronLeft aria-hidden="true" />
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link
                                href={teamIndex.url({ query: departmentQuery })}
                            >
                                {t('people.team.this_week')}
                            </Link>
                        </Button>
                        {props.next_week ? (
                            <Button
                                asChild
                                variant="outline"
                                size="icon"
                                aria-label={t('people.team.next_week')}
                            >
                                <Link
                                    href={teamIndex.url({
                                        query: {
                                            semana: props.next_week,
                                            ...departmentQuery,
                                        },
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
                                aria-label={t('people.team.next_week')}
                            >
                                <ChevronRight aria-hidden="true" />
                            </Button>
                        )}
                    </nav>
                }
            >
                {props.pending > 0 ? (
                    <div
                        className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-info-soft p-3 text-sm"
                        data-test="team-pending"
                    >
                        <span className="flex items-center gap-2">
                            <Inbox
                                aria-hidden="true"
                                className="size-4 text-info"
                            />
                            {tCount(
                                'people.team.pending_banner',
                                props.pending,
                            )}
                        </span>
                        <Button asChild size="sm" variant="outline">
                            <Link href={pendingIndex.url()}>
                                {t('people.team.pending_link')}
                            </Link>
                        </Button>
                    </div>
                ) : null}

                <div className="flex flex-wrap items-end gap-3">
                    <p className="mr-auto text-sm text-muted-foreground">
                        {t('people.team.range', {
                            from: dayMonthLabel(dates[0] ?? today),
                            to: dayMonthLabel(dates[dates.length - 1] ?? today),
                        })}
                    </p>
                    {departments.length > 1 ? (
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`${id}-department`}
                                className="text-xs"
                            >
                                {t('people.team.department')}
                            </Label>
                            <NativeSelect
                                id={`${id}-department`}
                                value={
                                    departmentId === null
                                        ? ''
                                        : String(departmentId)
                                }
                                onChange={(event) =>
                                    router.get(
                                        teamIndex.url({
                                            query: {
                                                semana: week,
                                                ...(event.target.value === ''
                                                    ? {}
                                                    : {
                                                          departamento:
                                                              event.target
                                                                  .value,
                                                      }),
                                            },
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className="w-56"
                            >
                                <option value="">
                                    {t('people.team.all_departments')}
                                </option>
                                {departments.map((department) => (
                                    <option
                                        key={department.id}
                                        value={department.id}
                                    >
                                        {department.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    ) : null}
                    <div className="grid gap-1">
                        <Label htmlFor={`${id}-search`} className="text-xs">
                            {t('people.team.search')}
                        </Label>
                        <Input
                            id={`${id}-search`}
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="w-56"
                        />
                    </div>
                </div>

                {visible.length === 0 ? (
                    <EmptyState icon={Users} title={t('people.team.empty')} />
                ) : (
                    <div className={STICKY_TABLE_WRAPPER}>
                        <table
                            className="w-full min-w-[56rem] border-collapse text-sm"
                            data-test="team-workday"
                        >
                            <caption className="sr-only">
                                {t('people.team.caption')}
                            </caption>
                            <thead>
                                <tr className="border-b text-left text-xs text-muted-foreground">
                                    <th
                                        scope="col"
                                        className="sticky left-0 z-10 bg-background px-2 py-2 font-medium"
                                    >
                                        {t('people.team.person')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-2 py-2 font-medium"
                                    >
                                        {t('people.team.now')}
                                    </th>
                                    {dates.map((date) => (
                                        <th
                                            key={date}
                                            scope="col"
                                            className={cn(
                                                'px-2 py-2 font-medium capitalize',
                                                date === today &&
                                                    'text-foreground',
                                            )}
                                            aria-current={
                                                date === today
                                                    ? 'date'
                                                    : undefined
                                            }
                                        >
                                            {weekdayShortLabel(date)}{' '}
                                            {dayMonthLabel(date)}
                                        </th>
                                    ))}
                                    <th
                                        scope="col"
                                        className="px-2 py-2 text-right font-medium"
                                    >
                                        {t('people.team.total')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((person) => (
                                    <tr
                                        key={person.id}
                                        className="border-b"
                                        data-test="team-row"
                                    >
                                        <th
                                            scope="row"
                                            className="sticky left-0 z-10 bg-background px-2 py-2 text-left font-normal"
                                        >
                                            <Link
                                                href={teamShow.url(person.id)}
                                                className={cn(
                                                    'flex items-center gap-2 rounded-sm hover:underline',
                                                    FOCUS_RING,
                                                )}
                                            >
                                                <UserAvatar
                                                    user={person}
                                                    className="hidden sm:inline-flex"
                                                />
                                                <span className="truncate">
                                                    {person.name}
                                                </span>
                                            </Link>
                                        </th>
                                        <td className="px-2 py-2 text-xs whitespace-nowrap">
                                            {person.now ? (
                                                <span
                                                    className="inline-flex items-center gap-1.5"
                                                    data-test="team-now"
                                                    data-state={
                                                        person.now.state
                                                    }
                                                >
                                                    <span
                                                        aria-hidden="true"
                                                        className={cn(
                                                            'size-2 rounded-full',
                                                            NOW_TONE[
                                                                person.now.state
                                                            ],
                                                        )}
                                                    />
                                                    {t(
                                                        `people.team.now_state.${person.now.state}` as TranslationKey,
                                                    )}
                                                    {person.now.since ? (
                                                        <span className="tabular text-muted-foreground">
                                                            {formatTime(
                                                                person.now
                                                                    .since,
                                                            )}
                                                        </span>
                                                    ) : null}
                                                </span>
                                            ) : null}
                                        </td>
                                        {person.days.map((cell) => (
                                            <td
                                                key={cell.date}
                                                className="px-1 py-1 align-top"
                                            >
                                                {cell.status ===
                                                'future' ? null : (
                                                    <Link
                                                        href={teamShow.url(
                                                            person.id,
                                                            {
                                                                query: {
                                                                    mes: cell.date.slice(
                                                                        0,
                                                                        7,
                                                                    ),
                                                                    dia: cell.date,
                                                                },
                                                            },
                                                        )}
                                                        className={cn(
                                                            'flex min-h-11 w-full flex-col items-start gap-0.5 rounded-sm px-1.5 py-1 hover:bg-accent',
                                                            FOCUS_RING,
                                                        )}
                                                        aria-label={t(
                                                            'people.team.cell',
                                                            {
                                                                name: person.name,
                                                                day: `${weekdayShortLabel(cell.date)} ${dayMonthLabel(cell.date)}`,
                                                            },
                                                        )}
                                                        data-test="team-cell"
                                                        data-status={
                                                            cell.status
                                                        }
                                                    >
                                                        <span className="tabular text-xs">
                                                            {cell.worked_minutes >
                                                                0 ||
                                                            cell.expected_minutes >
                                                                0
                                                                ? `${formatMinutes(cell.worked_minutes)} / ${formatMinutes(cell.expected_minutes)}`
                                                                : ''}
                                                        </span>
                                                        <DayStatusBadge
                                                            status={cell.status}
                                                            compact
                                                        />
                                                    </Link>
                                                )}
                                            </td>
                                        ))}
                                        <td className="tabular px-2 py-2 text-right text-xs whitespace-nowrap">
                                            <span className="block">
                                                {formatMinutes(
                                                    person.totals
                                                        .worked_minutes,
                                                )}{' '}
                                                /{' '}
                                                {formatMinutes(
                                                    person.totals
                                                        .expected_minutes,
                                                )}
                                            </span>
                                            <span
                                                className={cn(
                                                    'block',
                                                    person.totals
                                                        .difference_minutes < 0
                                                        ? 'text-danger'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {formatDifference(
                                                    person.totals
                                                        .difference_minutes,
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <p className="text-xs text-muted-foreground">
                    {t('people.team.legend')}
                </p>
            </PeopleFrame>
        </>
    );
}

TeamWorkdayPage.layout = {
    breadcrumbs: [{ title: t('people.team.title'), href: teamIndex() }],
};
