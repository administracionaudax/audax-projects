import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    CalendarClock,
    CalendarOff,
    CircleAlert,
    CircleCheck,
    CircleDot,
    CircleSlash,
    Clock,
    Flame,
    Lock,
    Minus,
    PencilLine,
} from 'lucide-react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Progress } from '@/components/ui/progress';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    WeeklyCycleProgress,
    WeeklyCycleSummary,
    WeeklyPersonStatus,
} from '@/types/weeklies';

/**
 * Piezas comunes de las pantallas de la Weekly (10.2b): etiquetas de estado (icono + texto, nunca
 * solo color), la semana compacta del móvil (F-021), el icono del cliente y la participación.
 */

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';
type Meta = { tone: Tone; icon: LucideIcon };

export const PERSON_STATUS: Record<WeeklyPersonStatus, Meta> = {
    upcoming: { tone: 'neutral', icon: CalendarClock },
    pending: { tone: 'info', icon: PencilLine },
    overdue: { tone: 'danger', icon: CircleAlert },
    submitted: { tone: 'success', icon: CircleCheck },
    submitted_late: { tone: 'warning', icon: Clock },
    missed: { tone: 'neutral', icon: CircleSlash },
    exempt: { tone: 'neutral', icon: CalendarOff },
    not_required: { tone: 'neutral', icon: Minus },
};

const CYCLE_PROGRESS: Record<WeeklyCycleProgress, Meta> = {
    finished: { tone: 'neutral', icon: Lock },
    upcoming: { tone: 'neutral', icon: CalendarClock },
    overdue: { tone: 'danger', icon: CircleAlert },
    completed: { tone: 'success', icon: CircleCheck },
    in_progress: { tone: 'info', icon: CircleDot },
};

/** ¿Ya la ha enviado (a tiempo o con retraso)? */
export function isSubmittedStatus(status: WeeklyPersonStatus): boolean {
    return status === 'submitted' || status === 'submitted_late';
}

/** ¿Le toca y aún no la ha enviado? */
export function isPendingStatus(status: WeeklyPersonStatus): boolean {
    return (
        status === 'pending' || status === 'overdue' || status === 'upcoming'
    );
}

export function PersonStatusBadge({
    status,
    className,
}: {
    status: WeeklyPersonStatus;
    className?: string;
}) {
    const meta = PERSON_STATUS[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon} className={className}>
            {t(`weeklies.person_status.${status}`)}
        </StatusBadge>
    );
}

export function CycleProgressBadge({
    progress,
}: {
    progress: WeeklyCycleProgress;
}) {
    const meta = CYCLE_PROGRESS[progress];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`weeklies.cycle_progress.${progress}`)}
        </StatusBadge>
    );
}

/** "2026-10-05" → "05/10". */
function dayMonth(date: string): string {
    const match = /^\d{4}-(\d{2})-(\d{2})/.exec(date);

    return match ? `${match[2]}/${match[1]}` : date;
}

/** «W41-26» → 41. */
export function weekNumber(cycle: Pick<WeeklyCycleSummary, 'number'>): string {
    const match = /\d+/.exec(cycle.number);

    return match ? String(Number(match[0])) : cycle.number;
}

/** F-021: «Sem. 41 · 05/10 - 09/10», para el móvil. */
export function compactWeekLabel(
    cycle: Pick<WeeklyCycleSummary, 'number' | 'start_date' | 'end_date'>,
): string {
    return t('weeklies.week.compact', {
        week: weekNumber(cycle),
        start: dayMonth(cycle.start_date),
        end: dayMonth(cycle.end_date),
    });
}

/** La etiqueta de la semana: compacta en el móvil y entera desde `sm` (F-021). */
export function WeekLabel({
    cycle,
    className,
}: {
    cycle: Pick<
        WeeklyCycleSummary,
        'number' | 'start_date' | 'end_date' | 'label'
    >;
    className?: string;
}) {
    return (
        <span className={className}>
            <span className="sm:hidden">{compactWeekLabel(cycle)}</span>
            <span className="hidden sm:inline">{cycle.label}</span>
        </span>
    );
}

/** Icono del cliente (emoji de WeeklySync, F-126) o el edificio por defecto. */
export function ClientIcon({
    icon,
    className,
}: {
    icon: string | null | undefined;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-flex size-7 shrink-0 items-center justify-center border bg-muted text-base leading-none',
                className,
            )}
        >
            {icon ? (
                icon
            ) : (
                <Building2
                    className="size-4 text-muted-foreground"
                    strokeWidth={1.5}
                />
            )}
        </span>
    );
}

/** «3 semanas» de racha, con la llama. */
export function StreakValue({
    streak,
    className,
}: {
    streak: number;
    className?: string;
}) {
    return (
        <span
            className={cn('inline-flex items-center gap-1.5', className)}
            data-test="weekly-streak"
        >
            <Flame
                aria-hidden="true"
                className="size-4 text-warning"
                strokeWidth={1.5}
            />
            <span>
                {streak === 1
                    ? t('weeklies.streak.one')
                    : t('weeklies.streak.other', { count: streak })}
            </span>
        </span>
    );
}

/** Participación de una semana: «3 / 5 enviadas (1 exento)» y la barra. */
export function Participation({
    submitted,
    expected,
    exempt,
    className,
}: {
    submitted: number;
    expected: number;
    exempt: number;
    className?: string;
}) {
    const ratio = expected > 0 ? Math.round((submitted / expected) * 100) : 100;
    const label = t('weeklies.participation.count', {
        submitted,
        expected,
    });

    return (
        <div className={cn('grid gap-1.5', className)}>
            <p className="flex flex-wrap items-baseline gap-x-2 text-sm">
                <span className="tabular">{label}</span>
                {exempt > 0 ? (
                    <span className="text-xs text-muted-foreground">
                        {exempt === 1
                            ? t('weeklies.participation.exempt_one')
                            : t('weeklies.participation.exempt_other', {
                                  count: exempt,
                              })}
                    </span>
                ) : null}
            </p>
            {/* Color por tramo, como WeeklySync (10.9b): todas verde, más de la mitad ámbar, si no rojo. */}
            <Progress
                value={ratio}
                aria-label={label}
                data-tone={
                    expected === 0 || submitted >= expected
                        ? 'complete'
                        : ratio > 50
                          ? 'partial'
                          : 'low'
                }
                indicatorClassName={
                    expected === 0 || submitted >= expected
                        ? 'bg-success'
                        : ratio > 50
                          ? 'bg-warning'
                          : 'bg-danger'
                }
            />
        </div>
    );
}
