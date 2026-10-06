import { Square, SquareCheck, Sun } from 'lucide-react';
import { t } from '@/lib/i18n';
import { LineContent, LineFigures } from './line-content';
import type { DayPlanLine } from '@/types/day-plan';

/**
 * «Plan del día» desplegable en la vista Día del calendario del equipo, por personas
 * (docs/PLAN-CARGAS.md §10; D-254). Las horas solo llegan a quien puede verlas (D-251).
 */
export function CalendarDayPlan({
    lines,
    name,
}: {
    lines: DayPlanLine[] | undefined;
    name: string;
}) {
    const items = lines ?? [];

    return (
        <details className="mt-2 text-xs" data-test="calendar-day-plan">
            <summary className="flex cursor-pointer items-center gap-1 text-muted-foreground">
                <Sun aria-hidden="true" className="size-3.5" />
                {items.length === 0
                    ? t('day_plan.calendar.empty_summary')
                    : t('day_plan.calendar.summary', {
                          done: items.filter((line) => line.status === 'done')
                              .length,
                          total: items.length,
                      })}
                <span className="sr-only">
                    {' '}
                    {t('day_plan.team.lines_of', { name })}
                </span>
            </summary>
            {items.length > 0 ? (
                <ol className="mt-1 grid gap-1">
                    {items.map((line) => (
                        <li key={line.id} className="flex items-start gap-1.5">
                            {line.status === 'done' ? (
                                <SquareCheck
                                    aria-hidden="true"
                                    className="mt-0.5 size-3.5 shrink-0 text-success"
                                />
                            ) : (
                                <Square
                                    aria-hidden="true"
                                    className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                />
                            )}
                            <LineContent line={line} />
                            <LineFigures line={line} />
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="mt-1 text-muted-foreground">
                    {t('day_plan.calendar.empty')}
                </p>
            )}
        </details>
    );
}
