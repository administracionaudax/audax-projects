import { router } from '@inertiajs/react';
import { CircleAlert, Plus } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { BankSelect } from '@/components/tasks/task-fields';
import { defaultBankId, useTaskLookups } from '@/components/tasks/task-lookups';
import { TASK_RELOAD } from '@/components/tasks/task-requests';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { store as storeTask } from '@/routes/tasks';

export type QuickAddDefaults = Partial<{
    status_id: number;
    assignee_user_id: number | null;
    hour_bank_id: number | null;
    task_type_id: number | null;
}>;

/**
 * Creación rápida en línea (SPEC §6): escribe el título, pulsa Intro y sigue escribiendo la
 * siguiente. En los proyectos de bolsas pide la bolsa (primero las del departamento del usuario,
 * SPEC §8.3) salvo que venga dada (agrupado por bolsa o subtarea, que usa la del padre).
 */
export function QuickAddTask({
    defaults = {},
    parentId,
    label,
    className,
    compact = false,
}: {
    defaults?: QuickAddDefaults;
    /** Crea una subtarea de esta tarea. */
    parentId?: number;
    /** Nombre accesible del campo (p. ej. «Nueva tarea en Por hacer»). */
    label: string;
    className?: string;
    compact?: boolean;
}) {
    const lookups = useTaskLookups();
    const inputId = useId();
    const errorId = useId();
    const input = useRef<HTMLInputElement>(null);
    const [title, setTitle] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const needsBank =
        lookups.usesBanks &&
        parentId === undefined &&
        (defaults.hour_bank_id === undefined || defaults.hour_bank_id === null);
    const [bankId, setBankId] = useState<number | null>(() =>
        defaultBankId(lookups.banks, lookups.currentUser.department_id),
    );

    const submit = () => {
        const value = title.trim();

        if (value === '' || processing) {
            return;
        }

        if (needsBank && bankId === null) {
            setError(t('quick_add.bank_required'));

            return;
        }

        router.post(
            storeTask.url(lookups.project.id),
            {
                ...defaults,
                title: value,
                ...(needsBank ? { hour_bank_id: bankId } : {}),
                ...(parentId !== undefined ? { parent_task_id: parentId } : {}),
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: TASK_RELOAD,
                onStart: () => setProcessing(true),
                onSuccess: () => {
                    setTitle('');
                    setError(null);
                },
                onError: (errors) => {
                    setError(
                        Object.values(errors)[0] ?? t('task_errors.generic'),
                    );
                },
                onHttpException: () => {
                    setError(t('task_errors.server'));

                    return false;
                },
                onNetworkError: () => {
                    setError(t('task_errors.network'));

                    return false;
                },
                onFinish: () => {
                    setProcessing(false);
                    input.current?.focus();
                },
            },
        );
    };

    if (!lookups.can.create) {
        return null;
    }

    return (
        <div className={cn('grid gap-1', className)}>
            <form
                className={cn(
                    'flex flex-col gap-2',
                    !compact && 'sm:flex-row sm:items-center',
                )}
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <div className="relative min-w-0 flex-1">
                    <Plus
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <label htmlFor={inputId} className="sr-only">
                        {label}
                    </label>
                    <Input
                        ref={input}
                        id={inputId}
                        value={title}
                        onChange={(event) => setTitle(event.target.value)}
                        placeholder={
                            parentId !== undefined
                                ? t('quick_add.subtask_placeholder')
                                : t('quick_add.placeholder')
                        }
                        maxLength={255}
                        autoComplete="off"
                        aria-invalid={error ? true : undefined}
                        aria-describedby={error ? errorId : undefined}
                        aria-busy={processing || undefined}
                        className="pl-8"
                        data-dirty={title.trim() !== '' ? 'true' : undefined}
                        data-test="quick-add-input"
                    />
                </div>
                {needsBank ? (
                    <BankSelect
                        value={bankId}
                        onChange={(value) => {
                            setBankId(value);
                            setError(null);
                        }}
                        aria-label={t('quick_add.bank_label')}
                        className={cn(!compact && 'sm:w-56')}
                    />
                ) : null}
            </form>
            {error ? (
                <p
                    id={errorId}
                    role="alert"
                    className="flex items-center gap-1.5 text-sm text-destructive-foreground"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="size-4 shrink-0"
                    />
                    {error}
                </p>
            ) : null}
        </div>
    );
}
