import { Link } from '@inertiajs/react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showCycle } from '@/routes/weeklies';
import type {
    WeeklyConsistencyCell,
    WeeklyConsistencyState,
} from '@/types/weeklies';

const TONE: Record<WeeklyConsistencyState, string> = {
    on_time: 'bg-success',
    late: 'bg-warning',
    missed: 'bg-danger',
    exempt: 'bg-neutral-soft border',
    pending: 'bg-muted border border-dashed',
};

const LEGEND: WeeklyConsistencyState[] = [
    'on_time',
    'late',
    'missed',
    'exempt',
    'pending',
];

/**
 * «Constancia (últimas 12 semanas)» (10.9b, D-233; `ws:ProfileView.tsx:159-216` y
 * `ws:TeamView.tsx:1210-1267`): una casilla por semana, de la más antigua a la más reciente, con su
 * color (a tiempo, tarde, sin enviar, exenta o pendiente), su texto para el lector de pantalla y el
 * enlace a la semana. Solo desde el alta.
 */
export function ConsistencyMap({
    cells,
    className,
}: {
    cells: WeeklyConsistencyCell[];
    className?: string;
}) {
    return (
        <section
            aria-labelledby="consistency-title"
            className={cn('grid gap-2', className)}
            data-test="weekly-consistency"
        >
            <h3 id="consistency-title" className="text-sm">
                {t('weeklies.consistency.title')}
            </h3>
            {cells.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('weeklies.consistency.empty')}
                </p>
            ) : (
                <ol className="flex flex-wrap gap-1">
                    {cells.map((cell) => {
                        const text = t('weeklies.consistency.cell', {
                            week: cell.label,
                            state: t(
                                `weeklies.consistency.state.${cell.state}`,
                            ),
                        });

                        return (
                            <li key={cell.cycle_id}>
                                <Link
                                    href={showCycle.url(cell.cycle_id)}
                                    title={text}
                                    aria-label={text}
                                    className={cn(
                                        'block size-6',
                                        TONE[cell.state],
                                        FOCUS_RING,
                                    )}
                                    data-state={cell.state}
                                />
                            </li>
                        );
                    })}
                </ol>
            )}
            <ul
                className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground"
                aria-label={t('weeklies.consistency.legend')}
            >
                {LEGEND.map((state) => (
                    <li key={state} className="inline-flex items-center gap-1">
                        <span
                            aria-hidden="true"
                            className={cn('inline-block size-3', TONE[state])}
                        />
                        {t(`weeklies.consistency.state.${state}`)}
                    </li>
                ))}
            </ul>
        </section>
    );
}
