import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { ColorPicker } from '@/components/admin/color-picker';
import { describedBy, Field } from '@/components/admin/field';
import { IconPicker } from '@/components/admin/icon-picker';
import { NativeSelect } from '@/components/admin/native-select';
import { Button } from '@/components/ui/button';
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
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import { store, update } from '@/routes/admin/task-types';
import type { AdminTaskType, Department } from '@/types';

type TaskTypeForm = {
    name: string;
    color: string;
    icon: string | null;
    department_id: string;
    is_billable_default: boolean;
    is_active: boolean;
};

/** Crear o editar un tipo de tarea (SPEC §4.3). */
export function TaskTypeDialog({
    taskType,
    palette,
    icons,
    departments,
    trigger,
}: {
    taskType?: AdminTaskType;
    palette: string[];
    icons: string[];
    departments: Department[];
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const initial = (): TaskTypeForm => ({
        name: taskType?.name ?? '',
        color: taskType?.color ?? palette[0] ?? '#0171FF',
        icon: taskType?.icon ?? null,
        department_id: taskType?.department_id
            ? String(taskType.department_id)
            : '',
        is_billable_default: taskType?.is_billable_default ?? true,
        is_active: taskType?.is_active ?? true,
    });
    const form = useForm<TaskTypeForm>(initial());
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            department_id:
                data.department_id === '' ? null : Number(data.department_id),
        }));
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (taskType) {
            form.put(update.url(taskType.id), options);
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
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {taskType
                                ? t('admin.task_types.edit_title', {
                                      name: taskType.name,
                                  })
                                : t('admin.task_types.new_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('admin.task_types.form_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-5 sm:grid-cols-2">
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
                                maxLength={100}
                                autoComplete="off"
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby={describedBy(`${id}-name`, {
                                    error: errors.name,
                                })}
                            />
                        </Field>
                        <Field
                            id={`${id}-department`}
                            label={t('admin.task_types.department')}
                            error={errors.department_id}
                        >
                            <NativeSelect
                                id={`${id}-department`}
                                value={form.data.department_id}
                                onChange={(event) =>
                                    form.setData(
                                        'department_id',
                                        event.target.value,
                                    )
                                }
                                aria-describedby={describedBy(
                                    `${id}-department`,
                                    {
                                        help: true,
                                        error: errors.department_id,
                                    },
                                )}
                            >
                                <option value="">
                                    {t('admin.task_types.any_department')}
                                </option>
                                {departments.map((department) => (
                                    <option
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        {/* Ayuda a todo el ancho: bajo el departamento dejaba un hueco bajo el nombre. */}
                        <p
                            id={`${id}-department-help`}
                            className="-mt-3 text-sm text-muted-foreground sm:col-span-2"
                        >
                            {t('admin.task_types.department_help')}
                        </p>
                    </div>

                    <ColorPicker
                        value={form.data.color}
                        onChange={(color) => form.setData('color', color)}
                        palette={palette}
                        legend={t('admin.form.color')}
                        error={errors.color}
                    />

                    <IconPicker
                        value={form.data.icon}
                        onChange={(icon) => form.setData('icon', icon)}
                        icons={icons}
                        color={form.data.color}
                        legend={t('admin.task_types.icon')}
                        error={errors.icon}
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="flex items-start gap-3">
                            <Switch
                                id={`${id}-billable`}
                                checked={form.data.is_billable_default}
                                onCheckedChange={(checked) =>
                                    form.setData('is_billable_default', checked)
                                }
                                aria-describedby={`${id}-billable-help`}
                            />
                            <div className="grid content-start gap-1">
                                <Label htmlFor={`${id}-billable`}>
                                    {t('admin.task_types.billable')}
                                </Label>
                                <p
                                    id={`${id}-billable-help`}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('admin.task_types.billable_help')}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-start gap-3">
                            <Switch
                                id={`${id}-active`}
                                checked={form.data.is_active}
                                onCheckedChange={(checked) =>
                                    form.setData('is_active', checked)
                                }
                                aria-describedby={`${id}-active-help`}
                            />
                            <div className="grid content-start gap-1">
                                <Label htmlFor={`${id}-active`}>
                                    {t('admin.task_types.active')}
                                </Label>
                                <p
                                    id={`${id}-active-help`}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('admin.task_types.active_help')}
                                </p>
                            </div>
                        </div>
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
