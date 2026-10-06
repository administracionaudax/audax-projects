import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
import { LayerSwatch } from '@/components/forecast/layer-swatch';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAbilities } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { store, update } from '@/routes/forecast/projects';
import type {
    ClientOption,
    ForecastConfidence,
    ForecastProject,
} from '@/types/forecast';

/** Horas máximas de la estimación (Allocation::MINUTES_MAX). */
export const ESTIMATE_MAX_MINUTES = 99999 * 60;

type FormData = {
    name: string;
    client_kind: 'existing' | 'new';
    client_id: string;
    prospect_name: string;
    confidence: ForecastConfidence;
    start_date: string | null;
    end_date: string | null;
    estimated_minutes: number | null;
    estimated_amount: string;
    description: string;
};

function initial(forecast?: ForecastProject): FormData {
    return {
        name: forecast?.name ?? '',
        client_kind:
            forecast && forecast.client === null && forecast.prospect_name
                ? 'new'
                : 'existing',
        client_id: forecast?.client ? String(forecast.client.id) : '',
        prospect_name: forecast?.prospect_name ?? '',
        confidence: forecast?.confidence ?? 'tentative',
        start_date: forecast?.start_date ?? null,
        end_date: forecast?.end_date ?? null,
        estimated_minutes: forecast?.estimated_minutes ?? null,
        estimated_amount: forecast?.estimated_amount ?? '',
        description: forecast?.description ?? '',
    };
}

/**
 * Alta y edición de un proyecto previsto (D-281): nombre, cliente existente o nuevo (nombre libre),
 * seguridad (Segura o Posible, sin %: P5), fechas, estimación total y, solo con permiso de importes,
 * el importe estimado. Las reglas las valida el servidor y sus errores salen junto a cada campo.
 */
export function ForecastProjectDialog({
    forecast,
    clients,
    trigger,
    open: controlledOpen,
    onOpenChange,
}: {
    forecast?: ForecastProject;
    clients?: ClientOption[];
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const id = useId();
    const can = useAbilities();
    const [internalOpen, setInternalOpen] = useState(false);
    const open = controlledOpen ?? internalOpen;
    const form = useForm<FormData>(initial(forecast));
    const errors = form.errors as Record<string, string | undefined>;
    const edit = forecast !== undefined;
    // Un confirmado es siempre seguro (no vuelve a posible).
    const lockedFirm = forecast?.status === 'confirmed';

    const setOpen = (next: boolean) => {
        form.setData(initial(forecast));
        form.clearErrors();
        setInternalOpen(next);
        onOpenChange?.(next);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name.trim(),
            client_id:
                data.client_kind === 'existing' && data.client_id !== ''
                    ? Number(data.client_id)
                    : null,
            prospect_name:
                data.client_kind === 'new'
                    ? data.prospect_name.trim() || null
                    : null,
            confidence: lockedFirm ? 'firm' : data.confidence,
            start_date: data.start_date,
            end_date: data.end_date,
            estimated_minutes: data.estimated_minutes,
            ...(can.viewFinancials
                ? {
                      estimated_amount:
                          data.estimated_amount.trim() === ''
                              ? null
                              : data.estimated_amount.replace(',', '.'),
                  }
                : {}),
            description:
                data.description.trim() === '' ? null : data.description,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (forecast) {
            form.put(update.url(forecast.id), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {trigger ? <DialogTrigger asChild>{trigger}</DialogTrigger> : null}
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form
                    onSubmit={submit}
                    className="grid gap-5"
                    noValidate
                    data-test="forecast-project-form"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t(
                                edit
                                    ? 'forecast.form.edit_title'
                                    : 'forecast.form.new_title',
                            )}
                        </DialogTitle>
                        <DialogDescription>
                            {t('forecast.form.description')}
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
                            required
                            maxLength={255}
                            aria-invalid={errors.name ? true : undefined}
                            aria-describedby={describedBy(`${id}-name`, {
                                error: errors.name,
                            })}
                        />
                    </Field>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {t('forecast.form.client')}
                        </legend>
                        <RadioGroup
                            value={form.data.client_kind}
                            onValueChange={(value) =>
                                form.setData(
                                    'client_kind',
                                    value as FormData['client_kind'],
                                )
                            }
                            className="grid gap-2 sm:grid-cols-2"
                        >
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    id={`${id}-existing`}
                                    value="existing"
                                />
                                <Label
                                    htmlFor={`${id}-existing`}
                                    className="font-normal"
                                >
                                    {t('forecast.form.client_existing')}
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <RadioGroupItem id={`${id}-new`} value="new" />
                                <Label
                                    htmlFor={`${id}-new`}
                                    className="font-normal"
                                >
                                    {t('forecast.form.client_new')}
                                </Label>
                            </div>
                        </RadioGroup>
                        {form.data.client_kind === 'existing' ? (
                            <NativeSelect
                                id={`${id}-client`}
                                aria-label={t('forecast.form.client')}
                                value={form.data.client_id}
                                onChange={(event) =>
                                    form.setData(
                                        'client_id',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={
                                    errors.client_id || errors.prospect_name
                                        ? true
                                        : undefined
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
                        ) : (
                            <Input
                                id={`${id}-prospect`}
                                aria-label={t('forecast.form.prospect')}
                                placeholder={t('forecast.form.prospect')}
                                value={form.data.prospect_name}
                                onChange={(event) =>
                                    form.setData(
                                        'prospect_name',
                                        event.target.value,
                                    )
                                }
                                maxLength={255}
                                aria-invalid={
                                    errors.prospect_name ? true : undefined
                                }
                            />
                        )}
                        <InputError
                            message={errors.client_id ?? errors.prospect_name}
                        />
                    </fieldset>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {t('forecast.form.confidence')}
                        </legend>
                        <RadioGroup
                            value={lockedFirm ? 'firm' : form.data.confidence}
                            onValueChange={(value) =>
                                form.setData(
                                    'confidence',
                                    value as ForecastConfidence,
                                )
                            }
                            className="grid gap-2 sm:grid-cols-2"
                            disabled={lockedFirm}
                        >
                            {(['firm', 'tentative'] as const).map(
                                (confidence) => (
                                    <div
                                        key={confidence}
                                        className="flex items-center gap-2"
                                    >
                                        <RadioGroupItem
                                            id={`${id}-${confidence}`}
                                            value={confidence}
                                        />
                                        <Label
                                            htmlFor={`${id}-${confidence}`}
                                            className="flex items-center gap-1.5 font-normal"
                                        >
                                            <LayerSwatch layer={confidence} />
                                            {t(
                                                `forecast.confidence.${confidence}`,
                                            )}
                                        </Label>
                                    </div>
                                ),
                            )}
                        </RadioGroup>
                        <p className="text-sm text-muted-foreground">
                            {t('forecast.form.confidence_help')}
                        </p>
                        <InputError message={errors.confidence} />
                    </fieldset>

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
                                invalid={Boolean(errors.start_date)}
                            />
                        </Field>
                        <Field
                            id={`${id}-end`}
                            label={t('forecast.form.end')}
                            error={errors.end_date}
                            optional={t('forecast.form.optional')}
                        >
                            <DatePicker
                                id={`${id}-end`}
                                value={form.data.end_date}
                                onChange={(value) =>
                                    form.setData('end_date', value)
                                }
                                min={form.data.start_date ?? undefined}
                                invalid={Boolean(errors.end_date)}
                            />
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${id}-estimate`}
                            label={t('forecast.form.estimate')}
                            error={errors.estimated_minutes}
                            optional={t('forecast.form.optional')}
                        >
                            <DurationInput
                                id={`${id}-estimate`}
                                value={form.data.estimated_minutes}
                                onChange={(value) =>
                                    form.setData('estimated_minutes', value)
                                }
                                max={ESTIMATE_MAX_MINUTES}
                                invalid={Boolean(errors.estimated_minutes)}
                                // La ayuda («= 1:30 h») va bajo la caja sin ocupar sitio: así las dos
                                // columnas y el campo siguiente quedan alineados.
                                className="relative [&>p]:absolute [&>p]:top-full [&>p]:mt-1"
                            />
                        </Field>
                        {can.viewFinancials ? (
                            <Field
                                id={`${id}-amount`}
                                label={t('forecast.form.amount')}
                                error={errors.estimated_amount}
                                optional={t('forecast.form.optional')}
                            >
                                <Input
                                    id={`${id}-amount`}
                                    inputMode="decimal"
                                    value={form.data.estimated_amount}
                                    onChange={(event) =>
                                        form.setData(
                                            'estimated_amount',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        errors.estimated_amount
                                            ? true
                                            : undefined
                                    }
                                />
                            </Field>
                        ) : null}
                    </div>

                    <Field
                        id={`${id}-description`}
                        label={t('forecast.form.notes')}
                        error={errors.description}
                        optional={t('forecast.form.optional')}
                    >
                        <Textarea
                            id={`${id}-description`}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            rows={3}
                        />
                    </Field>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            {t('forecast.actions.cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? (
                                <Spinner aria-hidden="true" />
                            ) : null}
                            {t(
                                edit
                                    ? 'forecast.actions.save'
                                    : 'forecast.form.create',
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
