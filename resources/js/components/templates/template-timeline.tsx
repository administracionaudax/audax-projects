import { Diamond, TriangleAlert } from 'lucide-react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { EditorRow } from './template-editor-state';
import {
    conflictsOf,
    endDay,
    isSubtask,
    totalDays,
} from './template-editor-state';

/** Ancho de un día en el cronograma (px); con su propio scroll horizontal si no cabe. */
const DAY_WIDTH = 14;

/** Una marca cada semana (relativa: días 1, 8, 15…). */
const TICK_EVERY = 7;

/**
 * Vista previa de una plantilla (D-058): mini-cronograma relativo con una barra por tarea (del día
 * de inicio a la entrega) y un rombo por hito, en el orden del editor y con las subtareas
 * sangradas. Colores de datos en orden fijo (D-012): tareas --chart-1, subtareas --chart-2 e hitos
 * --chart-3, siempre con leyenda y sin depender solo del color (el rombo y la sangría también lo
 * dicen). La tabla del editor es la alternativa accesible: aquí se resume con un texto.
 */
export function TemplateTimeline({
    rows,
    labels,
}: {
    rows: EditorRow[];
    /** Número de cada fila en el editor («1», «1.1»…), por referencia. */
    labels: Record<string, string>;
}) {
    const days = Math.max(totalDays(rows), 1);
    const width = Math.max(days * DAY_WIDTH, 280);
    const ticks = Array.from(
        { length: Math.ceil(days / TICK_EVERY) },
        (_, index) => index * TICK_EVERY,
    );
    const milestones = rows.filter((row) => row.is_milestone).length;
    const conflicts = rows.filter(
        (row) => conflictsOf(rows, row.ref).length > 0,
    );

    return (
        <figure className="grid gap-3" data-test="template-timeline">
            <figcaption className="grid gap-1">
                <span className="text-sm font-medium">
                    {t('templates.timeline.title')}
                </span>
                <span className="text-sm text-muted-foreground">
                    {t('templates.timeline.summary', {
                        tasks: rows.length,
                        milestones,
                        days,
                    })}
                </span>
            </figcaption>

            <ul
                className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                aria-label={t('templates.timeline.legend')}
            >
                <li className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="inline-block h-2.5 w-5 rounded-[2px] bg-chart-1"
                    />
                    {t('templates.timeline.legend_task')}
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="inline-block h-2.5 w-5 rounded-[2px] bg-chart-2"
                    />
                    {t('templates.timeline.legend_subtask')}
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <Diamond
                        aria-hidden="true"
                        className="size-3 fill-chart-3 text-chart-3"
                    />
                    {t('templates.timeline.legend_milestone')}
                </li>
            </ul>

            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('templates.timeline.empty')}
                </p>
            ) : (
                <div
                    role="region"
                    aria-label={t('templates.timeline.scroll_label')}
                    tabIndex={0}
                    className={cn(
                        'overflow-x-auto rounded-md border bg-background',
                        FOCUS_RING,
                    )}
                >
                    <div
                        className="grid grid-cols-[minmax(7rem,12rem)_1fr] text-xs"
                        style={{ minWidth: `calc(7rem + ${width}px)` }}
                        aria-hidden="true"
                    >
                        <div className="border-b bg-muted px-2 py-1 text-muted-foreground">
                            {t('templates.timeline.task')}
                        </div>
                        <div className="relative h-6 border-b bg-muted">
                            {ticks.map((day) => (
                                <span
                                    key={day}
                                    className="absolute top-0 flex h-full items-center border-l pl-1 text-muted-foreground"
                                    style={{ left: `${(day / days) * 100}%` }}
                                >
                                    {t('templates.timeline.day', {
                                        day: day + 1,
                                    })}
                                </span>
                            ))}
                        </div>

                        {rows.map((row) => {
                            const start = (row.start_offset_days / days) * 100;
                            const span =
                                ((endDay(row) - row.start_offset_days + 1) /
                                    days) *
                                100;
                            const subtask = isSubtask(row);

                            return (
                                <div key={row.ref} className="contents">
                                    <div
                                        className={cn(
                                            'truncate border-b px-2 py-1',
                                            subtask &&
                                                'pl-5 text-muted-foreground',
                                        )}
                                    >
                                        {labels[row.ref]}{' '}
                                        {row.title.trim() ||
                                            t('templates.editor.untitled')}
                                    </div>
                                    <div className="relative h-7 border-b">
                                        {ticks.map((day) => (
                                            <span
                                                key={day}
                                                className="absolute top-0 h-full border-l border-dashed"
                                                style={{
                                                    left: `${(day / days) * 100}%`,
                                                }}
                                            />
                                        ))}
                                        {row.is_milestone ? (
                                            <Diamond
                                                className="absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 fill-chart-3 text-chart-3"
                                                style={{
                                                    left: `${start + span / 2}%`,
                                                }}
                                            />
                                        ) : (
                                            <span
                                                className={cn(
                                                    'absolute top-1/2 h-3 -translate-y-1/2 rounded-[2px]',
                                                    subtask
                                                        ? 'bg-chart-2'
                                                        : 'bg-chart-1',
                                                )}
                                                style={{
                                                    left: `${start}%`,
                                                    width: `max(${span}%, 3px)`,
                                                }}
                                            />
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}

            {conflicts.length > 0 ? (
                <p className="flex items-start gap-2 text-sm text-foreground">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    {t('templates.timeline.conflicts', {
                        count: conflicts.length,
                    })}
                </p>
            ) : null}
        </figure>
    );
}
