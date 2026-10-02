import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AtSign,
    CalendarClock,
    CalendarOff,
    CalendarX,
    Clock,
    Gauge,
    ListChecks,
    Milestone,
    Plus,
    Square,
    Timer,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { MyAbsencesCard } from '@/components/absences/my-absences-card';
import type { MyAbsencesSummary } from '@/components/absences/types';
import {
    HomeChatCard,
    HomeChatSkeleton,
} from '@/components/chat/home-chat-card';
import {
    TaskStatusBadge,
    TimesheetStatusBadge,
} from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import { R1MyIndicators } from '@/components/reports/r1-my-indicators';
import type { MyIndicators } from '@/components/reports/r1-types';
import { MyMilestones } from '@/components/planning/milestone-list';
import { CapacityCell } from '@/components/time/capacity-cell';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import { stopTimer } from '@/components/time/timer-actions';
import { TimerButton } from '@/components/time/timer-button';
import {
    formatElapsed,
    useElapsedSeconds,
} from '@/components/time/use-elapsed';
import { weekdayLongLabel } from '@/components/time/week-days';
import {
    MyWorkload,
    MyWorkloadSkeleton,
} from '@/components/workload/my-workload-card';
import type { MyWorkloadData } from '@/components/workload/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { firstName, useRequiredUser } from '@/hooks/use-auth';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { index as timeIndex } from '@/routes/time';
import type { ActiveTimer, HomePageProps, HomeTask } from '@/types';

function PanelCard({
    id,
    icon: Icon,
    title,
    description,
    wide = false,
    children,
}: {
    id: string;
    icon: LucideIcon;
    title: string;
    description?: string;
    wide?: boolean;
    children: ReactNode;
}) {
    return (
        <Card
            className={cn('gap-4', wide && 'md:col-span-2')}
            data-test={`home-card-${id}`}
        >
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Icon
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                        strokeWidth={1.5}
                    />
                    <h2>{title}</h2>
                </CardTitle>
                {description ? (
                    <CardDescription>{description}</CardDescription>
                ) : null}
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3">
                {children}
            </CardContent>
        </Card>
    );
}

/**
 * Panel personal «Inicio» (SPEC §5.1, D-021): solo las cosas de quien lo mira. En la Fase 1
 * están activas las tareas, el temporizador, las horas de la semana y los días sin imputar; en la
 * Fase 2, «Mis indicadores» del mes; en la Fase 3, «Mi carga» (prop diferida `workload`: no
 * retrasa la primera carga) y «Mis ausencias»; en la Fase 4, mis próximos hitos (D-062); en la
 * Fase 6, las menciones y los mensajes sin leer (prop diferida `chat_summary`).
 */
export default function Home({
    tasks,
    hours,
    week,
    unlogged_days: unloggedDays,
    indicators,
    absences,
    workload,
    milestones,
    chat_summary: chatSummary,
}: HomePageProps & {
    indicators: MyIndicators;
    absences: MyAbsencesSummary;
    workload?: MyWorkloadData;
}) {
    const user = useRequiredUser();
    const timer = usePage().props.timer ?? null;
    const [logging, setLogging] = useState<{ date?: string } | null>(null);
    const taskCount =
        tasks.overdue.length + tasks.today.length + tasks.week.length;

    return (
        <>
            <Head title={t('home.title')} />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-3xl font-normal tracking-tight text-foreground">
                        <KeywordText
                            text={t('home.greeting', { name: firstName(user) })}
                        />
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('home.subtitle')}
                    </p>
                </header>

                <section
                    aria-label={t('home.panel_label')}
                    className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4"
                >
                    <PanelCard
                        id="today-tasks"
                        icon={ListChecks}
                        title={t('home_panel.tasks.title')}
                        description={t('home.cards.tasks.description')}
                        wide
                    >
                        {taskCount === 0 ? (
                            <EmptyState
                                icon={ListChecks}
                                title={t('home_panel.tasks.empty')}
                                description={t(
                                    'home_panel.tasks.empty_description',
                                )}
                            >
                                <Button asChild variant="outline" size="sm">
                                    <Link href={urls.myTasks()}>
                                        {t('home_panel.tasks.all')}
                                    </Link>
                                </Button>
                            </EmptyState>
                        ) : (
                            <>
                                <TaskGroup
                                    title={t('home_panel.tasks.overdue')}
                                    tasks={tasks.overdue}
                                    overdue
                                />
                                <TaskGroup
                                    title={t('home_panel.tasks.today')}
                                    tasks={tasks.today}
                                />
                                <TaskGroup
                                    title={t('home_panel.tasks.week')}
                                    tasks={tasks.week}
                                />
                                <Link
                                    href={urls.myTasks()}
                                    className={cn(
                                        'self-start rounded-sm text-sm text-primary-text hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {t('home_panel.tasks.all')}
                                </Link>
                            </>
                        )}
                    </PanelCard>

                    <PanelCard
                        id="timer"
                        icon={Timer}
                        title={t('home.cards.timer.title')}
                        description={t('home.cards.timer.description')}
                    >
                        {timer ? (
                            <RunningTimer timer={timer} />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('home_panel.timer.none')}
                            </p>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            className="self-start"
                            onClick={() => setLogging({})}
                        >
                            <Plus aria-hidden="true" />
                            {t('hours.header.log_time')}
                        </Button>
                    </PanelCard>

                    <PanelCard
                        id="week-hours"
                        icon={Clock}
                        title={t('home.cards.hours.title')}
                    >
                        <dl className="grid gap-2 text-sm">
                            <div className="flex items-center justify-between gap-2">
                                <dt className="text-muted-foreground">
                                    {t('home_panel.hours.today')}
                                </dt>
                                <dd>
                                    <CapacityCell
                                        logged={hours.today}
                                        capacity={hours.capacity_today}
                                    />
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-2">
                                <dt className="text-muted-foreground">
                                    {t('home_panel.hours.week')}
                                </dt>
                                <dd>
                                    <CapacityCell
                                        logged={hours.week}
                                        capacity={hours.capacity_week}
                                    />
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-2">
                                <dt className="text-muted-foreground">
                                    {t('home_panel.hours.status')}
                                </dt>
                                <dd>
                                    <TimesheetStatusBadge
                                        status={week.period.status}
                                    />
                                </dd>
                            </div>
                        </dl>
                        {week.period.status === 'returned' ? (
                            <div
                                className="flex gap-2 rounded-md border border-warning bg-warning-soft p-3 text-sm"
                                data-test="home-returned"
                            >
                                <Undo2
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-warning"
                                />
                                <p>
                                    {week.period.reviewer
                                        ? t('hours.status.comment_by', {
                                              name: week.period.reviewer.name,
                                          })
                                        : t('hours.status.returned_own')}{' '}
                                    «{week.period.review_comment}»
                                </p>
                            </div>
                        ) : null}
                        <Link
                            href={timeIndex({ query: { semana: week.iso } })}
                            className={cn(
                                'self-start rounded-sm text-sm text-primary-text hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {t('home_panel.hours.open_sheet')}
                        </Link>
                    </PanelCard>

                    <PanelCard
                        id="workload"
                        icon={CalendarClock}
                        title={t('home.cards.workload.title')}
                        description={t('home.cards.workload.description')}
                        wide
                    >
                        <Deferred
                            data="workload"
                            fallback={<MyWorkloadSkeleton />}
                        >
                            <MyWorkload workload={workload} />
                        </Deferred>
                    </PanelCard>

                    <PanelCard
                        id="unlogged-days"
                        icon={CalendarX}
                        title={t('home_panel.unlogged.title')}
                        description={t('home_panel.unlogged.description')}
                    >
                        {unloggedDays.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('home_panel.unlogged.none')}
                            </p>
                        ) : (
                            <ul className="grid gap-1 text-sm">
                                {unloggedDays.map((day) => (
                                    <li
                                        key={day.date}
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <Link
                                            href={timeIndex({
                                                query: { semana: day.week },
                                            })}
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
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setLogging({ date: day.date })
                                            }
                                            aria-label={t(
                                                'home_panel.unlogged.log',
                                                { date: formatDate(day.date) },
                                            )}
                                        >
                                            <Plus aria-hidden="true" />
                                            {t('home_panel.unlogged.log_short')}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </PanelCard>

                    <PanelCard
                        id="indicators"
                        icon={Gauge}
                        title={t('home.cards.indicators.title')}
                        description={t('home.cards.indicators.description')}
                        wide
                    >
                        <R1MyIndicators
                            indicators={indicators}
                            userId={user.id}
                        />
                    </PanelCard>

                    {/* Fase 3 (área de ausencias): «Mis ausencias», antes de las tarjetas de fases futuras. */}
                    <PanelCard
                        id="absences"
                        icon={CalendarOff}
                        title={t('home.cards.absences.title')}
                        description={t('home.cards.absences.description')}
                    >
                        <MyAbsencesCard absences={absences} />
                    </PanelCard>

                    <PanelCard
                        id="milestones"
                        icon={Milestone}
                        title={t('planning.home.title')}
                        description={t('planning.home.description')}
                    >
                        <MyMilestones milestones={milestones} />
                    </PanelCard>

                    <PanelCard
                        id="mentions"
                        icon={AtSign}
                        title={t('home.cards.mentions.title')}
                        description={t('home.cards.mentions.description')}
                    >
                        <Deferred
                            data="chat_summary"
                            fallback={<HomeChatSkeleton />}
                        >
                            {chatSummary ? (
                                <HomeChatCard summary={chatSummary} />
                            ) : null}
                        </Deferred>
                    </PanelCard>
                </section>
            </div>

            <TimeEntryDialog
                open={logging !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setLogging(null);
                    }
                }}
                date={logging?.date}
            />
        </>
    );
}

function RunningTimer({ timer }: { timer: ActiveTimer }) {
    const seconds = useElapsedSeconds(timer.started_at);
    const [processing, setProcessing] = useState(false);

    return (
        <div className="grid gap-2">
            <Link
                href={urls.task(timer.project_id, timer.task_id)}
                className={cn('rounded-sm hover:underline', FOCUS_RING)}
            >
                <span className="block text-xs text-muted-foreground">
                    {timer.project_code} · {timer.project_name}
                </span>
                <span className="block">{timer.task_title}</span>
            </Link>
            <p className="tabular text-2xl font-medium" aria-hidden="true">
                {formatElapsed(seconds)}
            </p>
            <Button
                type="button"
                className="self-start"
                disabled={processing}
                onClick={() =>
                    stopTimer({
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                    })
                }
                aria-label={t('timer.stop', { task: timer.task_title })}
            >
                <Square aria-hidden="true" className="fill-current" />
                {t('hours.timer.stop_short')}
            </Button>
        </div>
    );
}

function TaskGroup({
    title,
    tasks,
    overdue = false,
}: {
    title: string;
    tasks: HomeTask[];
    overdue?: boolean;
}) {
    if (tasks.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-1">
            <h3
                className={cn(
                    'text-sm font-medium',
                    overdue && 'flex items-center gap-1',
                )}
            >
                {overdue ? (
                    <CalendarX
                        aria-hidden="true"
                        className="size-4 text-danger"
                    />
                ) : null}
                {title}{' '}
                <span className="text-muted-foreground">({tasks.length})</span>
            </h3>
            <ul className="divide-y rounded-md border">
                {tasks.map((task) => (
                    <li
                        key={task.id}
                        className="flex items-center gap-2 px-2 py-1.5"
                        data-test="home-task"
                    >
                        <TimerButton task={task} />
                        <div className="min-w-0 flex-1">
                            <Link
                                href={urls.task(task.project_id, task.id)}
                                className={cn(
                                    'block truncate rounded-sm text-sm hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                {task.title}
                            </Link>
                            <span className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                <span className="inline-flex items-center gap-1">
                                    <span
                                        aria-hidden="true"
                                        className="size-2 rounded-full"
                                        style={{
                                            backgroundColor: task.project.color,
                                        }}
                                    />
                                    {task.project.code}
                                </span>
                                {task.due_date ? (
                                    <span
                                        className={cn(
                                            overdue && 'text-foreground',
                                        )}
                                    >
                                        {t('home_panel.tasks.due', {
                                            date: formatDate(task.due_date),
                                        })}
                                    </span>
                                ) : null}
                            </span>
                        </div>
                        <span className="hidden sm:inline">
                            <TaskStatusBadge
                                name={task.status.name}
                                color={task.status.color}
                                done={task.status.is_done}
                            />
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

Home.layout = {
    breadcrumbs: [{ title: t('nav.home'), href: home() }],
};
