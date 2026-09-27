import { CircleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { createTask } from '@/components/gantt/requests';
import { DatePicker } from '@/components/domain/date-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import type { TaskBankOption } from '@/types';

type Errors = Partial<Record<string, string>>;

/**
 * «Nueva tarea» desde el Gantt (D-060): título, inicio y entrega (un hito, solo entrega) y, en los
 * proyectos de bolsas, la bolsa (primero las del departamento del usuario, SPEC §8.3). Usa la ruta
 * de alta de tareas (tasks.store, TaskWriter), la misma que el alta rápida de la lista.
 */
export function NewTaskDialog({
    open,
    onOpenChange,
    projectId,
    usesBanks,
    banks,
    defaultBankId,
    reload,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    usesBanks: boolean;
    banks: ReadonlyArray<TaskBankOption>;
    defaultBankId: number | null;
    reload: string[];
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-md"
                data-test="gantt-new-task-dialog"
            >
                {open ? (
                    <NewTaskForm
                        projectId={projectId}
                        usesBanks={usesBanks}
                        banks={banks}
                        defaultBankId={defaultBankId}
                        reload={reload}
                        onDone={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function NewTaskForm({
    projectId,
    usesBanks,
    banks,
    defaultBankId,
    reload,
    onDone,
}: {
    projectId: number;
    usesBanks: boolean;
    banks: ReadonlyArray<TaskBankOption>;
    defaultBankId: number | null;
    reload: string[];
    onDone: () => void;
}) {
    const id = useId();
    const [title, setTitle] = useState('');
    const [start, setStart] = useState<string | null>(null);
    const [due, setDue] = useState<string | null>(null);
    const [milestone, setMilestone] = useState(false);
    const [bankId, setBankId] = useState<number | null>(defaultBankId);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        const next: Errors = {};

        if (title.trim() === '') {
            next.title = t('gantt.new_task.title_required');
        }

        if (!milestone && start && due && due < start) {
            next.due_date = t('gantt.dates.due_before_start');
        }

        if (milestone && !due) {
            next.due_date = t('gantt.new_task.milestone_due_required');
        }

        if (usesBanks && bankId === null) {
            next.hour_bank_id = t('gantt.new_task.bank_required');
        }

        setErrors(next);

        if (Object.keys(next).length > 0) {
            return;
        }

        setProcessing(true);
        createTask(
            projectId,
            {
                title: title.trim(),
                start_date: milestone ? null : start,
                due_date: due,
                is_milestone: milestone,
                ...(usesBanks ? { hour_bank_id: bankId } : {}),
            },
            reload,
            {
                onSuccess: onDone,
                onFailure: (message, fieldErrors) =>
                    setErrors(
                        fieldErrors && Object.keys(fieldErrors).length > 0
                            ? fieldErrors
                            : { form: message },
                    ),
                // Otra visita la ha interrumpido: no se sabe si se ha creado (se verá en el Gantt).
                onCancel: () =>
                    setErrors({ form: t('gantt.new_task.interrupted') }),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const errorText = (field: string) =>
        errors[field] ? (
            <p
                id={`${id}-${field}-error`}
                role="alert"
                className="flex items-center gap-1.5 text-sm text-destructive-foreground"
            >
                <CircleAlert aria-hidden="true" className="size-4 shrink-0" />
                {errors[field]}
            </p>
        ) : null;

    const openBanks = banks.filter((bank) => bank.is_open);
    const otherErrors = Object.entries(errors).filter(
        ([field]) =>
            !['title', 'start_date', 'due_date', 'hour_bank_id'].includes(
                field,
            ),
    );

    return (
        <form
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <DialogHeader>
                <DialogTitle>{t('gantt.new_task.title')}</DialogTitle>
                <DialogDescription>
                    {t('gantt.new_task.description')}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-title`}>
                    {t('gantt.new_task.name')}
                </Label>
                <Input
                    id={`${id}-title`}
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                    maxLength={255}
                    autoComplete="off"
                    aria-invalid={errors.title ? true : undefined}
                    aria-describedby={
                        errors.title ? `${id}-title-error` : undefined
                    }
                    data-test="gantt-new-task-title"
                />
                {errorText('title')}
            </div>

            <div className="flex items-center gap-2">
                <Checkbox
                    id={`${id}-milestone`}
                    checked={milestone}
                    onCheckedChange={(checked) =>
                        setMilestone(checked === true)
                    }
                />
                <Label htmlFor={`${id}-milestone`} className="font-normal">
                    {t('gantt.new_task.milestone')}
                </Label>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                {milestone ? null : (
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${id}-start`}>
                            {t('gantt.column.start')}
                        </Label>
                        <DatePicker
                            id={`${id}-start`}
                            value={start}
                            onChange={setStart}
                        />
                        {errorText('start_date')}
                    </div>
                )}
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-due`}>{t('gantt.column.due')}</Label>
                    <DatePicker
                        id={`${id}-due`}
                        value={due}
                        onChange={setDue}
                        invalid={errors.due_date !== undefined}
                    />
                    {errorText('due_date')}
                </div>
            </div>

            {usesBanks ? (
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-bank`}>
                        {t('gantt.new_task.bank')}
                    </Label>
                    <Select
                        value={bankId === null ? undefined : String(bankId)}
                        onValueChange={(value) => setBankId(Number(value))}
                    >
                        <SelectTrigger
                            id={`${id}-bank`}
                            className="w-full"
                            aria-invalid={
                                errors.hour_bank_id ? true : undefined
                            }
                        >
                            <SelectValue
                                placeholder={t(
                                    'gantt.new_task.bank_placeholder',
                                )}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {openBanks.map((bank) => (
                                <SelectItem
                                    key={bank.id}
                                    value={String(bank.id)}
                                >
                                    {bank.department
                                        ? `${bank.name} · ${bank.department.name}`
                                        : bank.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {openBanks.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('gantt.new_task.no_banks')}
                        </p>
                    ) : null}
                    {errorText('hour_bank_id')}
                </div>
            ) : null}

            {otherErrors.map(([field, message]) => (
                <p
                    key={field}
                    role="alert"
                    className="flex items-center gap-1.5 text-sm text-destructive-foreground"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="size-4 shrink-0"
                    />
                    {message}
                </p>
            ))}

            <DialogFooter className="gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    onClick={onDone}
                    disabled={processing}
                >
                    {t('gantt.new_task.cancel')}
                </Button>
                <Button
                    type="submit"
                    disabled={processing}
                    data-test="gantt-new-task-submit"
                >
                    {processing ? <Spinner /> : null}
                    {t('gantt.new_task.submit')}
                </Button>
            </DialogFooter>
        </form>
    );
}
