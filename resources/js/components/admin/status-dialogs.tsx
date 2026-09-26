import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { ColorPicker } from '@/components/admin/color-picker';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { destroy, store, update } from '@/routes/admin/statuses';
import type { AdminTaskStatus, TaskStatusCategory } from '@/types';

export const CATEGORIES: {
    value: TaskStatusCategory;
    label: TranslationKey;
    help: TranslationKey;
}[] = [
    {
        value: 'todo',
        label: 'admin.statuses.category.todo',
        help: 'admin.statuses.category.todo_help',
    },
    {
        value: 'in_progress',
        label: 'admin.statuses.category.in_progress',
        help: 'admin.statuses.category.in_progress_help',
    },
    {
        value: 'done',
        label: 'admin.statuses.category.done',
        help: 'admin.statuses.category.done_help',
    },
];

export function categoryLabel(category: TaskStatusCategory): string {
    const found = CATEGORIES.find((item) => item.value === category);

    return found ? t(found.label) : category;
}

type StatusForm = {
    name: string;
    color: string;
    category: TaskStatusCategory;
    is_default: boolean;
};

/** Crear o editar un estado de tarea (SPEC §4.3): nombre, color, categoría y si es el de por defecto. */
export function StatusDialog({
    status,
    palette,
    trigger,
}: {
    status?: AdminTaskStatus;
    palette: string[];
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const initial = (): StatusForm => ({
        name: status?.name ?? '',
        color: status?.color ?? palette[0] ?? '#0171FF',
        category: status?.category ?? 'todo',
        is_default: status?.is_default ?? false,
    });
    const form = useForm<StatusForm>(initial());
    const errors = form.errors as Record<string, string | undefined>;
    const categoryChanged =
        status !== undefined &&
        status.category !== form.data.category &&
        status.tasks_count > 0;
    const crossesDone =
        categoryChanged &&
        (status.category === 'done') !== (form.data.category === 'done');

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (status) {
            form.put(update.url(status.id), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.setData(initial());
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {status
                                ? t('admin.statuses.edit_title', {
                                      name: status.name,
                                  })
                                : t('admin.statuses.new_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('admin.statuses.form_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id={`${id}-name`}
                        label={t('admin.form.name')}
                        error={errors.name}
                    >
                        <Input
                            id={`${id}-name`}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                            maxLength={60}
                            autoComplete="off"
                            aria-invalid={errors.name ? true : undefined}
                            aria-describedby={describedBy(`${id}-name`, {
                                error: errors.name,
                            })}
                        />
                    </Field>

                    <Field
                        id={`${id}-category`}
                        label={t('admin.statuses.category_label')}
                        help={t(
                            CATEGORIES.find(
                                (item) => item.value === form.data.category,
                            )?.help ?? 'admin.statuses.category.todo_help',
                        )}
                        error={errors.category}
                    >
                        <NativeSelect
                            id={`${id}-category`}
                            value={form.data.category}
                            onChange={(event) =>
                                form.setData(
                                    'category',
                                    event.target.value as TaskStatusCategory,
                                )
                            }
                            aria-invalid={errors.category ? true : undefined}
                            aria-describedby={describedBy(`${id}-category`, {
                                help: true,
                                error: errors.category,
                            })}
                        >
                            {CATEGORIES.map((category) => (
                                <option
                                    key={category.value}
                                    value={category.value}
                                >
                                    {t(category.label)}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>

                    {crossesDone ? (
                        <p
                            role="status"
                            className="rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground"
                        >
                            {form.data.category === 'done'
                                ? t('admin.statuses.becomes_done', {
                                      count: status.tasks_count,
                                  })
                                : t('admin.statuses.leaves_done', {
                                      count: status.tasks_count,
                                  })}
                        </p>
                    ) : null}

                    <ColorPicker
                        value={form.data.color}
                        onChange={(color) => form.setData('color', color)}
                        palette={palette}
                        legend={t('admin.form.color')}
                        error={errors.color}
                    />

                    <div className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id={`${id}-default`}
                                checked={form.data.is_default}
                                disabled={status?.is_default === true}
                                onCheckedChange={(checked) =>
                                    form.setData('is_default', checked === true)
                                }
                                aria-describedby={`${id}-default-help`}
                            />
                            <Label
                                htmlFor={`${id}-default`}
                                className="font-normal"
                            >
                                {t('admin.statuses.is_default')}
                            </Label>
                        </div>
                        <p
                            id={`${id}-default-help`}
                            className="text-sm text-muted-foreground"
                        >
                            {status?.is_default
                                ? t('admin.statuses.is_default_locked')
                                : t('admin.statuses.is_default_help')}
                        </p>
                        <InputError message={errors.is_default} />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Borrar un estado. Si tiene tareas, hay que elegir a qué estado pasan (se mueven en una
 * transacción y su «completada» se ajusta a la categoría nueva).
 */
export function DeleteStatusDialog({
    status,
    statuses,
    trigger,
}: {
    status: AdminTaskStatus;
    statuses: AdminTaskStatus[];
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const alternatives = statuses.filter(
        (candidate) => candidate.id !== status.id,
    );
    const form = useForm<{ replacement_status_id: string }>({
        replacement_status_id: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const needsReplacement = status.tasks_count > 0;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            replacement_status_id:
                data.replacement_status_id === ''
                    ? null
                    : Number(data.replacement_status_id),
        }));
        form.delete(destroy.url(status.id), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {t('admin.statuses.delete_title', {
                                name: status.name,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {needsReplacement
                                ? t('admin.statuses.delete_with_tasks', {
                                      count: status.tasks_count,
                                  })
                                : t('admin.statuses.delete_description')}
                        </DialogDescription>
                    </DialogHeader>

                    {needsReplacement ? (
                        <Field
                            id={`${id}-replacement`}
                            label={t('admin.statuses.replacement')}
                            error={errors.replacement_status_id}
                        >
                            <NativeSelect
                                id={`${id}-replacement`}
                                value={form.data.replacement_status_id}
                                required
                                onChange={(event) =>
                                    form.setData(
                                        'replacement_status_id',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={
                                    errors.replacement_status_id
                                        ? true
                                        : undefined
                                }
                                aria-describedby={describedBy(
                                    `${id}-replacement`,
                                    {
                                        error: errors.replacement_status_id,
                                    },
                                )}
                            >
                                <option value="">
                                    {t('admin.statuses.choose_replacement')}
                                </option>
                                {alternatives.map((candidate) => (
                                    <option
                                        key={candidate.id}
                                        value={String(candidate.id)}
                                    >
                                        {candidate.name} (
                                        {categoryLabel(candidate.category)})
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    ) : null}

                    <InputError message={errors.status ?? errors.category} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                form.processing ||
                                (needsReplacement &&
                                    form.data.replacement_status_id === '')
                            }
                        >
                            {form.processing && <Spinner />}
                            {t('common.delete')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
