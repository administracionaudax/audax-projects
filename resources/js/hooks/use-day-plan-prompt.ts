import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { t } from '@/lib/i18n';
import { status } from '@/routes/day-plan/items';

/** Prop flash `day_plan_prompt` de TimerController::stop (D-254). */
export type DayPlanPrompt = { id: number; text: string };

/** Marca la línea como hecha (la acción del aviso). Exportada para los tests. */
export function markLineDone(prompt: DayPlanPrompt): void {
    router.post(
        status.url(prompt.id),
        { status: 'done' },
        { preserveScroll: true, preserveState: true, errorBag: 'dayPlan' },
    );
}

/**
 * Al parar el temporizador de una línea del plan del día aún pendiente (en la cabecera, en Inicio o
 * en Mi día): «¿Das por hecha la línea?» como aviso con la acción «Marcar como hecha» (§4.2.4). No
 * bloquea: si no se pulsa, la línea sigue pendiente.
 */
export function useDayPlanPrompt(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const prompt = flash?.day_plan_prompt as DayPlanPrompt | undefined;

            if (!prompt || typeof prompt.id !== 'number') {
                return;
            }

            toast(t('day_plan.prompt.title', { text: prompt.text }), {
                duration: 10_000,
                action: {
                    label: t('day_plan.prompt.done'),
                    onClick: () => markLineDone(prompt),
                },
            });
        });
    }, []);
}
