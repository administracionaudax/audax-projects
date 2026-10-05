import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { store, update } from '@/routes/clients';
import type { Client } from '@/types';

type ClientForm = {
    name: string;
    icon: string;
    tax_id: string;
    contact_name: string;
    contact_email: string;
    phone: string;
    notes: string;
    default_hourly_rate: string;
};

function initialData(client?: Client): ClientForm {
    return {
        name: client?.name ?? '',
        icon: client?.icon ?? '',
        tax_id: client?.tax_id ?? '',
        contact_name: client?.contact_name ?? '',
        contact_email: client?.contact_email ?? '',
        phone: client?.phone ?? '',
        notes: client?.notes ?? '',
        default_hourly_rate: client?.default_hourly_rate
            ? client.default_hourly_rate.replace('.', ',')
            : '',
    };
}

/**
 * Alta y edición de un cliente en un diálogo (SPEC §6). La tarifa por defecto solo aparece con
 * view-financials (el servidor la ignora sin ese permiso). Solo el nombre es obligatorio. El icono
 * (un emoji, F-126) sale en la cartera, la ficha y la Weekly.
 */
export function ClientDialog({
    client,
    showFinancials,
    trigger,
}: {
    client?: Client;
    showFinancials: boolean;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<ClientForm>(initialData(client));
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => {
            const { default_hourly_rate: rate, ...rest } = data;

            return showFinancials
                ? { ...rest, default_hourly_rate: rate }
                : rest;
        });
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (client) {
            form.put(update.url(client.id), options);
        } else {
            form.post(store.url(), options);
        }
    };

    const text = (
        key: keyof ClientForm,
        label: string,
        props: {
            type?: string;
            maxLength?: number;
            autoComplete?: string;
            required?: boolean;
            optional?: boolean;
            placeholder?: string;
            help?: string;
        } = {},
    ) => {
        const inputId = `${id}-${key}`;

        return (
            <Field
                id={inputId}
                label={label}
                optional={
                    props.optional ? t('clients.form.optional') : undefined
                }
                help={props.help}
                error={errors[key]}
            >
                <Input
                    id={inputId}
                    type={props.type ?? 'text'}
                    value={form.data[key]}
                    onChange={(event) => form.setData(key, event.target.value)}
                    required={props.required}
                    maxLength={props.maxLength}
                    autoComplete={props.autoComplete ?? 'off'}
                    placeholder={props.placeholder}
                    aria-invalid={errors[key] ? true : undefined}
                    aria-describedby={describedBy(inputId, {
                        help: Boolean(props.help),
                        error: errors[key],
                    })}
                />
            </Field>
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.setData(initialData(client));
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {client
                                ? t('clients.form.edit_title', {
                                      name: client.name,
                                  })
                                : t('clients.form.new_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('clients.form.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-5 sm:grid-cols-2">
                        {text('name', t('clients.form.name'), {
                            required: true,
                            maxLength: 255,
                        })}
                        {text('icon', t('clients.form.icon'), {
                            optional: true,
                            maxLength: 16,
                            placeholder: '🍷',
                            help: t('clients.form.icon_help'),
                        })}
                        {text('tax_id', t('clients.form.tax_id'), {
                            optional: true,
                            maxLength: 32,
                        })}
                        {text('contact_name', t('clients.form.contact_name'), {
                            optional: true,
                            maxLength: 255,
                        })}
                        {text(
                            'contact_email',
                            t('clients.form.contact_email'),
                            {
                                optional: true,
                                type: 'email',
                                maxLength: 255,
                            },
                        )}
                        {text('phone', t('clients.form.phone'), {
                            optional: true,
                            type: 'tel',
                            maxLength: 32,
                        })}
                        {showFinancials
                            ? text(
                                  'default_hourly_rate',
                                  t('clients.form.default_hourly_rate'),
                                  {
                                      optional: true,
                                      placeholder: '0,00',
                                      help: t(
                                          'clients.form.default_hourly_rate_help',
                                      ),
                                  },
                              )
                            : null}
                    </div>

                    <Field
                        id={`${id}-notes`}
                        label={t('clients.form.notes')}
                        optional={t('clients.form.optional')}
                        error={errors.notes}
                    >
                        <Textarea
                            id={`${id}-notes`}
                            rows={4}
                            maxLength={5000}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                            aria-invalid={errors.notes ? true : undefined}
                            aria-describedby={describedBy(`${id}-notes`, {
                                error: errors.notes,
                            })}
                        />
                    </Field>

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
                            {client
                                ? t('common.save')
                                : t('clients.form.create')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
