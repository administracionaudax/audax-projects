import { usePage } from '@inertiajs/react';
import { Play, Square } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { startTimer, stopTimer } from './timer-actions';

/**
 * Botón de temporizador de una tarea (SPEC §6 y §7): play si no está en marcha en esta tarea;
 * stop si lo está. Iniciar otro para el anterior y lo imputa (D-036).
 *
 * - Mientras responde el servidor, muestra un indicador de carga y no admite otro clic.
 * - Los errores al iniciar (no ser miembro, bolsa `block` sin saldo, semana enviada…) llegan
 *   como avisos; si falla al parar, la cabecera abre el diálogo para ajustar la duración, elegir
 *   otra tarea o descartarlo (D-035).
 * - Si la tarea es un hito (`is_milestone`), el botón está desactivado: los hitos no llevan horas.
 *
 * Contrato: lo usan Tareas, Mis tareas e Inicio.
 */
export function TimerButton({
    task,
    className,
    size = 'icon',
}: {
    task: { id: number; title: string; is_milestone?: boolean };
    className?: string;
    size?: 'icon' | 'sm';
}) {
    const timer = usePage().props.timer ?? null;
    const running = timer?.task_id === task.id;
    const [processing, setProcessing] = useState(false);
    const milestone = task.is_milestone === true;
    const label = milestone
        ? t('hours.timer.milestone')
        : t(running ? 'timer.stop' : 'timer.start', { task: task.title });

    const callbacks = {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    const toggle = () => {
        if (processing || milestone) {
            return;
        }

        if (running) {
            stopTimer(callbacks);
        } else {
            startTimer(task.id, callbacks);
        }
    };

    const Icon = running ? Square : Play;

    return (
        <Button
            type="button"
            variant={running ? 'default' : 'ghost'}
            size={size}
            onClick={toggle}
            disabled={milestone}
            aria-label={label}
            aria-pressed={milestone ? undefined : running}
            aria-busy={processing || undefined}
            title={label}
            className={cn(size === 'icon' && 'size-8', className)}
        >
            {processing ? (
                <Spinner
                    aria-hidden="true"
                    role={undefined}
                    aria-label={undefined}
                />
            ) : (
                <Icon
                    aria-hidden="true"
                    className={running ? 'fill-current' : undefined}
                />
            )}
            {size === 'sm' ? (
                <span>
                    {running
                        ? t('hours.timer.stop_short')
                        : t('hours.timer.start_short')}
                </span>
            ) : null}
        </Button>
    );
}
