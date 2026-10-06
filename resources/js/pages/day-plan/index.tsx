import { Head, Link, router } from '@inertiajs/react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import {
    ChevronLeft,
    ChevronRight,
    ListChecks,
    Sun,
    Timer,
} from 'lucide-react';
import { useId, useState } from 'react';
import { DayPlanNav } from '@/components/day-plan/day-plan-nav';
import { FromTasksDialog } from '@/components/day-plan/from-tasks-dialog';
import { LineComposer } from '@/components/day-plan/line-composer';
import {
    LineLogDialog,
    LineTimeActions,
    LineTimerButton,
    LinkEntriesDialog,
} from '@/components/day-plan/line-time';
import { MyDayList } from '@/components/day-plan/my-day-list';
import { PendingBanner } from '@/components/day-plan/pending-banner';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { dayLabel } from '@/lib/day-plan';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes, formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { addDays } from '@/lib/week';
import {
    logPlanned as logPlannedDay,
    note as noteRoute,
    show,
} from '@/routes/day-plan';
import type { DayPlanLine, MyDayPageProps } from '@/types/day-plan';

/**
 * «Mi día» (docs/PLAN-CARGAS.md §4.3, D-250): lo que voy a hacer hoy (o el día que elija) en líneas de
 * texto libre, con el aviso de las pendientes de días anteriores, la nota del día y, al final, la caja
 * para escribir la siguiente línea. Las cifras del día (jornada, previsto, imputado y hechas) son
 * solo mías.
 */
export default function MyDayPage({ day, targets }: MyDayPageProps) {
    const [fromTasks, setFromTasks] = useState(false);
    const [logging, setLogging] = useState<DayPlanLine | null>(null);
    const [linking, setLinking] = useState<DayPlanLine | null>(null);
    const [loggingPlanned, setLoggingPlanned] = useState(false);
    const isToday = day.date === day.today;
    const previous = addDays(day.date, -1);
    const next = addDays(day.date, 1);
    const title = t('day_plan.title');

    return (
        <>
            <Head title={title} />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-normal tracking-tight">
                            {title}
                        </h1>
                        <p
                            className="text-sm text-muted-foreground first-letter:uppercase"
                            data-test="day-plan-date"
                        >
                            {dayLabel(day.date, day.today)}
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
                                href={show.url({ query: { fecha: previous } })}
                                preserveScroll
                            >
                                <ChevronLeft aria-hidden="true" />
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            aria-current={isToday ? 'date' : undefined}
                        >
                            <Link href={show.url()}>{t('day_plan.today')}</Link>
                        </Button>
                        {next <= day.horizon_end ? (
                            <Button
                                asChild
                                variant="outline"
                                size="icon"
                                aria-label={t('day_plan.next_day')}
                            >
                                <Link
                                    href={show.url({ query: { fecha: next } })}
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
                                aria-label={t('day_plan.next_day')}
                            >
                                <ChevronRight aria-hidden="true" />
                            </Button>
                        )}
                    </nav>
                </header>

                <DayPlanNav
                    current="mine"
                    date={isToday ? undefined : day.date}
                />

                <dl
                    className="flex flex-wrap gap-x-6 gap-y-1 text-sm"
                    data-test="day-plan-summary"
                >
                    <div className="flex gap-1">
                        <dt className="text-muted-foreground">
                            {t('day_plan.summary.capacity')}
                        </dt>
                        <dd className="tabular">
                            {formatMinutes(day.summary.capacity_minutes)}
                        </dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="text-muted-foreground">
                            {t('day_plan.summary.planned')}
                        </dt>
                        <dd
                            className={cn(
                                'tabular',
                                day.summary.planned_minutes >
                                    day.summary.capacity_minutes &&
                                    'text-danger',
                            )}
                        >
                            {formatMinutes(day.summary.planned_minutes)}
                        </dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="text-muted-foreground">
                            {t('day_plan.summary.logged')}
                        </dt>
                        <dd className="tabular">
                            {formatMinutes(day.summary.logged_minutes)}
                        </dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="text-muted-foreground">
                            {t('day_plan.summary.done')}
                        </dt>
                        <dd className="tabular">
                            {t('day_plan.summary.done_value', {
                                done: day.summary.done,
                                total: day.summary.total,
                            })}
                        </dd>
                    </div>
                    {day.plan.published_at ? (
                        <div className="flex gap-1">
                            <dt className="text-muted-foreground">
                                {t('day_plan.summary.published')}
                            </dt>
                            <dd className="tabular">
                                {formatTime(day.plan.published_at)}
                            </dd>
                        </div>
                    ) : null}
                </dl>

                {isToday ? (
                    <PendingBanner pending={day.pending} today={day.today} />
                ) : null}

                <DayNote
                    date={day.date}
                    note={day.plan.note}
                    canWrite={day.can.write}
                />

                <section
                    aria-label={t('day_plan.lines_label')}
                    className="grid gap-2"
                >
                    {day.items.length === 0 && !day.can.write ? (
                        <EmptyState
                            icon={Sun}
                            title={t('day_plan.empty_past')}
                        />
                    ) : null}
                    {day.items.length > 0 ? (
                        <MyDayList
                            date={day.date}
                            lines={day.items}
                            targets={targets}
                            today={day.today}
                            horizonEnd={day.horizon_end}
                            canWrite={day.can.write}
                            canClose={day.can.close}
                            renderTimer={
                                isToday
                                    ? (line) => <LineTimerButton line={line} />
                                    : undefined
                            }
                            renderActions={
                                day.can.close
                                    ? (line) => (
                                          <LineTimeActions
                                              line={line}
                                              onLog={() => setLogging(line)}
                                              onLink={() => setLinking(line)}
                                          />
                                      )
                                    : undefined
                            }
                        />
                    ) : null}
                    {day.can.write ? (
                        <LineComposer
                            date={day.date}
                            targets={targets}
                            autoFocus={day.items.length === 0}
                        />
                    ) : null}
                </section>

                {day.can.write ||
                (day.can.close && day.summary.loggable > 0) ? (
                    <div className="flex flex-wrap items-center gap-2">
                        {day.can.close && day.summary.loggable > 0 ? (
                            <Button
                                type="button"
                                size="sm"
                                disabled={loggingPlanned}
                                onClick={() =>
                                    router.post(
                                        logPlannedDay.url(),
                                        { date: day.date },
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                            errorBag: 'dayPlan',
                                            // Que ningún error del servidor se pierda en silencio (D-310).
                                            onError: toastVisitErrors,
                                            onStart: () =>
                                                setLoggingPlanned(true),
                                            onFinish: () =>
                                                setLoggingPlanned(false),
                                        },
                                    )
                                }
                                data-test="day-plan-log-planned-all"
                            >
                                <Timer aria-hidden="true" />
                                {t('day_plan.time.log_planned_all', {
                                    count: day.summary.loggable,
                                })}
                            </Button>
                        ) : null}
                        {day.can.write ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setFromTasks(true)}
                                data-test="day-plan-from-tasks"
                            >
                                <ListChecks aria-hidden="true" />
                                {t('day_plan.from_tasks.open')}
                            </Button>
                        ) : null}
                    </div>
                ) : null}
                <FromTasksDialog
                    date={day.date}
                    open={fromTasks}
                    onOpenChange={setFromTasks}
                />
                {logging ? (
                    <LineLogDialog
                        line={logging}
                        onOpenChange={(open) =>
                            open ? null : setLogging(null)
                        }
                    />
                ) : null}
                {linking ? (
                    <LinkEntriesDialog
                        line={linking}
                        onOpenChange={(open) =>
                            open ? null : setLinking(null)
                        }
                    />
                ) : null}
            </div>
        </>
    );
}

/** Nota del día («Hoy tengo médico a las 12»): se guarda al salir del campo. */
function DayNote({
    date,
    note,
    canWrite,
}: {
    date: string;
    note: string | null;
    canWrite: boolean;
}) {
    const id = useId();
    const [value, setValue] = useState(note ?? '');
    const [open, setOpen] = useState(note !== null && note !== '');

    if (!canWrite) {
        return note ? (
            <p
                className="border-l-2 pl-3 text-sm text-muted-foreground"
                data-test="day-plan-note"
            >
                {note}
            </p>
        ) : null;
    }

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={cn(
                    'self-start text-sm text-primary-text hover:underline',
                    FOCUS_RING,
                )}
                data-test="day-plan-note-open"
            >
                {t('day_plan.note.add')}
            </button>
        );
    }

    return (
        <div className="grid gap-1.5">
            <label htmlFor={`${id}-note`} className="text-sm font-medium">
                {t('day_plan.note.label')}
            </label>
            <Textarea
                id={`${id}-note`}
                value={value}
                rows={2}
                maxLength={500}
                placeholder={t('day_plan.note.placeholder')}
                onChange={(event) => setValue(event.target.value)}
                onBlur={() => {
                    if (value.trim() !== (note ?? '').trim()) {
                        router.put(
                            noteRoute.url(),
                            { date, note: value.trim() || null },
                            {
                                preserveScroll: true,
                                preserveState: true,
                                errorBag: 'dayPlan',
                                // Que ningún error del servidor se pierda en silencio (D-310).
                                onError: toastVisitErrors,
                            },
                        );
                    }
                }}
                data-test="day-plan-note-input"
            />
        </div>
    );
}
