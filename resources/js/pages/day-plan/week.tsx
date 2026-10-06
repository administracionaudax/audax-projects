import { Head, Link } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Repeat,
    Square,
    SquareCheck,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DayPlanNav } from '@/components/day-plan/day-plan-nav';
import { LineContent } from '@/components/day-plan/line-content';
import { DayStateBadge, TeamFilters } from '@/components/day-plan/team-ui';
import { EmptyState } from '@/components/empty-state';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import { weekdayShortLabel, dayMonthLabel } from '@/components/time/week-days';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { STICKY_TABLE_WRAPPER } from '@/components/weeklies/insights/project-status-view';
import { dayLabel, normalize } from '@/lib/day-plan';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { week as weekRoute } from '@/routes/day-plan';
import type {
    DayPlanPerson,
    TeamWeekCell,
    TeamWeekPageProps,
} from '@/types/day-plan';

/**
 * Semana del equipo (`/dia/semana`, docs/PLAN-CARGAS.md §4.3; D-251): personas × días con el estado de
 * cada día; clic en una celda para ver sus líneas. «Hechas / planificadas» y el total de la semana,
 * solo a quien puede ver las cifras de esa persona.
 */
export default function TeamWeekPage(props: TeamWeekPageProps) {
    const { week, days, today, rows, department, departments } = props;
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<{
        person: DayPlanPerson;
        cell: TeamWeekCell;
    } | null>(null);
    const visible = useMemo(() => {
        const needle = normalize(search.trim());

        return needle === ''
            ? rows
            : rows.filter((row) => normalize(row.user.name).includes(needle));
    }, [rows, search]);
    const departmentQuery = department === 'all' ? 'todos' : String(department);
    const title = t('day_plan.week.title');

    return (
        <>
            <Head title={title} />
            <div className="flex w-full flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-normal tracking-tight">
                            {title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('day_plan.week.range', {
                                from: dayMonthLabel(days[0] ?? today),
                                to: dayMonthLabel(
                                    days[days.length - 1] ?? today,
                                ),
                            })}
                        </p>
                    </div>
                    <nav
                        aria-label={t('day_plan.week.nav')}
                        className="flex items-center gap-1"
                    >
                        <Button
                            asChild
                            variant="outline"
                            size="icon"
                            aria-label={t('day_plan.week.previous')}
                        >
                            <Link
                                href={weekRoute.url({
                                    query: {
                                        semana: props.previous_week,
                                        departamento: departmentQuery,
                                    },
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
                                week === props.current_week ? 'date' : undefined
                            }
                        >
                            <Link
                                href={weekRoute.url({
                                    query: { departamento: departmentQuery },
                                })}
                            >
                                {t('day_plan.week.this_week')}
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            size="icon"
                            aria-label={t('day_plan.week.next')}
                        >
                            <Link
                                href={weekRoute.url({
                                    query: {
                                        semana: props.next_week,
                                        departamento: departmentQuery,
                                    },
                                })}
                                preserveScroll
                            >
                                <ChevronRight aria-hidden="true" />
                            </Link>
                        </Button>
                    </nav>
                </header>

                <DayPlanNav current="week" />

                <TeamFilters
                    url={weekRoute.url()}
                    query={week === props.current_week ? {} : { semana: week }}
                    department={department}
                    departments={departments}
                    search={search}
                    onSearch={setSearch}
                />

                {visible.length === 0 ? (
                    <EmptyState icon={Users} title={t('day_plan.team.empty')} />
                ) : (
                    <div className={STICKY_TABLE_WRAPPER}>
                        <table
                            className="w-full min-w-[720px] border-collapse text-sm"
                            data-test="day-plan-week"
                        >
                            <caption className="sr-only">
                                {t('day_plan.week.caption')}
                            </caption>
                            <thead>
                                <tr className="border-b text-left text-xs text-muted-foreground">
                                    <th
                                        scope="col"
                                        className="sticky left-0 bg-background px-2 py-2 font-medium"
                                    >
                                        {t('day_plan.week.person')}
                                    </th>
                                    {days.map((date) => (
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
                                        className="px-2 py-2 font-medium"
                                    >
                                        {t('day_plan.week.total')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((row) => (
                                    <tr
                                        key={row.user.id}
                                        className="border-b"
                                        data-test="day-plan-week-row"
                                    >
                                        <th
                                            scope="row"
                                            className="sticky left-0 bg-background px-2 py-2 text-left font-normal"
                                        >
                                            <span className="flex items-center gap-2">
                                                <UserAvatar
                                                    user={row.user}
                                                    className="hidden sm:inline-flex"
                                                />
                                                <span className="truncate">
                                                    {row.user.name}
                                                </span>
                                            </span>
                                        </th>
                                        {row.days.map((cell) => (
                                            <td
                                                key={cell.date}
                                                className="px-1 py-1 align-top"
                                            >
                                                <button
                                                    type="button"
                                                    disabled={
                                                        cell.items.length === 0
                                                    }
                                                    onClick={() =>
                                                        setSelected({
                                                            person: row.user,
                                                            cell,
                                                        })
                                                    }
                                                    className={cn(
                                                        'flex min-h-10 w-full flex-col items-start gap-0.5 px-1.5 py-1 text-left enabled:hover:bg-accent disabled:cursor-default',
                                                        FOCUS_RING,
                                                    )}
                                                    aria-label={t(
                                                        'day_plan.week.cell',
                                                        {
                                                            name: row.user.name,
                                                            day: dayLabel(
                                                                cell.date,
                                                                today,
                                                            ),
                                                        },
                                                    )}
                                                    data-test="day-plan-week-cell"
                                                    data-state={cell.state}
                                                >
                                                    {cell.figures ? (
                                                        <span className="tabular inline-flex items-center gap-1 text-xs">
                                                            {cell.figures
                                                                .done ===
                                                            cell.figures
                                                                .total ? (
                                                                <SquareCheck
                                                                    aria-hidden="true"
                                                                    className="size-3.5 text-success"
                                                                />
                                                            ) : (
                                                                <Square
                                                                    aria-hidden="true"
                                                                    className="size-3.5 text-muted-foreground"
                                                                />
                                                            )}
                                                            {t(
                                                                'day_plan.week.done',
                                                                {
                                                                    done: cell
                                                                        .figures
                                                                        .done,
                                                                    total: cell
                                                                        .figures
                                                                        .total,
                                                                },
                                                            )}
                                                            {cell.figures
                                                                .carried > 0 ? (
                                                                <span className="inline-flex items-center text-muted-foreground">
                                                                    <Repeat
                                                                        aria-hidden="true"
                                                                        className="size-3 text-warning"
                                                                    />
                                                                    {
                                                                        cell
                                                                            .figures
                                                                            .carried
                                                                    }
                                                                    <span className="sr-only">
                                                                        {' '}
                                                                        {t(
                                                                            'day_plan.week.carried_sr',
                                                                        )}
                                                                    </span>
                                                                </span>
                                                            ) : null}
                                                        </span>
                                                    ) : (
                                                        <DayStateBadge
                                                            state={cell.state}
                                                            reason={cell.reason}
                                                            compact
                                                        />
                                                    )}
                                                </button>
                                            </td>
                                        ))}
                                        <td className="tabular px-2 py-2 text-xs">
                                            {row.figures &&
                                            row.figures.total > 0
                                                ? `${t('day_plan.week.done', { done: row.figures.done, total: row.figures.total })} · ${formatPercent(row.figures.done / row.figures.total)}`
                                                : row.figures
                                                  ? '—'
                                                  : ''}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <p className="text-xs text-muted-foreground">
                    {t('day_plan.week.legend')}
                </p>
            </div>

            <Dialog
                open={selected !== null}
                onOpenChange={(open) => (open ? null : setSelected(null))}
            >
                <DialogContent className="sm:max-w-lg">
                    {selected ? (
                        <>
                            <DialogTitle>{selected.person.name}</DialogTitle>
                            <DialogDescription className="first-letter:uppercase">
                                {dayLabel(selected.cell.date, today)}
                            </DialogDescription>
                            <ol
                                className="grid gap-2"
                                data-test="day-plan-week-lines"
                            >
                                {selected.cell.items.map((line) => (
                                    <li
                                        key={line.id}
                                        className="flex items-start gap-2"
                                    >
                                        {line.status === 'done' ? (
                                            <SquareCheck
                                                aria-hidden="true"
                                                className="mt-0.5 size-4 shrink-0 text-success"
                                            />
                                        ) : (
                                            <Square
                                                aria-hidden="true"
                                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                            />
                                        )}
                                        <LineContent
                                            line={line}
                                            showAddedLate
                                        />
                                    </li>
                                ))}
                            </ol>
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </>
    );
}
