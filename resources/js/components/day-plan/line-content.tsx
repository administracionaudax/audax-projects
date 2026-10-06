import { Ban, CalendarArrowUp, Check, Repeat, SquareCheck } from 'lucide-react';
import { carryLabel } from '@/lib/day-plan';
import { formatMinutes, formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { DayPlanLine } from '@/types/day-plan';

/**
 * El contenido de una línea (texto, «↻ ×N», cliente o proyecto, tarea, motivo de «no hecha» y, a
 * quien puede verlas, las horas previstas e imputadas). Lo comparten Mi día, Inicio y el equipo.
 */
export function LineContent({
    line,
    showAddedLate = false,
}: {
    line: DayPlanLine;
    /** En el equipo: «añadida a las 12:40» si se escribió pasada la hora límite (§4.4). */
    showAddedLate?: boolean;
}) {
    const done = line.status === 'done';
    const closed = line.status === 'not_done' || line.status === 'carried';
    const target = line.project
        ? `${line.project.code} · ${line.project.name}`
        : (line.client?.name ?? t('day_plan.line.general'));

    return (
        <div className="min-w-0 flex-1">
            <p
                className={cn(
                    'text-sm break-words',
                    done && 'text-muted-foreground line-through',
                    closed && 'text-muted-foreground',
                )}
                data-test="day-plan-line-text"
            >
                {line.text}
                {line.carry_count > 0 ? (
                    <span
                        className="ml-2 inline-flex items-center gap-0.5 text-xs whitespace-nowrap text-muted-foreground no-underline"
                        title={carryLabel(line.carry_count)}
                        data-test="day-plan-carry-count"
                    >
                        <Repeat
                            aria-hidden="true"
                            className="size-3 text-warning"
                        />
                        <span aria-hidden="true">×{line.carry_count}</span>
                        <span className="sr-only">
                            {carryLabel(line.carry_count)}
                        </span>
                    </span>
                ) : null}
            </p>
            <p className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                <span className="inline-flex min-w-0 items-center gap-1">
                    {line.project ? (
                        <span
                            aria-hidden="true"
                            className="size-2 shrink-0 rounded-full"
                            style={{ backgroundColor: line.project.color }}
                        />
                    ) : null}
                    <span className="truncate">{target}</span>
                </span>
                {line.task ? (
                    <span className="inline-flex min-w-0 items-center gap-1">
                        <SquareCheck
                            aria-hidden="true"
                            className="size-3 shrink-0"
                        />
                        <span className="truncate">
                            {t('day_plan.line.task', { task: line.task.title })}
                        </span>
                    </span>
                ) : null}
                {line.status === 'not_done' ? (
                    <span
                        className="inline-flex items-center gap-1"
                        data-test="day-plan-not-done"
                    >
                        <Ban aria-hidden="true" className="size-3" />
                        {line.not_done_reason
                            ? t('day_plan.line.not_done_reason', {
                                  reason: line.not_done_reason,
                              })
                            : t('day_plan.line.not_done')}
                    </span>
                ) : null}
                {line.status === 'carried' ? (
                    <span className="inline-flex items-center gap-1">
                        <CalendarArrowUp
                            aria-hidden="true"
                            className="size-3"
                        />
                        {t('day_plan.line.carried')}
                    </span>
                ) : null}
                {done ? (
                    <span className="sr-only">{t('day_plan.line.done')}</span>
                ) : null}
                {showAddedLate && line.added_late && line.created_at ? (
                    <span>
                        {t('day_plan.line.added_at', {
                            time: formatTime(line.created_at),
                        })}
                    </span>
                ) : null}
            </p>
        </div>
    );
}

/** «2:00» previstas y lo imputado («1:05 ✓»), solo si llegan las cifras. */
export function LineFigures({ line }: { line: DayPlanLine }) {
    if (line.planned_minutes === null && !line.logged_minutes) {
        return null;
    }

    return (
        <span
            className="tabular flex shrink-0 flex-col items-end text-xs"
            data-test="day-plan-line-figures"
        >
            {line.planned_minutes !== null ? (
                <span title={t('day_plan.line.planned_title')}>
                    <span className="sr-only">
                        {t('day_plan.line.planned_sr')}{' '}
                    </span>
                    {formatMinutes(line.planned_minutes)}
                </span>
            ) : null}
            {line.logged_minutes ? (
                <span
                    className="inline-flex items-center gap-0.5 text-muted-foreground"
                    title={t('day_plan.line.logged_title')}
                >
                    <span className="sr-only">
                        {t('day_plan.line.logged_sr')}{' '}
                    </span>
                    {formatMinutes(line.logged_minutes)}
                    <Check aria-hidden="true" className="size-3" />
                </span>
            ) : null}
        </span>
    );
}
