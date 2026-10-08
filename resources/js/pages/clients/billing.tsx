import { Head, Link, setLayoutProps, useForm } from '@inertiajs/react';
import { ArrowLeft, BarChart3, CircleAlert, CircleCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { useId } from 'react';
import { BillingPanel } from '@/components/billing/billing-panel';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { BillingPanelData, ReportFiltersProps } from '@/types';

type Profile = {
    legal_name: string | null;
    eu_vat_number: string | null;
    address: string | null;
    postal_code: string | null;
    city: string | null;
    province: string | null;
    country_code: string;
    tax_regime: string;
    payment_method: string | null;
    payment_days: number | null;
    payment_day: number | null;
    language: string;
    billing_emails: string[];
    complete: boolean;
};

type Props = {
    client: {
        id: number;
        name: string;
        is_active: boolean;
        tax_id: string | null;
    };
    profile: Profile;
    contacts: {
        id: number;
        name: string;
        tax_id: string | null;
        match_method: 'tax_id' | 'name' | 'manual' | null;
    }[];
    filters: ReportFiltersProps;
    panel: BillingPanelData;
    options: {
        tax_regimes: { value: string; label: string }[];
        payment_methods: { value: string; label: string }[];
    };
};

const LANGUAGES = ['es', 'en', 'ca', 'fr', 'de', 'it', 'pt'] as const;
const NONE = '__none__';

/**
 * Facturación del cliente (Fase 12, F1; D-381 y D-392): su ficha fiscal (razón social, NIF, NIF-IVA,
 * dirección, régimen, forma y días de pago, idioma y emails de facturación), sus contactos de
 * Holded, su «Vendido frente a real» y sus facturas. Solo con view-financials.
 */
export default function ClientBilling({
    client,
    profile,
    contacts,
    filters,
    panel,
    options,
}: Props) {
    const id = useId();
    const url = `/clientes/${client.id}/facturacion`;

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.clients'), href: urls.clients() },
            { title: client.name, href: urls.client(client.id) },
            { title: t('billing.client.crumb'), href: url },
        ],
    });
    const form = useForm({
        tax_id: client.tax_id ?? '',
        legal_name: profile.legal_name ?? '',
        eu_vat_number: profile.eu_vat_number ?? '',
        address: profile.address ?? '',
        postal_code: profile.postal_code ?? '',
        city: profile.city ?? '',
        province: profile.province ?? '',
        country_code: profile.country_code,
        tax_regime: profile.tax_regime,
        payment_method: profile.payment_method ?? '',
        payment_days:
            profile.payment_days === null ? '' : String(profile.payment_days),
        payment_day:
            profile.payment_day === null ? '' : String(profile.payment_day),
        language: profile.language,
        billing_emails: profile.billing_emails.join('\n'),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            payment_method:
                data.payment_method === '' ? null : data.payment_method,
            payment_days:
                data.payment_days === '' ? null : Number(data.payment_days),
            payment_day:
                data.payment_day === '' ? null : Number(data.payment_day),
            billing_emails: data.billing_emails
                .split(/[\n,;]+/)
                .map((email) => email.trim())
                .filter(Boolean),
        }));
        form.put(`/clientes/${client.id}/datos-fiscales`, {
            preserveScroll: true,
        });
    };

    const text = (
        key:
            | 'tax_id'
            | 'legal_name'
            | 'eu_vat_number'
            | 'address'
            | 'postal_code'
            | 'city'
            | 'province'
            | 'country_code',
        wide = false,
        autoComplete?: string,
    ) => (
        <div
            className={cn('grid content-start gap-1', wide && 'sm:col-span-2')}
        >
            <Label htmlFor={`${id}-${key}`}>
                {t(`billing.profile.${key}`)}
            </Label>
            <Input
                id={`${id}-${key}`}
                value={form.data[key]}
                autoComplete={autoComplete}
                maxLength={key === 'country_code' ? 2 : undefined}
                aria-invalid={errors[key] ? true : undefined}
                onChange={(event) => form.setData(key, event.target.value)}
            />
            <InputError message={errors[key]} />
        </div>
    );

    return (
        <>
            <Head title={t('billing.client.title', { client: client.name })} />

            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    title={t('billing.client.heading', { client: client.name })}
                    description={t('billing.client.description')}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={urls.client(client.id)}>
                                    <ArrowLeft aria-hidden="true" />
                                    {t('billing.client.back')}
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/informes/vendido-frente-a-real?periodo=anio&cliente[]=${client.id}`}
                                >
                                    <BarChart3 aria-hidden="true" />
                                    {t('billing.client.report')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <PageSection
                    title={t('billing.profile.title')}
                    description={t('billing.profile.description')}
                    action={
                        <p
                            className={cn(
                                'flex items-center gap-1.5 text-sm',
                                profile.complete
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {profile.complete ? (
                                <CircleCheck
                                    aria-hidden="true"
                                    className="size-4 text-success"
                                />
                            ) : (
                                <CircleAlert
                                    aria-hidden="true"
                                    className="size-4 text-warning"
                                />
                            )}
                            {t(
                                profile.complete
                                    ? 'billing.profile.complete'
                                    : 'billing.profile.incomplete',
                            )}
                        </p>
                    }
                >
                    <form
                        onSubmit={submit}
                        noValidate
                        className="grid max-w-4xl gap-4 sm:grid-cols-2"
                        data-test="billing-profile-form"
                    >
                        {text('legal_name', true, 'organization')}
                        {text('tax_id')}
                        {text('eu_vat_number')}
                        {text('address', true, 'street-address')}
                        {text('postal_code', false, 'postal-code')}
                        {text('city', false, 'address-level2')}
                        {text('province', false, 'address-level1')}
                        {text('country_code', false, 'country')}
                        <ChoiceField
                            id={`${id}-regime`}
                            label={t('billing.profile.tax_regime')}
                            value={form.data.tax_regime}
                            options={options.tax_regimes}
                            onChange={(value) =>
                                form.setData('tax_regime', value ?? 'general')
                            }
                            error={errors.tax_regime}
                            required
                        />
                        <ChoiceField
                            id={`${id}-method`}
                            label={t('billing.profile.payment_method')}
                            value={
                                form.data.payment_method === ''
                                    ? null
                                    : form.data.payment_method
                            }
                            options={options.payment_methods}
                            onChange={(value) =>
                                form.setData('payment_method', value ?? '')
                            }
                            error={errors.payment_method}
                        />
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-days`}>
                                {t('billing.profile.payment_days')}
                            </Label>
                            <Input
                                id={`${id}-days`}
                                inputMode="numeric"
                                value={form.data.payment_days}
                                aria-invalid={
                                    errors.payment_days ? true : undefined
                                }
                                onChange={(event) =>
                                    form.setData(
                                        'payment_days',
                                        event.target.value.replace(/\D/g, ''),
                                    )
                                }
                            />
                            <InputError message={errors.payment_days} />
                        </div>
                        <div className="grid content-start gap-1">
                            <Label htmlFor={`${id}-day`}>
                                {t('billing.profile.payment_day')}
                            </Label>
                            <Input
                                id={`${id}-day`}
                                inputMode="numeric"
                                value={form.data.payment_day}
                                placeholder={t(
                                    'billing.profile.payment_day_placeholder',
                                )}
                                aria-invalid={
                                    errors.payment_day ? true : undefined
                                }
                                onChange={(event) =>
                                    form.setData(
                                        'payment_day',
                                        event.target.value.replace(/\D/g, ''),
                                    )
                                }
                            />
                            <InputError message={errors.payment_day} />
                        </div>
                        <ChoiceField
                            id={`${id}-language`}
                            label={t('billing.profile.language')}
                            value={form.data.language}
                            options={LANGUAGES.map((language) => ({
                                value: language,
                                label: t(`billing.languages.${language}`),
                            }))}
                            onChange={(value) =>
                                form.setData('language', value ?? 'es')
                            }
                            error={errors.language}
                            required
                        />
                        <div className="grid content-start gap-1 sm:col-span-2">
                            <Label htmlFor={`${id}-emails`}>
                                {t('billing.profile.billing_emails')}
                            </Label>
                            <Textarea
                                id={`${id}-emails`}
                                rows={2}
                                value={form.data.billing_emails}
                                aria-describedby={`${id}-emails-help`}
                                aria-invalid={
                                    Object.keys(errors).some((key) =>
                                        key.startsWith('billing_emails'),
                                    )
                                        ? true
                                        : undefined
                                }
                                onChange={(event) =>
                                    form.setData(
                                        'billing_emails',
                                        event.target.value,
                                    )
                                }
                            />
                            <p
                                id={`${id}-emails-help`}
                                className="text-xs text-muted-foreground"
                            >
                                {t('billing.profile.billing_emails_help')}
                            </p>
                            <InputError
                                message={
                                    errors.billing_emails ??
                                    Object.entries(errors).find(([key]) =>
                                        key.startsWith('billing_emails.'),
                                    )?.[1]
                                }
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <Button type="submit" disabled={form.processing}>
                                {t('billing.profile.save')}
                            </Button>
                        </div>
                    </form>
                </PageSection>

                <PageSection
                    title={t('billing.client.contacts')}
                    description={t('billing.client.contacts_description')}
                >
                    {contacts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('billing.client.no_contacts')}{' '}
                            <Link
                                href="/facturacion/contactos"
                                className="underline"
                            >
                                {t('billing.client.resolve_contacts')}
                            </Link>
                        </p>
                    ) : (
                        <ul className="grid gap-1 text-sm">
                            {contacts.map((contact) => (
                                <li key={contact.id}>
                                    {contact.name}
                                    <span className="text-muted-foreground">
                                        {contact.tax_id
                                            ? ` · ${contact.tax_id}`
                                            : ''}{' '}
                                        ·{' '}
                                        {t(
                                            `billing.contacts.match.${contact.match_method ?? 'manual'}`,
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </PageSection>

                <PageSection
                    title={t('billing.report.title')}
                    description={t('billing.client.report_description')}
                >
                    <ReportFilterBar
                        filters={filters}
                        show={[]}
                        url={url}
                        compare={false}
                    />
                    <BillingPanel
                        panel={panel}
                        invoicesHref={`/facturacion/facturas?cliente=${client.id}`}
                    />
                </PageSection>
            </div>
        </>
    );
}

function ChoiceField({
    id,
    label,
    value,
    options,
    onChange,
    error,
    required = false,
}: {
    id: string;
    label: string;
    value: string | null;
    options: { value: string; label: string }[];
    onChange: (value: string | null) => void;
    error?: string;
    required?: boolean;
}) {
    return (
        <div className="grid content-start gap-1">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={value ?? NONE}
                onValueChange={(next) => onChange(next === NONE ? null : next)}
            >
                <SelectTrigger
                    id={id}
                    className="w-full"
                    aria-invalid={error ? true : undefined}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {!required ? (
                        <SelectItem value={NONE}>
                            {t('billing.profile.none')}
                        </SelectItem>
                    ) : null}
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}
