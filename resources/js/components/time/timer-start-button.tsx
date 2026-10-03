import { Play } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { TaskPicker } from './task-picker';
import { startTimer } from './timer-actions';

/**
 * «Iniciar temporizador» de la cabecera (SPEC §7): abre el buscador de tareas donde se puede
 * imputar y, al elegir una, arranca el temporizador sin tener que ir antes a la tarea. Se para
 * desde el chip de la cabecera. Los errores al iniciar llegan como avisos (timer-actions).
 */
export function TimerStartButton() {
    const [processing, setProcessing] = useState(false);
    const label = t('hours.header.start_timer');

    return (
        <TaskPicker
            value={null}
            onChange={(task) =>
                startTimer(task.id, {
                    onStart: () => setProcessing(true),
                    onFinish: () => setProcessing(false),
                })
            }
            trigger={
                <Button
                    type="button"
                    variant="outline"
                    className="shrink-0 px-2.5 sm:px-3"
                    disabled={processing}
                    aria-label={label}
                    aria-busy={processing || undefined}
                    title={label}
                    data-test="header-start-timer"
                >
                    {processing ? (
                        <Spinner
                            aria-hidden="true"
                            role={undefined}
                            aria-label={undefined}
                        />
                    ) : (
                        <Play aria-hidden="true" />
                    )}
                    <span className="hidden md:inline">{label}</span>
                </Button>
            }
        />
    );
}
