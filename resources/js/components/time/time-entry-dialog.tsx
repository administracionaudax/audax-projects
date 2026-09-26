import { router } from '@inertiajs/react';
import { CircleAlert, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useRequiredUser } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { todayInMadrid } from '@/lib/week';
import { destroy, store, update } from '@/routes/time/entries';
import type { TimeEntry } from '@/types';
import { TaskPicker } from './task-picker';
import type { PickedTask } from './task-picker';
import { useEntryOptions } from './use-entry-options';

export type TimeEntryDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Tarea preseleccionada (desde el panel de tarea o Mis tareas). */
    task?: { id: number; title: string; project_id: number } | null;
    /** Entrada a editar (si no, se crea una nueva). */
    entry?: TimeEntry | null;
    /** Fecha propuesta "YYYY-MM-DD" (por defecto, hoy en Madrid). */
    date?: string;
    /** Duración propuesta en minutos (p. ej. la escrita en la hoja semanal). */
    minutes?: number | null;
    /** Imputar en nombre de otra persona (gestores, responsables y admin, SPEC §7). */
    userId?: number;
};

type Errors = Partial<
    Record<
        | 'task_id'
        | 'user_id'
        | 'date'
        | 'minutes'
        | 'description'
        | 'is_billable'
        | 'general',
        string
    >
>;

const FIELDS = [
    'task_id',
    'user_id',
    'date',
    'minutes',
    'description',
    'is_billable',
] as const;

/** Errores del servidor por campo; los que no son de un campo del formulario van arriba. */
function mapErrors(errors: Record<string, string>): Errors {
    const mapped: Errors = {};
    const general: string[] = [];

    for (const [key, message] of Object.entries(errors)) {
        if ((FIELDS as readonly string[]).includes(key)) {
            mapped[key as (typeof FIELDS)[number]] = message;
        } else {
            general.push(message);
        }
    }

    if (general.length > 0) {
        mapped.general = general.join(' ');
    }

    return mapped;
}

function initialTask(
    entry: TimeEntry | null | undefined,
    task: TimeEntryDialogProps['task'],
): PickedTask | null {
    if (entry) {
        return {
            id: entry.task_id,
            title: entry.task?.title ?? '',
            project_id: entry.project_id,
            project: entry.project
                ? { ...entry.project, is_internal: false }
                : undefined,
        };
    }

    return task ? { ...task } : null;
}

/**
 * Diálogo de entrada manual de horas (SPEC §7): tarea, fecha, duración, descripción, persona (si
 * puede imputar por otros) y facturable. Crea, edita y borra con TimeEntryWriter; los errores
 * llegan por campo y los avisos (exceso, jornada, tarea completada) como toasts.
 *
 * Contrato: lo usan el panel de tarea (Agente C), Inicio, la cabecera y la hoja semanal.
 */
export function TimeEntryDialog({
    open,
    onOpenChange,
    task,
    entry,
    date,
    minutes,
    userId,
}: TimeEntryDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-lg">
                {open ? (
                    <TimeEntryForm
                        task={task}
                        entry={entry}
                        date={date}
                        minutes={minutes}
                        userId={userId}
                        onDone={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function TimeEntryForm({
    task,
    entry,
    date,
    minutes: proposedMinutes,
    userId,
    onDone,
}: Omit<TimeEntryDialogProps, 'open' | 'onOpenChange'> & {
    onDone: () => void;
}) {
    const user = useRequiredUser();
    const id = useId();
    const editing = Boolean(entry);

    const [pickedTask, setPickedTask] = useState<PickedTask | null>(() =>
        initialTask(entry, task),
    );
    const [day, setDay] = useState<string | null>(
        entry?.date ?? date ?? todayInMadrid(),
    );
    const [minutes, setMinutes] = useState<number | null>(
        proposedMinutes ?? entry?.minutes ?? null,
    );
    const [description, setDescription] = useState(entry?.description ?? '');
    const [personId, setPersonId] = useState<number>(
        entry?.user_id ?? userId ?? user.id,
    );
    // null = lo que diga la tarea (se hereda en el servidor).
    const [billable, setBillable] = useState<boolean | null>(
        entry ? entry.is_billable : null,
    );
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const options = useEntryOptions(pickedTask?.project_id ?? null, true);
    const people = options?.people ?? [];
    const settings = options?.settings;
    const internal = pickedTask?.project?.is_internal === true;
    const billableChecked = internal
        ? false
        : (billable ?? pickedTask?.is_billable ?? true);
    const showPeople = !editing && (people.length > 1 || personId !== user.id);

    const visitOptions = {
        preserveScroll: true,
        preserveState: true,
        errorBag: 'timeEntry',
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onError: (serverErrors: Record<string, string>) =>
            setErrors(mapErrors(serverErrors)),
        onSuccess: () => onDone(),
    };

    const validate = (): Errors => {
        const found: Errors = {};

        if (!pickedTask) {
            found.task_id = t('hours.dialog.errors.task');
        }
        if (!day) {
            found.date = t('hours.dialog.errors.date');
        }
        if (minutes === null) {
            found.minutes = t('hours.dialog.errors.minutes');
        }
        if (settings?.description_required && description.trim() === '') {
            found.description = t('hours.dialog.errors.description');
        }

        return found;
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const found = validate();
        setErrors(found);

        if (Object.keys(found).length > 0 || !pickedTask || !day) {
            return;
        }

        const data = {
            task_id: pickedTask.id,
            user_id: personId,
            date: day,
            minutes,
            description: description.trim() === '' ? null : description.trim(),
            ...(billable !== null && !internal
                ? { is_billable: billable }
                : {}),
        };

        if (entry) {
            router.put(update.url(entry.id), data, visitOptions);
        } else {
            router.post(store.url(), data, visitOptions);
        }
    };

    const remove = () => {
        if (!entry) {
            return;
        }

        router.delete(destroy.url(entry.id), visitOptions);
    };

    const field = (name: string) => `${id}-${name}`;

    return (
        <form onSubmit={submit} noValidate className="grid gap-5">
            <DialogHeader>
                <DialogTitle>
                    {editing
                        ? t('hours.dialog.edit_title')
                        : t('time_entry_dialog.title')}
                </DialogTitle>
                <DialogDescription>
                    {t('hours.dialog.description')}
                </DialogDescription>
            </DialogHeader>

            {errors.general ? (
                <Alert variant="destructive" role="alert">
                    <CircleAlert aria-hidden="true" />
                    <AlertDescription>{errors.general}</AlertDescription>
                </Alert>
            ) : null}

            {showPeople ? (
                <div className="grid gap-2">
                    <Label htmlFor={field('person')}>
                        {t('hours.dialog.person')}
                    </Label>
                    <Select
                        value={String(personId)}
                        onValueChange={(value) => {
                            setPersonId(Number(value));
                            setPickedTask(null);
                        }}
                    >
                        <SelectTrigger
                            id={field('person')}
                            className="w-full"
                            aria-invalid={errors.user_id ? true : undefined}
                            aria-describedby={
                                errors.user_id
                                    ? field('person-error')
                                    : undefined
                            }
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {people.length === 0 ? (
                                <SelectItem value={String(personId)}>
                                    {personId === user.id
                                        ? user.name
                                        : t('hours.dialog.person_other')}
                                </SelectItem>
                            ) : null}
                            {people.map((person) => (
                                <SelectItem
                                    key={person.id}
                                    value={String(person.id)}
                                >
                                    {person.id === user.id
                                        ? t('hours.dialog.me', {
                                              name: person.name,
                                          })
                                        : person.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError
                        id={field('person-error')}
                        message={errors.user_id}
                    />
                </div>
            ) : null}

            <div className="grid gap-2">
                <Label htmlFor={field('task')}>{t('hours.dialog.task')}</Label>
                <TaskPicker
                    id={field('task')}
                    value={pickedTask}
                    onChange={(picked) => {
                        setPickedTask(picked);
                        setErrors((current) => ({
                            ...current,
                            task_id: undefined,
                        }));
                    }}
                    userId={personId !== user.id ? personId : undefined}
                    invalid={Boolean(errors.task_id)}
                    aria-describedby={
                        errors.task_id ? field('task-error') : undefined
                    }
                />
                <InputError id={field('task-error')} message={errors.task_id} />
            </div>

            <div className="grid gap-5 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('date')}>
                        {t('hours.dialog.date')}
                    </Label>
                    <DatePicker
                        id={field('date')}
                        value={day}
                        onChange={setDay}
                        clearable={false}
                        max={
                            settings?.allow_future
                                ? undefined
                                : (settings?.today ?? todayInMadrid())
                        }
                        invalid={Boolean(errors.date)}
                    />
                    <InputError
                        id={field('date-error')}
                        message={errors.date}
                    />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor={field('minutes')}>
                        {t('hours.dialog.duration')}
                    </Label>
                    <DurationInput
                        id={field('minutes')}
                        value={minutes}
                        onChange={setMinutes}
                        invalid={Boolean(errors.minutes)}
                        aria-describedby={
                            errors.minutes ? field('minutes-error') : undefined
                        }
                    />
                    <InputError
                        id={field('minutes-error')}
                        message={errors.minutes}
                    />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor={field('description')}>
                    {settings?.description_required
                        ? t('hours.dialog.description_label_required')
                        : t('hours.dialog.description_label')}
                </Label>
                <Textarea
                    id={field('description')}
                    value={description}
                    onChange={(event) => setDescription(event.target.value)}
                    maxLength={2000}
                    rows={3}
                    placeholder={t('hours.dialog.description_placeholder')}
                    aria-invalid={errors.description ? true : undefined}
                    aria-describedby={
                        errors.description
                            ? field('description-error')
                            : undefined
                    }
                />
                <InputError
                    id={field('description-error')}
                    message={errors.description}
                />
            </div>

            <div className="flex items-start gap-3">
                <Switch
                    id={field('billable')}
                    checked={billableChecked}
                    disabled={internal}
                    onCheckedChange={(checked) => setBillable(checked)}
                    aria-describedby={field('billable-help')}
                />
                <div className="grid gap-1">
                    <Label htmlFor={field('billable')}>
                        {t('hours.dialog.billable')}
                    </Label>
                    <p
                        id={field('billable-help')}
                        className="text-xs text-muted-foreground"
                    >
                        {internal
                            ? t('hours.dialog.billable_internal')
                            : t('hours.dialog.billable_help')}
                    </p>
                    <InputError message={errors.is_billable} />
                </div>
            </div>

            <DialogFooter className="gap-2 sm:justify-between">
                {editing ? (
                    confirmDelete ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm">
                                {t('hours.dialog.delete_confirm')}
                            </span>
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                disabled={processing}
                                onClick={remove}
                            >
                                {t('common.delete')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setConfirmDelete(false)}
                            >
                                {t('common.cancel')}
                            </Button>
                        </div>
                    ) : (
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={processing}
                            onClick={() => setConfirmDelete(true)}
                        >
                            <Trash2 aria-hidden="true" />
                            {t('hours.dialog.delete')}
                        </Button>
                    )
                ) : (
                    <span />
                )}
                <div className="flex flex-col-reverse gap-2 sm:flex-row">
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
                        {editing
                            ? t('hours.dialog.save')
                            : t('hours.dialog.create')}
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}
