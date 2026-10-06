import { router } from '@inertiajs/react';
import { Save, Undo2 } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
import { PersonLoadPicker } from '@/components/workload/person-load-picker';
import type {
    WorkloadExtraPerson,
    WorkloadPerson,
    WorkloadTask,
} from '@/components/workload/types';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { update } from '@/routes/workload/tasks';

/** Estimación máxima: 999 h (App\Http\Requests\Tasks\TaskFieldRules::MAX_ESTIMATE_MINUTES). */
export const MAX_ESTIMATE_MINUTES = 999 * 60;

export type EditorField = 'assignee' | 'dates' | 'estimate';

type Values = {
    assignee_user_id: number | null;
    start_date: string | null;
    due_date: string | null;
    estimated_minutes: number | null;
};

type Field = keyof Values;

function valuesOf(task: WorkloadTask): Values {
    return {
        assignee_user_id: task.assignee_id,
        start_date: task.start_date,
        due_date: task.due_date,
        estimated_minutes: task.estimated_minutes,
    };
}

/** Solo lo que ha cambiado (PATCH parcial: el servidor no toca lo que no llega). */
export function changedValues(
    task: WorkloadTask,
    values: Values,
): Partial<Values> {
    const original = valuesOf(task);
    const changes: Partial<Values> = {};

    for (const key of Object.keys(values) as Field[]) {
        if (values[key] !== original[key]) {
            Object.assign(changes, { [key]: values[key] });
        }
    }

    return changes;
}

/**
 * Clave para volver a montar el editor cuando el servidor devuelve la tarea cambiada (tras
 * guardar, la matriz y el panel se recalculan con los datos nuevos).
 */
export function editorKey(task: WorkloadTask): string {
    return [
        task.id,
        task.assignee_id,
        task.start_date,
        task.due_date,
        task.estimated_minutes,
    ].join(':');
}

/**
 * Reasignar y replanificar una tarea desde la vista Carga (D-052): responsable (solo si puede
 * repartirla), inicio, entrega y estimación. Guarda con PATCH /carga/tareas/{id}: TaskPolicy, el
 * alcance de la vista y TaskWriter en el servidor; al volver, la matriz se recalcula (visita de
 * Inertia a la misma URL, sin perder el scroll ni el panel abierto).
 */
export function WorkloadTaskEditor({
    task,
    fields = ['assignee', 'dates', 'estimate'],
    people,
    extraPeople = [],
    submitLabel,
    onSaved,
    className,
}: {
    task: WorkloadTask;
    fields?: EditorField[];
    /** Personas del alcance con su carga (se filtran por task.assignee_ids). */
    people: WorkloadPerson[];
    extraPeople?: WorkloadExtraPerson[];
    submitLabel?: string;
    /** Tras guardar (la tarea puede haber salido de la lista): el foco va a un sitio estable. */
    onSaved?: () => void;
    className?: string;
}) {
    const id = useId();
    const [values, setValues] = useState<Values>(() => valuesOf(task));
    const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
    const [processing, setProcessing] = useState(false);
    const changes = changedValues(task, values);
    const dirty = Object.keys(changes).length > 0;
    const allowed = new Set(task.assignee_ids ?? []);
    const canReassign = task.assignee_ids !== null;
    const showAssignee = fields.includes('assignee') && canReassign;
    const team = people.filter((person) => allowed.has(person.id));
    const others = extraPeople.filter(
        (person) =>
            allowed.has(person.id) && !team.some((p) => p.id === person.id),
    );

    const set = <K extends Field>(key: K, value: Values[K]) => {
        setValues((current) => ({ ...current, [key]: value }));
        setErrors((current) => ({ ...current, [key]: undefined }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!dirty || processing) {
            return;
        }

        router.patch(update.url(task.id), changes, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onSaved?.(),
            onError: (received) => {
                setErrors(received as Partial<Record<Field, string>>);
                const first = Object.values(received)[0];
                toast.error(first ?? t('workload_edit.error_generic'));
            },
            onHttpException: (response) => {
                toast.error(
                    response.status === 403
                        ? t('workload_edit.error_forbidden')
                        : t('workload_edit.error_server'),
                );

                return false;
            },
            onNetworkError: () => {
                toast.error(t('workload_edit.error_network'));

                return false;
            },
        });
    };

    const describe = (field: Field) =>
        errors[field] ? `${id}-${field}-error` : undefined;

    return (
        <form
            onSubmit={submit}
            className={cn('grid gap-3', className)}
            aria-label={t('workload_edit.form_label', { task: task.title })}
            data-test="workload-task-editor"
        >
            <div className="grid gap-3 sm:grid-cols-2">
                {showAssignee ? (
                    <div className="grid content-start gap-1 sm:col-span-2">
                        <Label htmlFor={`${id}-assignee`}>
                            {t('workload_edit.assignee')}
                        </Label>
                        <PersonLoadPicker
                            id={`${id}-assignee`}
                            value={values.assignee_user_id}
                            onChange={(value) => set('assignee_user_id', value)}
                            team={team}
                            others={others}
                            allowNone
                            invalid={errors.assignee_user_id !== undefined}
                            aria-describedby={describe('assignee_user_id')}
                            disabled={processing}
                        />
                        <InputError
                            id={`${id}-assignee_user_id-error`}
                            message={errors.assignee_user_id}
                        />
                    </div>
                ) : null}

                {fields.includes('dates') ? (
                    <>
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-start`}>
                                {t('workload_edit.start')}
                            </Label>
                            <DatePicker
                                id={`${id}-start`}
                                value={values.start_date}
                                onChange={(value) => set('start_date', value)}
                                invalid={errors.start_date !== undefined}
                                disabled={processing}
                                aria-label={t('workload_edit.start_value', {
                                    date: values.start_date
                                        ? formatDate(values.start_date)
                                        : t('workload_edit.no_date'),
                                })}
                            />
                            <InputError message={errors.start_date} />
                        </div>
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-due`}>
                                {t('workload_edit.due')}
                            </Label>
                            <DatePicker
                                id={`${id}-due`}
                                value={values.due_date}
                                onChange={(value) => set('due_date', value)}
                                invalid={errors.due_date !== undefined}
                                disabled={processing}
                                aria-label={t('workload_edit.due_value', {
                                    date: values.due_date
                                        ? formatDate(values.due_date)
                                        : t('workload_edit.no_date'),
                                })}
                            />
                            <InputError message={errors.due_date} />
                        </div>
                    </>
                ) : null}

                {fields.includes('estimate') ? (
                    <div className="grid content-start gap-1">
                        <Label htmlFor={`${id}-estimate`}>
                            {t('workload_edit.estimate')}
                        </Label>
                        <DurationInput
                            id={`${id}-estimate`}
                            value={values.estimated_minutes}
                            onChange={(value) =>
                                set('estimated_minutes', value)
                            }
                            max={MAX_ESTIMATE_MINUTES}
                            invalid={errors.estimated_minutes !== undefined}
                            disabled={processing}
                            aria-describedby={describe('estimated_minutes')}
                        />
                        <InputError
                            id={`${id}-estimated_minutes-error`}
                            message={errors.estimated_minutes}
                        />
                    </div>
                ) : null}
            </div>

            {fields.includes('assignee') && !canReassign ? (
                <p className="text-xs text-muted-foreground">
                    {t('workload_edit.cannot_reassign')}
                </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" size="sm" disabled={!dirty || processing}>
                    {processing ? (
                        <Spinner aria-hidden="true" />
                    ) : (
                        <Save aria-hidden="true" />
                    )}
                    {processing
                        ? t('workload_edit.saving')
                        : (submitLabel ?? t('workload_edit.save'))}
                </Button>
                {dirty && !processing ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            setValues(valuesOf(task));
                            setErrors({});
                        }}
                    >
                        <Undo2 aria-hidden="true" />
                        {t('workload_edit.reset')}
                    </Button>
                ) : null}
            </div>
        </form>
    );
}
