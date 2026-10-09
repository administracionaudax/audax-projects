import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { InvoicingClientOption } from '@/types';

type Fields = {
    legal_name: string;
    tax_id: string;
    eu_vat_number: string;
    address: string;
    postal_code: string;
    city: string;
    province: string;
    country_code: string;
};

const FIELDS: { key: keyof Fields; wide?: boolean; autoComplete?: string }[] = [
    { key: 'legal_name', wide: true, autoComplete: 'organization' },
    { key: 'tax_id' },
    { key: 'eu_vat_number' },
    { key: 'address', wide: true, autoComplete: 'street-address' },
    { key: 'postal_code', autoComplete: 'postal-code' },
    { key: 'city', autoComplete: 'address-level2' },
    { key: 'province', autoComplete: 'address-level1' },
    { key: 'country_code', autoComplete: 'country' },
];

/**
 * Completar la ficha fiscal del cliente sin salir del editor (PLAN-EMISION §6.2, paso 2; D-428): los
 * datos que pide una factura completa (L-05). Guarda en la ficha del cliente (la misma ruta que su
 * página de facturación) y conserva el resto de su ficha (régimen, idioma, pago y emails).
 */
export function ClientFiscalDialog({
    client,
    trigger,
}: {
    client: InvoicingClientOption;
    trigger: React.ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const profile = client.profile;
    const form = useForm<
        Fields & {
            tax_regime: string;
            language: string;
            payment_days: number | null;
            payment_method: string | null;
            payment_day: number | null;
            billing_emails: string[];
        }
    >({
        legal_name: profile.legal_name ?? client.name,
        tax_id: profile.tax_id ?? '',
        eu_vat_number: profile.eu_vat_number ?? '',
        address: profile.address ?? '',
        postal_code: profile.postal_code ?? '',
        city: profile.city ?? '',
        province: profile.province ?? '',
        country_code: profile.country_code ?? 'ES',
        tax_regime: profile.tax_regime,
        language: profile.language,
        payment_days: profile.payment_days,
        payment_method: profile.payment_method,
        payment_day: profile.payment_day,
        billing_emails: profile.billing_emails,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // El diálogo va en un portal, pero React propaga el envío al formulario del editor.
        event.stopPropagation();
        form.put(`/clientes/${client.id}/datos-fiscales`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-xl">
                <DialogTitle>
                    {t('invoicing.fiscal.title', { client: client.name })}
                </DialogTitle>
                <DialogDescription>
                    {t('invoicing.fiscal.description')}
                </DialogDescription>
                <form
                    onSubmit={submit}
                    noValidate
                    className="grid gap-3 sm:grid-cols-2"
                    data-test="client-fiscal-form"
                >
                    {FIELDS.map(({ key, wide, autoComplete }) => (
                        <div
                            key={key}
                            className={cn(
                                'grid content-start gap-1',
                                wide && 'sm:col-span-2',
                            )}
                        >
                            <Label htmlFor={`${id}-${key}`}>
                                {t(`invoicing.fiscal.${key}`)}
                            </Label>
                            <Input
                                id={`${id}-${key}`}
                                value={form.data[key]}
                                autoComplete={autoComplete}
                                maxLength={
                                    key === 'country_code' ? 2 : undefined
                                }
                                aria-invalid={
                                    form.errors[key] ? true : undefined
                                }
                                onChange={(event) =>
                                    form.setData(key, event.target.value)
                                }
                            />
                            {key === 'eu_vat_number' &&
                            client.profile.tax_regime === 'intra_eu' ? (
                                <p className="text-xs text-muted-foreground">
                                    {t('invoicing.fiscal.eu_vat_hint')}
                                </p>
                            ) : null}
                            <InputError message={form.errors[key]} />
                        </div>
                    ))}
                    <DialogFooter className="gap-2 sm:col-span-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setOpen(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t('invoicing.fiscal.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
