import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { ColorPicker } from '@/components/admin/color-picker';
import { describedBy, Field } from '@/components/admin/field';
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
import { store, update } from '@/routes/admin/departments';
import type { AdminDepartment, UserSummary } from '@/types';

type DepartmentForm = {
    name: string;
    color: string;
    manager_ids: number[];
};

/** Crear o editar un departamento: nombre, color de la paleta y responsables (D-024). */
export function DepartmentDialog({
    department,
    palette,
    managerOptions,
    trigger,
}: {
    department?: AdminDepartment;
    palette: string[];
    managerOptions: UserSummary[];
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const initial = (): DepartmentForm => ({
        name: department?.name ?? '',
        color: department?.color ?? palette[0] ?? '#0171FF',
        manager_ids: department?.managers.map((manager) => manager.id) ?? [],
    });
    const form = useForm<DepartmentForm>(initial());
    const errors = form.errors as Record<string, string | undefined>;
    // Un responsable actual que ya no sea elegible (p. ej. desactivado) se sigue viendo.
    const options = [
        ...managerOptions,
        ...(department?.managers ?? []).filter(
            (manager) =>
                !managerOptions.some((option) => option.id === manager.id),
        ),
    ];

    const toggle = (userId: number, checked: boolean) => {
        form.setData(
            'manager_ids',
            checked
                ? [...form.data.manager_ids, userId]
                : form.data.manager_ids.filter(
                      (managerId) => managerId !== userId,
                  ),
        );
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (department) {
            form.put(update.url(department.id), options);
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
                            {department
                                ? t('admin.departments.edit_title', {
                                      name: department.name,
                                  })
                                : t('admin.departments.new_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('admin.departments.form_description')}
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
                            maxLength={100}
                            autoComplete="off"
                            aria-invalid={errors.name ? true : undefined}
                            aria-describedby={describedBy(`${id}-name`, {
                                error: errors.name,
                            })}
                        />
                    </Field>

                    <ColorPicker
                        value={form.data.color}
                        onChange={(color) => form.setData('color', color)}
                        palette={palette}
                        legend={t('admin.form.color')}
                        error={errors.color}
                    />

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {t('admin.departments.managers')}
                        </legend>
                        <p className="text-sm text-muted-foreground">
                            {t('admin.departments.managers_help')}
                        </p>
                        {options.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.departments.no_manager_options')}
                            </p>
                        ) : (
                            <ul className="grid max-h-56 gap-2 overflow-y-auto rounded-md border p-3 sm:grid-cols-2">
                                {options.map((option) => {
                                    const checkboxId = `${id}-manager-${option.id}`;

                                    return (
                                        <li
                                            key={option.id}
                                            className="flex items-center gap-2"
                                        >
                                            <Checkbox
                                                id={checkboxId}
                                                checked={form.data.manager_ids.includes(
                                                    option.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggle(
                                                        option.id,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor={checkboxId}
                                                className="font-normal"
                                            >
                                                {option.name}
                                            </Label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        <InputError message={errors.manager_ids} />
                    </fieldset>

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
