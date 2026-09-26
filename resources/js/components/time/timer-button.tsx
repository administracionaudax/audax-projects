import { router, usePage } from '@inertiajs/react';
import { Play, Square } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';

/**
 * Botón de temporizador de una tarea (SPEC §6 y §7): play si no está en marcha en esta tarea;
 * stop si lo está. Iniciar otro para el anterior y lo imputa (D-036).
 *
 * Contrato: lo usan Tareas, Mis tareas e Inicio. Su implementación completa (errores de bolsa
 * `block`, diálogo al parar, aviso de más de X h) es del área Horas (Agente D).
 */
export function TimerButton({
    task,
    className,
    size = 'icon',
}: {
    task: { id: number; title: string };
    className?: string;
    size?: 'icon' | 'sm';
}) {
    const timer = usePage().props.timer ?? null;
    const running = timer?.task_id === task.id;
    const label = t(running ? 'timer.stop' : 'timer.start', {
        task: task.title,
    });

    const toggle = () => {
        if (running) {
            router.post(urls.timerStop(), {}, { preserveScroll: true });
        } else {
            router.post(
                urls.timerStart(),
                { task_id: task.id },
                { preserveScroll: true },
            );
        }
    };

    return (
        <Button
            type="button"
            variant={running ? 'default' : 'ghost'}
            size={size}
            onClick={toggle}
            aria-label={label}
            aria-pressed={running}
            title={label}
            className={cn(size === 'icon' && 'size-8', className)}
        >
            {running ? (
                <Square aria-hidden="true" className="fill-current" />
            ) : (
                <Play aria-hidden="true" />
            )}
        </Button>
    );
}
