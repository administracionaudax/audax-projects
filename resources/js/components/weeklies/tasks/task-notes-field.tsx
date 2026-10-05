import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Textarea } from '@/components/ui/textarea';
import { DictationButton } from '@/components/weeklies/dictation-button';
import { saveTaskNotes } from '@/components/weeklies/weekly-api';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { MySpaceTask } from '@/types/weeklies';

/** Tras dejar de escribir, se guarda (el original guardaba a los 500 ms; aquí algo más, D-203). */
export const NOTES_AUTOSAVE_MS = 1_000;

type SaveState = 'idle' | 'saving' | 'saved' | 'error';

/**
 * Las notas de una tarea (F-060): su descripción en texto plano, editable en línea con autoguardado y
 * con dictado (el Whisper del servidor, como «Mi weekly»). Lo pendiente se guarda al salir del campo
 * y al cerrar la página. Una descripción con formato no se edita aquí (se perdería): se enseña y se
 * enlaza a la tarea (D-203).
 */
export function TaskNotesField({ task }: { task: MySpaceTask }) {
    const id = useId();
    const [value, setValue] = useState(task.notes);
    const [state, setState] = useState<SaveState>('idle');
    const saved = useRef(task.notes);
    const latest = useRef(task.notes);
    const timer = useRef<number | null>(null);
    const mounted = useRef(true);

    const clear = () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }
    };

    const save = useCallback(
        async (keepalive = false) => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
                timer.current = null;
            }

            const text = latest.current;

            if (text === saved.current) {
                return;
            }

            if (mounted.current) {
                setState('saving');
            }

            try {
                await saveTaskNotes(task.id, text, { keepalive });
                saved.current = text;

                if (mounted.current) {
                    setState(latest.current === text ? 'saved' : 'idle');
                }
            } catch {
                if (mounted.current) {
                    setState('error');
                }
            }
        },
        [task.id],
    );

    // Lo que quede pendiente, al cerrar o cambiar de página.
    useEffect(() => {
        mounted.current = true;
        const flush = () => void save(true);
        window.addEventListener('pagehide', flush);

        return () => {
            mounted.current = false;
            window.removeEventListener('pagehide', flush);
            flush();
        };
    }, [save]);

    // Si llega otra versión del servidor y no hay nada sin guardar, se adopta.
    useEffect(() => {
        if (latest.current === saved.current && task.notes !== saved.current) {
            saved.current = task.notes;
            latest.current = task.notes;
            setValue(task.notes);
        }
    }, [task.notes]);

    const change = (text: string) => {
        latest.current = text;
        setValue(text);
        setState('idle');
        clear();
        timer.current = window.setTimeout(() => void save(), NOTES_AUTOSAVE_MS);
    };

    if (!task.notes_editable) {
        return (
            <div className="grid gap-1 text-sm" data-test="task-notes-rich">
                {task.notes ? (
                    <p className="line-clamp-4 whitespace-pre-wrap text-muted-foreground">
                        {task.notes}
                    </p>
                ) : null}
                <p className="text-xs text-muted-foreground">
                    {t('my_space.tasks.notes.rich')}{' '}
                    <Link
                        href={urls.task(task.project.id, task.id)}
                        className={cn(
                            'text-primary-text underline',
                            FOCUS_RING,
                        )}
                    >
                        {t('my_space.tasks.notes.edit_in_task')}
                    </Link>
                </p>
            </div>
        );
    }

    const lines = value === '' ? 1 : Math.min(4, value.split('\n').length + 1);

    return (
        <div className="grid min-w-0 gap-1.5">
            <label htmlFor={id} className="sr-only">
                {t('my_space.tasks.notes.label', { task: task.title })}
            </label>
            <Textarea
                id={id}
                rows={lines}
                value={value}
                placeholder={t('my_space.tasks.notes.placeholder')}
                maxLength={10_000}
                disabled={!task.can.update}
                onChange={(event) => change(event.target.value)}
                onBlur={() => void save()}
                className="min-h-9 resize-none text-sm"
                data-test={`task-notes-${task.id}`}
            />
            {task.can.update ? (
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <DictationButton
                        taskId={task.id}
                        label={t('my_space.tasks.notes.dictate', {
                            task: task.title,
                        })}
                        groupLabel={t('my_space.tasks.notes.dictation_group', {
                            task: task.title,
                        })}
                        onText={(text) =>
                            change(
                                latest.current
                                    ? `${latest.current}\n${text}`
                                    : text,
                            )
                        }
                    />
                    <p
                        aria-live="polite"
                        className={cn(
                            'text-xs',
                            state === 'error'
                                ? 'text-danger'
                                : 'text-muted-foreground',
                        )}
                        data-test="task-notes-state"
                    >
                        {state === 'saving'
                            ? t('my_space.tasks.notes.saving')
                            : state === 'saved'
                              ? t('my_space.tasks.notes.saved')
                              : null}
                        {state === 'error' ? (
                            <>
                                {t('my_space.tasks.notes.error')}{' '}
                                <button
                                    type="button"
                                    className={cn('underline', FOCUS_RING)}
                                    onClick={() => void save()}
                                >
                                    {t('my_space.tasks.notes.retry')}
                                </button>
                            </>
                        ) : null}
                    </p>
                </div>
            ) : null}
        </div>
    );
}
