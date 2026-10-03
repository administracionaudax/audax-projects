import { CircleCheck, Diamond, TriangleAlert } from 'lucide-react';
import { barFill } from '@/components/gantt/colors';
import type { LegendEntry } from '@/components/gantt/colors';
import type { GanttColorMode } from '@/components/gantt/types';
import { t } from '@/lib/i18n';

/**
 * Leyenda del Gantt: los colores del modo elegido (con su nombre) y las marcas fijas (hito,
 * resumen, dependencia, dependencia en conflicto y hoy). Siempre texto junto al color.
 */
export function GanttLegend({
    mode,
    entries,
}: {
    mode: GanttColorMode;
    entries: ReadonlyArray<LegendEntry>;
}) {
    return (
        <div className="grid gap-2 text-sm text-muted-foreground">
            {entries.length > 0 ? (
                <ul
                    aria-label={t(`gantt.legend.${mode}`)}
                    className="flex flex-wrap items-center gap-x-4 gap-y-1"
                    data-test="gantt-legend"
                >
                    {entries.map((entry) => (
                        <li
                            key={entry.key}
                            className="flex items-center gap-1.5"
                        >
                            <span
                                aria-hidden="true"
                                className="h-3 w-5 shrink-0 rounded-sm border"
                                style={{
                                    backgroundColor: barFill(entry.color),
                                    borderColor: entry.color,
                                    borderStyle: entry.dashed
                                        ? 'dashed'
                                        : 'solid',
                                }}
                            />
                            {entry.done ? (
                                <CircleCheck
                                    aria-hidden="true"
                                    className="size-3.5 text-success"
                                />
                            ) : null}
                            <span className="text-foreground">
                                {entry.label}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : null}
            <ul
                aria-label={t('gantt.legend.marks')}
                className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
            >
                <li className="flex items-center gap-1.5">
                    <Diamond aria-hidden="true" className="size-3.5" />
                    {t('gantt.legend.milestone')}
                </li>
                <li className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="h-1.5 w-5 rounded-sm bg-muted-foreground"
                    />
                    {t('gantt.legend.summary')}
                </li>
                <li className="flex items-center gap-1.5">
                    <svg aria-hidden="true" width="20" height="8">
                        <path
                            d="M0 4 H16"
                            stroke="var(--muted-foreground)"
                            strokeWidth="1.5"
                        />
                        <path
                            d="M14 1 L19 4 L14 7 z"
                            fill="var(--muted-foreground)"
                        />
                    </svg>
                    {t('gantt.legend.dependency')}
                </li>
                <li className="flex items-center gap-1.5">
                    <TriangleAlert
                        aria-hidden="true"
                        className="size-3.5 text-destructive-foreground"
                    />
                    {t('gantt.legend.conflict')}
                </li>
                <li className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="h-3 w-0.5 rounded-full bg-primary"
                    />
                    {t('gantt.legend.today')}
                </li>
            </ul>
        </div>
    );
}
