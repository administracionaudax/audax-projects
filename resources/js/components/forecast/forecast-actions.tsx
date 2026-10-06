import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useId } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
import { PROJECT_COLORS } from '@/components/projects-list/project-colors';
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
import { Spinner } from '@/components/ui/spinner';
import { useResetOnOpen } from '@/hooks/use-reset-on-open';
import { t } from '@/lib/i18n';
import { createProject, link, lose } from '@/routes/forecast/projects';
import type { ClientOption, ForecastProject } from '@/types/forecast';

type DialogProps = {
    forecast: ForecastProject;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** «Marcar como perdido» (D-281), con un motivo opcional. Deja de contar en la previsión. */
export function LoseDialog({ forecast, open, onOpenChange }: DialogProps) {
    const id = useId();
    const form = useForm({ reason: '' });
    useResetOnOpen(open, () => {
        form.setData({ reason: '' });
        form.clearErrors();
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(lose.url(forecast.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {t('forecast.actions.lose_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('forecast.actions.lose_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-reason`}
                        label={t('forecast.actions.reason')}
                        error={form.errors.reason}
                        optional={t('forecast.form.optional')}
                    >
                        <Input
                            id={`${id}-reason`}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            maxLength={200}
                        />
                    </Field>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('forecast.actions.cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? (
                                <Spinner aria-hidden="true" />
                            ) : null}
                            {t('forecast.actions.lose')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** «Vincular con un proyecto existente» (D-286): congela la línea base y copia las asignaciones. */
export function LinkDialog({
    forecast,
    candidates,
    open,
    onOpenChange,
}: DialogProps & {
    candidates?: { id: number; code: string; name: string }[];
}) {
    const id = useId();
    const form = useForm({ project_id: '', copy_allocations: true });
    useResetOnOpen(open, () => {
        form.setData({ project_id: '', copy_allocations: true });
        form.clearErrors();
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            project_id: data.project_id === '' ? null : Number(data.project_id),
            copy_allocations: data.copy_allocations,
        }));
        form.post(link.url(forecast.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {t('forecast.actions.link_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('forecast.actions.link_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-project`}
                        label={t('forecast.actions.project')}
                        error={form.errors.project_id}
                    >
                        <NativeSelect
                            id={`${id}-project`}
                            value={form.data.project_id}
                            onChange={(event) =>
                                form.setData('project_id', event.target.value)
                            }
                            aria-invalid={
                                form.errors.project_id ? true : undefined
                            }
                            aria-describedby={describedBy(`${id}-project`, {
                                error: form.errors.project_id,
                            })}
                        >
                            <option value="">
                                {candidates === undefined
                                    ? t('forecast.loading')
                                    : t('forecast.actions.project_placeholder')}
                            </option>
                            {(candidates ?? []).map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.code} · {project.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <CopyAllocations
                        id={id}
                        checked={form.data.copy_allocations}
                        onChange={(value) =>
                            form.setData('copy_allocations', value)
                        }
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('forecast.actions.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || form.data.project_id === ''
                            }
                        >
                            {form.processing ? (
                                <Spinner aria-hidden="true" />
                            ) : null}
                            {t('forecast.actions.link')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CopyAllocations({
    id,
    checked,
    onChange,
}: {
    id: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <div className="flex items-start gap-2">
            <Checkbox
                id={`${id}-copy`}
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
                className="mt-0.5"
            />
            <Label htmlFor={`${id}-copy`} className="grid gap-0.5 font-normal">
                {t('forecast.actions.copy')}
                <span className="text-sm text-muted-foreground">
                    {t('forecast.actions.copy_help')}
                </span>
            </Label>
        </div>
    );
}

type BillingType = 'time_and_materials' | 'fixed_price';

/**
 * «Crear proyecto real» (D-286 y D-308): el alta de siempre con lo mínimo (nombre, cliente, forma
 * de facturar, estado y fechas), con las personas asignadas como miembros y las asignaciones
 * copiadas. Si el previsto es de un cliente nuevo, se puede crear el cliente con su nombre. Las
 * bolsas y el resto de ajustes se ponen después en el proyecto.
 */
export function CreateProjectDialog({
    forecast,
    clients,
    open,
    onOpenChange,
}: DialogProps & { clients?: ClientOption[] }) {
    const id = useId();
    const prospect =
        forecast.client === null && forecast.prospect_name !== null;
    // Lo que se propone sale del previsto de ahora (puede haberse editado tras cargar la ficha).
    const proposal = () => ({
        name: forecast.name,
        code: '',
        create_client: prospect,
        client_id: forecast.client ? String(forecast.client.id) : '',
        billing_type: 'time_and_materials' as BillingType,
        status: 'planned' as 'planned' | 'active',
        color: PROJECT_COLORS[0].value,
        start_date: forecast.start_date,
        due_date: forecast.end_date,
        copy_allocations: true,
    });
    const form = useForm(proposal());
    useResetOnOpen(open, () => {
        form.setData(proposal());
        form.clearErrors();
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name.trim(),
            code: data.code.trim() === '' ? null : data.code.trim(),
            ...(data.create_client
                ? { create_client: true }
                : {
                      client_id:
                          data.client_id === '' ? null : Number(data.client_id),
                  }),
            billing_type: data.billing_type,
            status: data.status,
            color: data.color,
            start_date: data.start_date,
            due_date: data.due_date,
            copy_allocations: data.copy_allocations,
        }));
        form.post(createProject.url(forecast.id));
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form
                    onSubmit={submit}
                    className="grid gap-5"
                    noValidate
                    data-test="create-project-form"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t('forecast.actions.create_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('forecast.actions.create_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-name`}
                        label={t('forecast.form.name')}
                        error={errors.name}
                    >
                        <Input
                            id={`${id}-name`}
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            maxLength={255}
                        />
                    </Field>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-client`}>
                            {t('forecast.form.client')}
                        </Label>
                        {prospect ? (
                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id={`${id}-create-client`}
                                    checked={form.data.create_client}
                                    onCheckedChange={(value) =>
                                        form.setData(
                                            'create_client',
                                            value === true,
                                        )
                                    }
                                    className="mt-0.5"
                                />
                                <Label
                                    htmlFor={`${id}-create-client`}
                                    className="font-normal"
                                >
                                    {t('forecast.actions.create_client', {
                                        name: forecast.prospect_name ?? '',
                                    })}
                                </Label>
                            </div>
                        ) : null}
                        {form.data.create_client ? null : (
                            <NativeSelect
                                id={`${id}-client`}
                                value={form.data.client_id}
                                onChange={(event) =>
                                    form.setData(
                                        'client_id',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={
                                    errors.client_id ? true : undefined
                                }
                            >
                                <option value="">
                                    {clients === undefined
                                        ? t('forecast.loading')
                                        : t('forecast.form.client_placeholder')}
                                </option>
                                {(clients ?? []).map((client) => (
                                    <option key={client.id} value={client.id}>
                                        {client.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                        <InputError
                            message={errors.client_id ?? errors.create_client}
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${id}-billing`}
                            label={t('forecast.actions.billing')}
                            error={errors.billing_type}
                        >
                            <NativeSelect
                                id={`${id}-billing`}
                                value={form.data.billing_type}
                                onChange={(event) =>
                                    form.setData(
                                        'billing_type',
                                        event.target.value as BillingType,
                                    )
                                }
                            >
                                <option value="time_and_materials">
                                    {t(
                                        'project.billing_type.time_and_materials',
                                    )}
                                </option>
                                <option value="fixed_price">
                                    {t('project.billing_type.fixed_price')}
                                </option>
                            </NativeSelect>
                        </Field>
                        <Field
                            id={`${id}-status`}
                            label={t('forecast.actions.status')}
                            error={errors.status}
                        >
                            <NativeSelect
                                id={`${id}-status`}
                                value={form.data.status}
                                onChange={(event) =>
                                    form.setData(
                                        'status',
                                        event.target.value as
                                            | 'planned'
                                            | 'active',
                                    )
                                }
                            >
                                <option value="planned">
                                    {t('project.status.planned')}
                                </option>
                                <option value="active">
                                    {t('project.status.active')}
                                </option>
                            </NativeSelect>
                        </Field>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${id}-start`}
                            label={t('forecast.form.start')}
                            error={errors.start_date}
                            optional={t('forecast.form.optional')}
                        >
                            <DatePicker
                                id={`${id}-start`}
                                value={form.data.start_date}
                                onChange={(value) =>
                                    form.setData('start_date', value)
                                }
                            />
                        </Field>
                        <Field
                            id={`${id}-due`}
                            label={t('forecast.actions.due')}
                            error={errors.due_date}
                            optional={t('forecast.form.optional')}
                        >
                            <DatePicker
                                id={`${id}-due`}
                                value={form.data.due_date}
                                onChange={(value) =>
                                    form.setData('due_date', value)
                                }
                                min={form.data.start_date ?? undefined}
                            />
                        </Field>
                    </div>
                    <Field
                        id={`${id}-code`}
                        label={t('forecast.actions.code')}
                        error={errors.code}
                        optional={t('forecast.actions.code_auto')}
                    >
                        <Input
                            id={`${id}-code`}
                            value={form.data.code}
                            onChange={(event) =>
                                form.setData(
                                    'code',
                                    event.target.value.toUpperCase(),
                                )
                            }
                            maxLength={20}
                        />
                    </Field>
                    <CopyAllocations
                        id={id}
                        checked={form.data.copy_allocations}
                        onChange={(value) =>
                            form.setData('copy_allocations', value)
                        }
                    />
                    {errors.color ? (
                        <InputError message={errors.color} />
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('forecast.actions.cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? (
                                <Spinner aria-hidden="true" />
                            ) : null}
                            {t('forecast.actions.create_project')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
