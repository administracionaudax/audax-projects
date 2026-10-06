import { Head, Link, router } from '@inertiajs/react';
import {
    BellRing,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronsDownUp,
    ChevronsUpDown,
    Repeat,
    Square,
    SquareCheck,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DayPlanNav } from '@/components/day-plan/day-plan-nav';
import { LineComments } from '@/components/day-plan/line-comments';
import { LineContent, LineFigures } from '@/components/day-plan/line-content';
import { DayStateBadge, TeamFilters } from '@/components/day-plan/team-ui';
import { EmptyState } from '@/components/empty-state';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { dayLabel, normalize } from '@/lib/day-plan';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { addDays } from '@/lib/week';
import { team as teamRoute } from '@/routes/day-plan';
import { remind } from '@/routes/day-plan/team';
import type { TeamDayPageProps, TeamDayRow } from '@/types/day-plan';

/**
 * «Equipo hoy» (`/dia/equipo`, docs/PLAN-CARGAS.md §4.2 y §4.3; D-251): el plan del día de cada
 * persona, por departamento. Toda la plantilla ve los textos y los checks; las cifras (hora del plan,
 * previsto frente a jornada, hechas, imputado y temporizador) y los comentarios, solo la persona, su
 * responsable y los admins. Quien no ha escrito el plan pasada la hora límite sale en rojo, con
 * «Recordar» para su responsable.
 */
export default function TeamDayPage(props: TeamDayPageProps) {
    const { date, today, deadline, rows, summary, department, departments } =
        props;
    const [search, setSearch] = useState('');
    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
    const visible = useMemo(() => {
        const needle = normalize(search.trim());

        return needle === ''
            ? rows
            : rows.filter((row) => normalize(row.user.name).includes(needle));
    }, [rows, search]);
    const isToday = date === today;
    const query: Record<string, string> = isToday ? {} : { fecha: date };
    const title = t('day_plan.team.title');
    const allCollapsed = visible.every((row) => collapsed.has(row.user.id));

    const toggle = (id: number) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    return (
        <>
            <Head title={title} />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-normal tracking-tight">
                            {title}
                        </h1>
                        <p className="text-sm text-muted-foreground first-letter:uppercase">
                            {dayLabel(date, today)}
                            {' · '}
                            {t('day_plan.team.deadline', { time: deadline })}
                        </p>
                    </div>
                    <nav
                        aria-label={t('day_plan.day_nav')}
                        className="flex items-center gap-1"
                    >
                        <Button
                            asChild
                            variant="outline"
                            size="icon"
                            aria-label={t('day_plan.previous_day')}
                        >
                            <Link
                                href={teamRoute.url({
                                    query: {
                                        fecha: addDays(date, -1),
                                        departamento:
                                            department === 'all'
                                                ? 'todos'
                                                : String(department),
                                    },
                                })}
                                preserveScroll
                            >
                                <ChevronLeft aria-hidden="true" />
                            </Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={teamRoute.url({
                                    query: {
                                        departamento:
                                            department === 'all'
                                                ? 'todos'
                                                : String(department),
                                    },
                                })}
                            >
                                {t('day_plan.today')}
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            size="icon"
                            aria-label={t('day_plan.next_day')}
                        >
                            <Link
                                href={teamRoute.url({
                                    query: {
                                        fecha: addDays(date, 1),
                                        departamento:
                                            department === 'all'
                                                ? 'todos'
                                                : String(department),
                                    },
                                })}
                                preserveScroll
                            >
                                <ChevronRight aria-hidden="true" />
                            </Link>
                        </Button>
                    </nav>
                </header>

                <DayPlanNav current="team" date={isToday ? undefined : date} />

                <div className="flex flex-wrap items-end justify-between gap-3">
                    <TeamFilters
                        url={teamRoute.url()}
                        query={query}
                        department={department}
                        departments={departments}
                        search={search}
                        onSearch={setSearch}
                    />
                    {visible.length > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                setCollapsed(
                                    allCollapsed
                                        ? new Set()
                                        : new Set(
                                              visible.map((row) => row.user.id),
                                          ),
                                )
                            }
                        >
                            {allCollapsed ? (
                                <ChevronsUpDown aria-hidden="true" />
                            ) : (
                                <ChevronsDownUp aria-hidden="true" />
                            )}
                            {allCollapsed
                                ? t('day_plan.team.expand_all')
                                : t('day_plan.team.collapse_all')}
                        </Button>
                    ) : null}
                </div>

                <p
                    className="text-sm text-muted-foreground"
                    data-test="day-plan-team-summary"
                >
                    {t('day_plan.team.summary', {
                        with: summary.with_plan,
                        people: summary.people,
                    })}
                    {summary.without_plan > 0
                        ? ` · ${t('day_plan.team.summary_without', { count: summary.without_plan })}`
                        : ''}
                    {summary.away > 0
                        ? ` · ${t('day_plan.team.summary_away', { count: summary.away })}`
                        : ''}
                    {summary.figures
                        ? ` · ${t('day_plan.team.summary_done', { done: summary.figures.done, total: summary.figures.total })} · ${t('day_plan.team.summary_carried', { count: summary.figures.carried })}`
                        : ''}
                </p>

                {visible.length === 0 ? (
                    <EmptyState icon={Users} title={t('day_plan.team.empty')} />
                ) : (
                    <ul className="divide-y border-y" data-test="day-plan-team">
                        {visible.map((row) => (
                            <TeamRow
                                key={row.user.id}
                                row={row}
                                open={!collapsed.has(row.user.id)}
                                onToggle={() => toggle(row.user.id)}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function TeamRow({
    row,
    open,
    onToggle,
}: {
    row: TeamDayRow;
    open: boolean;
    onToggle: () => void;
}) {
    const [reminding, setReminding] = useState(false);
    const figures = row.figures;
    const panelId = `day-plan-person-${row.user.id}`;
    const over =
        figures !== null &&
        figures.capacity_minutes > 0 &&
        figures.planned_minutes > figures.capacity_minutes;

    return (
        <li
            className="py-3"
            data-test="day-plan-team-row"
            data-user-id={row.user.id}
            data-state={row.state}
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={open}
                    aria-controls={panelId}
                    disabled={row.items.length === 0}
                    className={cn(
                        'flex min-w-0 items-center gap-2 text-left disabled:cursor-default',
                        FOCUS_RING,
                    )}
                >
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-4 shrink-0 text-muted-foreground transition-transform',
                            !open && '-rotate-90',
                            row.items.length === 0 && 'invisible',
                        )}
                    />
                    <UserAvatar user={row.user} />
                    <span className="truncate text-sm font-medium">
                        {row.user.name}
                    </span>
                </button>
                <DayStateBadge
                    state={row.state}
                    reason={row.reason}
                    publishedAt={figures?.published_at}
                />
                {figures ? (
                    <span
                        className="tabular flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground"
                        data-test="day-plan-team-figures"
                    >
                        {figures.total > 0 ? (
                            <span>
                                {t('day_plan.team.done', {
                                    done: figures.done,
                                    total: figures.total,
                                })}
                            </span>
                        ) : null}
                        <span className={cn(over && 'text-danger')}>
                            {t('day_plan.team.planned', {
                                planned: formatMinutes(figures.planned_minutes),
                                capacity: formatMinutes(
                                    figures.capacity_minutes,
                                ),
                            })}
                        </span>
                        <span>
                            {t('day_plan.team.logged', {
                                time: formatMinutes(figures.logged_minutes),
                            })}
                        </span>
                        {figures.carried > 0 ? (
                            <span className="inline-flex items-center gap-0.5">
                                <Repeat
                                    aria-hidden="true"
                                    className="size-3 text-warning"
                                />
                                {t('day_plan.team.carried', {
                                    count: figures.carried,
                                })}
                            </span>
                        ) : null}
                        {figures.running ? (
                            <span
                                className="inline-flex min-w-0 items-center gap-1 text-foreground"
                                data-test="day-plan-team-running"
                            >
                                <Square
                                    aria-hidden="true"
                                    className="size-3 fill-current text-primary"
                                />
                                <span className="truncate">
                                    {t('day_plan.team.running', {
                                        text: figures.running.text,
                                    })}
                                </span>
                            </span>
                        ) : null}
                    </span>
                ) : null}
                {row.can_remind ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="ml-auto"
                        disabled={reminding || row.reminded}
                        onClick={() =>
                            router.post(
                                remind.url(row.user.id),
                                {},
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onStart: () => setReminding(true),
                                    onFinish: () => setReminding(false),
                                },
                            )
                        }
                        data-test="day-plan-remind"
                    >
                        {reminding ? (
                            <Spinner />
                        ) : (
                            <BellRing aria-hidden="true" />
                        )}
                        {row.reminded
                            ? t('day_plan.team.reminded')
                            : t('day_plan.team.remind', {
                                  name: row.user.name,
                              })}
                    </Button>
                ) : null}
            </div>
            {row.note ? (
                <p className="mt-1 pl-14 text-xs text-muted-foreground">
                    {row.note}
                </p>
            ) : null}
            {open && row.items.length > 0 ? (
                <ol
                    id={panelId}
                    className="mt-2 grid gap-2 pl-14"
                    aria-label={t('day_plan.team.lines_of', {
                        name: row.user.name,
                    })}
                >
                    {row.items.map((line) => (
                        <li
                            key={line.id}
                            className="grid gap-1"
                            data-test="day-plan-team-line"
                            data-status={line.status}
                        >
                            <div className="flex items-start gap-2">
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
                                <LineContent line={line} showAddedLate />
                                <LineFigures line={line} />
                            </div>
                            <LineComments
                                line={line}
                                canComment={row.can_comment}
                            />
                        </li>
                    ))}
                </ol>
            ) : null}
        </li>
    );
}
