import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { isoWeek } from '@/lib/week';
import { index as timeIndex } from '@/routes/time';
import type { ClockSummary } from '@/types/people';

/** Minutos que faltan por imputar (0 si ya está todo o hay más imputado que trabajado). */
export function missingMinutes(summary: ClockSummary): number {
    return Math.max(summary.worked_minutes - summary.logged_minutes, 0);
}

/**
 * Al fichar la salida (PLAN-FASE-11 §3.2.5; D-340), solo a la propia persona: «Hoy has trabajado
 * 7:50 y has imputado 6:10», con «Imputar lo que falta» que lleva a sus horas de la semana. Es una
 * ayuda, no un control: nadie más lo ve y nunca se imputa ni se ficha solo.
 */
export function useClockSummary(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const summary = flash?.clock_summary as ClockSummary | undefined;

            if (!summary || typeof summary.worked_minutes !== 'number') {
                return;
            }

            const missing = missingMinutes(summary);

            if (missing <= 0) {
                return;
            }

            toast(
                t('people.summary.title', {
                    worked: formatMinutes(summary.worked_minutes),
                    logged: formatMinutes(summary.logged_minutes),
                }),
                {
                    description: t('people.summary.missing', {
                        missing: formatMinutes(missing),
                    }),
                    duration: 12_000,
                    action: {
                        label: t('people.summary.action'),
                        onClick: () =>
                            router.visit(
                                timeIndex.url({
                                    query: { semana: isoWeek(summary.date) },
                                }),
                            ),
                    },
                },
            );
        });
    }, []);
}
