import { router } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
import {
    AssigneePicker,
    BankSelect,
    PrioritySelect,
    StatusSelect,
    TypeSelect,
} from '@/components/tasks/task-fields';
import { defaultBankId, useTaskLookups } from '@/components/tasks/task-lookups';
import { MAX_ESTIMATE_MINUTES } from '@/components/tasks/task-panel-fields';
import { TASK_RELOAD } from '@/components/tasks/task-requests';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import { store as storeTask } from '@/routes/tasks';
import type { Task, TaskPriority } from '@/types';

/** Tarea padre de la que hereda los valores por defecto (D-163). */
export type TaskCreateParent = Pick<
    Task,
    | 'id'
    | 'title'
    | 'assignee_user_id'
    | 'task_type_id'
    | 'priority'
    | 'due_date'
>;

/** Valores de partida de una tarea raíz (la columna, el grupo o lo escrito en el alta rápida). */
export type TaskCreateDefaults = Partial<{
    title: string;
    status_id: number;
    assignee_user_id: number | null;
    hour_bank_id: number | null;
    task_type_id: number | null;
}>;

type Field =
    | 'title'
    | 'assignee_user_id'
    | 'status_id'
    | 'priority'
    | 'task_type_id'
    | 'hour_bank_id'
    | 'start_date'
    | 'due_date'
    | 'estimated_minutes';

type Errors = Partial<Record<Field | 'general', string>>;

const FIELDS: readonly Field[] = [
    'title',
    'assignee_user_id',
    'status_id',
    'priority',
    'task_type_id',
    'hour_bank_id',
    'start_date',
    'due_date',
    'estimated_minutes',
];

function mapErrors(errors: Record<string, string>): Errors {
    const mapped: Errors = {};
    const general: string[] = [];

    for (const [key, message] of Object.entries(errors)) {
        if ((FIELDS as readonly string[]).includes(key)) {
            mapped[key as Field] = message;
        } else {
            general.push(message);
        }
    }

    if (general.length > 0) {
        mapped.general = general.join(' ');
    }

    return mapped;
}

/**
 * Crear una tarea o una subtarea con sus datos (D-163): título, responsable, estado, prioridad,
 * tipo, inicio, entrega y horas estimadas (y la bolsa en una tarea raíz de un proyecto de bolsas).
 * Una subtarea hereda del padre el responsable, el tipo, la prioridad y la entrega; su bolsa es
 * siempre la del padre (D-037). Intro guarda; con «Crear otra al guardar» el diálogo sigue abierto,
 * vacía el título y la estimación y conserva lo demás para la siguiente.
 */
export function TaskCreateDialog({
    open,
    onOpenChange,
    parent,
    defaults,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    parent?: TaskCreateParent | null;
    defaults?: TaskCreateDefaults;
    /** Tras crear cada tarea (p. ej. para vaciar el alta rápida de la que vino). */
    onCreated?: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg"
                data-test="task-create-dialog"
            >
                {open ? (
                    <TaskCreateForm
                        parent={parent ?? null}
                        defaults={defaults ?? {}}
                        onCreated={onCreated}
                        onDone={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function TaskCreateForm({
    parent,
    defaults,
    onCreated,
    onDone,
}: {
    parent: TaskCreateParent | null;
    defaults: TaskCreateDefaults;
    onCreated?: () => void;
    onDone: () => void;
}) {
    const lookups = useTaskLookups();
    const id = useId();
    const titleInput = useRef<HTMLInputElement>(null);
    const defaultStatus =
        lookups.statuses.find((status) => status.is_default) ??
        lookups.statuses[0];

    const [title, setTitle] = useState(defaults.title ?? '');
    const [assigneeId, setAssigneeId] = useState<number | null>(
        parent ? parent.assignee_user_id : (defaults.assignee_user_id ?? null),
    );
    const [statusId, setStatusId] = useState<number | null>(
        defaults.status_id ?? defaultStatus?.id ?? null,
    );
    const [priority, setPriority] = useState<TaskPriority>(
        parent?.priority ?? 'normal',
    );
    const [typeId, setTypeId] = useState<number | null>(
        parent ? parent.task_type_id : (defaults.task_type_id ?? null),
    );
    const needsBank =
        parent === null &&
        lookups.usesBanks &&
        (defaults.hour_bank_id === undefined || defaults.hour_bank_id === null);
    const [bankId, setBankId] = useState<number | null>(() =>
        defaultBankId(lookups.banks, lookups.currentUser.department_id),
    );
    const [startDate, setStartDate] = useState<string | null>(null);
    const [dueDate, setDueDate] = useState<string | null>(
        parent?.due_date ?? null,
    );
    const [estimate, setEstimate] = useState<number | null>(null);
    const [another, setAnother] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [created, setCreated] = useState<string | null>(null);

    const field = (name: string) => `${id}-${name}`;

    const validate = (): Errors => {
        const found: Errors = {};

        if (title.trim() === '') {
            found.title = t('task_create.errors.title');
        }
        if (needsBank && bankId === null) {
            found.hour_bank_id = t('quick_add.bank_required');
        }
        if (startDate && dueDate && startDate > dueDate) {
            found.due_date = t('task_create.errors.dates');
        }

        return found;
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (processing) {
            return;
        }

        const found = validate();
        setErrors(found);

        if (Object.keys(found).length > 0) {
            return;
        }

        const value = title.trim();

        router.post(
            storeTask.url(lookups.project.id),
            {
                title: value,
                status_id: statusId,
                priority,
                assignee_user_id: assigneeId,
                task_type_id: typeId,
                start_date: startDate,
                due_date: dueDate,
                estimated_minutes: estimate,
                ...(parent
                    ? { parent_task_id: parent.id }
                    : needsBank
                      ? { hour_bank_id: bankId }
                      : defaults.hour_bank_id !== undefined
                        ? { hour_bank_id: defaults.hour_bank_id }
                        : {}),
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: TASK_RELOAD,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    onCreated?.();

                    if (!another) {
                        onDone();

                        return;
                    }

                    // Siguiente: título y estimación vacíos; lo demás se conserva.
                    setTitle('');
                    setEstimate(null);
                    setErrors({});
                    setCreated(value);
                    titleInput.current?.focus();
                },
                onError: (serverErrors) =>
                    setErrors(
                        mapErrors(serverErrors as Record<string, string>),
                    ),
                onHttpException: () => {
                    setErrors({ general: t('task_errors.server') });

                    return false;
                },
                onNetworkError: () => {
                    setErrors({ general: t('task_errors.network') });

                    return false;
                },
            },
        );
    };

    const isSubtask = parent !== null;

    return (
        <form onSubmit={submit} noValidate className="grid gap-4">
            <DialogHeader>
                <DialogTitle>
                    {isSubtask
                        ? t('task_create.subtask_title')
                        : t('task_create.title')}
                </DialogTitle>
                <DialogDescription>
                    {isSubtask
                        ? t('task_create.subtask_description', {
                              task: parent.title,
                          })
                        : t('task_create.description')}
                </DialogDescription>
            </DialogHeader>

            {errors.general ? (
                <Alert variant="destructive" role="alert">
                    <CircleAlert aria-hidden="true" />
                    <AlertDescription>{errors.general}</AlertDescription>
                </Alert>
            ) : null}

            {/* Tras «crear y añadir otra»: confirmación que se anuncia sin mover el foco. */}
            <p className="sr-only" role="status" aria-live="polite">
                {created ? t('task_create.created', { task: created }) : ''}
            </p>

            <div className="grid gap-2">
                <Label htmlFor={field('title')}>
                    {t('task_create.field.title')}
                </Label>
                <Input
                    ref={titleInput}
                    id={field('title')}
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                    maxLength={255}
                    autoComplete="off"
                    autoFocus
                    aria-invalid={errors.title ? true : undefined}
                    aria-describedby={
                        errors.title ? field('title-error') : undefined
                    }
                    data-test="task-create-title"
                />
                <InputError id={field('title-error')} message={errors.title} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('assignee')}>
                        {t('task_panel.assignee')}
                    </Label>
                    <AssigneePicker
                        id={field('assignee')}
                        value={assigneeId}
                        onChange={setAssigneeId}
                    />
                    <InputError message={errors.assignee_user_id} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('type')}>
                        {t('task_panel.type')}
                    </Label>
                    <TypeSelect
                        id={field('type')}
                        value={typeId}
                        onChange={setTypeId}
                    />
                    <InputError message={errors.task_type_id} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('start')}>
                        {t('task_panel.start_date')}
                    </Label>
                    <DatePicker
                        id={field('start')}
                        value={startDate}
                        onChange={setStartDate}
                        invalid={Boolean(errors.start_date)}
                    />
                    <InputError message={errors.start_date} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('due')}>
                        {t('task_panel.due_date')}
                    </Label>
                    <DatePicker
                        id={field('due')}
                        value={dueDate}
                        onChange={setDueDate}
                        invalid={Boolean(errors.due_date)}
                    />
                    <InputError message={errors.due_date} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('estimate')}>
                        {t('task_create.field.estimate')}
                    </Label>
                    <DurationInput
                        id={field('estimate')}
                        value={estimate}
                        onChange={setEstimate}
                        max={MAX_ESTIMATE_MINUTES}
                        invalid={Boolean(errors.estimated_minutes)}
                        aria-describedby={field('estimate-help')}
                    />
                    <p id={field('estimate-help')} className="sr-only">
                        {t('task_panel.estimate_help')}
                    </p>
                    <InputError message={errors.estimated_minutes} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('priority')}>
                        {t('task_panel.priority')}
                    </Label>
                    <PrioritySelect
                        id={field('priority')}
                        value={priority}
                        onChange={setPriority}
                    />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('status')}>
                        {t('task_panel.status')}
                    </Label>
                    <StatusSelect
                        id={field('status')}
                        value={statusId}
                        onChange={setStatusId}
                    />
                    <InputError message={errors.status_id} />
                </div>
                {needsBank ? (
                    <div className="grid content-start gap-2">
                        <Label htmlFor={field('bank')}>
                            {t('task_panel.bank')}
                        </Label>
                        <BankSelect
                            id={field('bank')}
                            value={bankId}
                            onChange={(value) => {
                                setBankId(value);
                                setErrors((current) => ({
                                    ...current,
                                    hour_bank_id: undefined,
                                }));
                            }}
                        />
                        <InputError message={errors.hour_bank_id} />
                    </div>
                ) : null}
            </div>

            <div className="flex items-center gap-3">
                <Switch
                    id={field('another')}
                    checked={another}
                    onCheckedChange={setAnother}
                />
                <Label htmlFor={field('another')} className="font-normal">
                    {t('task_create.another')}
                </Label>
            </div>

            <DialogFooter className="gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    disabled={processing}
                    onClick={onDone}
                >
                    {t('common.cancel')}
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing ? <Spinner /> : null}
                    {isSubtask
                        ? t('task_create.submit_subtask')
                        : t('task_create.submit')}
                </Button>
            </DialogFooter>
        </form>
    );
}
