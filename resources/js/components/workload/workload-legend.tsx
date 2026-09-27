import { CalendarClock, CalendarMinus, OctagonAlert } from 'lucide-react';
import { LOAD_LEVELS } from '@/components/charts/thresholds';
import type { LoadLevel } from '@/components/charts/thresholds';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const LEVELS: LoadLevel[] = ['none', 'under', 'balanced', 'high', 'over'];

/**
 * Leyenda del semáforo de carga (D-052): cada nivel con su icono, su nombre y su tramo, y las dos
 * marcas de las celdas (capacidad reducida y tareas vencidas).
 */
export function WorkloadLegend({ className }: { className?: string }) {
    return (
        <section
            aria-label={t('workload_legend.label')}
            className={cn('text-xs text-muted-foreground', className)}
        >
            <ul className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                {LEVELS.map((level) => {
                    const meta = LOAD_LEVELS[level];
                    const Icon = meta.icon;

                    return (
                        <li
                            key={level}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                className={cn(
                                    'inline-flex size-5 items-center justify-center rounded-[3px]',
                                    meta.surface,
                                )}
                            >
                                <Icon
                                    aria-hidden="true"
                                    className={cn('size-3.5', meta.tone)}
                                />
                            </span>
                            <span>
                                <span className="text-foreground">
                                    {meta.label}
                                </span>
                                {': '}
                                {t(
                                    `workload_legend.${level}` as TranslationKey,
                                )}
                            </span>
                        </li>
                    );
                })}
                <li className="inline-flex items-center gap-1.5">
                    <CalendarMinus aria-hidden="true" className="size-3.5" />
                    {t('workload_legend.reduced')}
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <OctagonAlert
                        aria-hidden="true"
                        className="size-3.5 text-danger"
                    />
                    {t('workload_legend.no_capacity_load')}
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <CalendarClock
                        aria-hidden="true"
                        className="size-3.5 text-danger"
                    />
                    {t('workload_legend.overdue')}
                </li>
            </ul>
        </section>
    );
}
