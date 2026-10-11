import { router, useForm } from '@inertiajs/react';
import {
    BellRing,
    CircleAlert,
    Mail,
    Plus,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useId, useState } from 'react';
import InputError from '@/components/input-error';
import { PageSection } from '@/components/projects-list/page-section';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type { InvoicingMailingData } from '@/types';

const BASE = '/facturacion/ajustes/emision';

type Template = InvoicingMailingData['templates'][number];

/** Valores de ejemplo de las variables, para la vista previa de la plantilla. */
export function sampleValues(company: string): Record<string, string> {
    return {
        cliente: 'Hoteles Mirador, S.A.',
        numero: 'F270001',
        importe: '1.210,00 €',
        pendiente: '1.210,00 €',
        vencimiento: '03/03/2027',
        enlace: t('invoicing.collect.mailing.sample_link'),
        empresa: company,
        rectificada: 'F260314',
    };
}

/** Cambia cada [variable] por su valor (como EmailTemplates::fill). */
export function fillTemplate(
    text: string,
    values: Record<string, string>,
): string {
    return text.replace(/\[([a-z]+)\]/g, (match, name: string) =>
        name in values ? values[name] : match,
    );
}

/**
 * El remitente de las facturas (supuesto de P-4, D-438): sale desde la dirección del relé con el
 * nombre de aquí; la respuesta y la copia oculta, opcionales.
 */
export function SenderSettings({ data }: { data: InvoicingMailingData }) {
    const id = useId();
    const form = useForm({
        sender_name:
            data.sender.sender_name === data.sender.default_name
                ? ''
                : data.sender.sender_name,
        reply_to: data.sender.reply_to ?? '',
        bcc: data.sender.bcc ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(`${BASE}/correo`, { preserveScroll: true });
    };

    return (
        <PageSection
            title={t('invoicing.collect.mailing.sender_title')}
            description={t('invoicing.collect.mailing.sender_description', {
                email: data.sender.from,
                days: data.sender.link_days,
            })}
        >
            <form
                onSubmit={submit}
                noValidate
                className="grid max-w-3xl gap-4 sm:grid-cols-2"
                data-test="mailing-sender"
            >
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor={`${id}-name`}>
                        {t('invoicing.collect.mailing.sender_name')}
                    </Label>
                    <Input
                        id={`${id}-name`}
                        value={form.data.sender_name}
                        maxLength={120}
                        disabled={!data.can_manage}
                        placeholder={data.sender.default_name}
                        onChange={(event) =>
                            form.setData('sender_name', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('invoicing.collect.mailing.sender_preview', {
                            name:
                                form.data.sender_name.trim() ||
                                data.sender.default_name,
                            email: data.sender.from,
                        })}
                    </p>
                    <InputError message={form.errors.sender_name} />
                </div>
                <div className="grid content-start gap-1">
                    <Label htmlFor={`${id}-reply`}>
                        {t('invoicing.collect.mailing.reply_to')}
                    </Label>
                    <Input
                        id={`${id}-reply`}
                        type="email"
                        value={form.data.reply_to}
                        disabled={!data.can_manage}
                        placeholder={data.sender.from}
                        onChange={(event) =>
                            form.setData('reply_to', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('invoicing.collect.mailing.reply_to_hint')}
                    </p>
                    <InputError message={form.errors.reply_to} />
                </div>
                <div className="grid content-start gap-1">
                    <Label htmlFor={`${id}-bcc`}>
                        {t('invoicing.collect.mailing.bcc')}
                    </Label>
                    <Input
                        id={`${id}-bcc`}
                        type="email"
                        value={form.data.bcc}
                        disabled={!data.can_manage}
                        placeholder={t(
                            'invoicing.collect.mailing.bcc_placeholder',
                        )}
                        onChange={(event) =>
                            form.setData('bcc', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('invoicing.collect.mailing.bcc_hint')}
                    </p>
                    <InputError message={form.errors.bcc} />
                </div>
                {data.can_manage ? (
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            {t('invoicing.settings.save')}
                        </Button>
                    </div>
                ) : null}
            </form>
        </PageSection>
    );
}

/**
 * Las plantillas de email (D-439): por tipo e idioma, con asunto y texto, las variables entre
 * corchetes y una vista previa con valores de ejemplo. «Volver a la de serie» quita la cambiada.
 */
export function TemplateSettings({ data }: { data: InvoicingMailingData }) {
    const id = useId();
    const [kind, setKind] = useState<Template['kind']>('invoice');
    const [language, setLanguage] = useState<Template['language']>('es');
    const template =
        data.templates.find(
            (one) => one.kind === kind && one.language === language,
        ) ?? data.templates[0];
    const form = useForm({ subject: template.subject, body: template.body });
    const values = sampleValues(data.sender.sender_name);

    useEffect(() => {
        form.setData({ subject: template.subject, body: template.body });
        form.clearErrors();
        // Al cambiar de plantilla (o al guardarla), lo guardado.
    }, [kind, language, template.subject, template.body]);

    const url = `${BASE}/plantillas/${kind}/${language}`;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(url, { preserveScroll: true });
    };
    const dirty =
        form.data.subject !== template.subject ||
        form.data.body !== template.body;

    return (
        <PageSection
            title={t('invoicing.collect.mailing.templates_title')}
            description={t('invoicing.collect.mailing.templates_description')}
        >
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid content-start gap-1">
                    <Label htmlFor={`${id}-kind`}>
                        {t('invoicing.collect.mailing.kind')}
                    </Label>
                    <Select
                        value={kind}
                        onValueChange={(value) =>
                            setKind(value as Template['kind'])
                        }
                    >
                        <SelectTrigger id={`${id}-kind`} className="w-56">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(
                                ['invoice', 'credit_note', 'reminder'] as const
                            ).map((one) => (
                                <SelectItem key={one} value={one}>
                                    {t(`invoicing.collect.mailing.kind_${one}`)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid content-start gap-1">
                    <Label htmlFor={`${id}-language`}>
                        {t('invoicing.collect.mailing.language')}
                    </Label>
                    <Select
                        value={language}
                        onValueChange={(value) =>
                            setLanguage(value as Template['language'])
                        }
                    >
                        <SelectTrigger id={`${id}-language`} className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="es">
                                {t('invoicing.collect.mailing.language_es')}
                            </SelectItem>
                            <SelectItem value="en">
                                {t('invoicing.collect.mailing.language_en')}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <span className="pb-2 text-xs text-muted-foreground">
                    {t(
                        template.custom
                            ? 'invoicing.collect.mailing.custom'
                            : 'invoicing.collect.mailing.default',
                    )}
                </span>
            </div>

            <div className="grid gap-6 xl:grid-cols-2">
                <form
                    onSubmit={submit}
                    noValidate
                    className="grid content-start gap-4"
                    data-test="mailing-template"
                >
                    <div className="grid content-start gap-1">
                        <Label htmlFor={`${id}-subject`}>
                            {t('invoicing.collect.email.subject')}
                        </Label>
                        <Input
                            id={`${id}-subject`}
                            value={form.data.subject}
                            maxLength={255}
                            disabled={!data.can_manage}
                            onChange={(event) =>
                                form.setData('subject', event.target.value)
                            }
                        />
                        <InputError message={form.errors.subject} />
                    </div>
                    <div className="grid content-start gap-1">
                        <Label htmlFor={`${id}-body`}>
                            {t('invoicing.collect.email.body')}
                        </Label>
                        <Textarea
                            id={`${id}-body`}
                            value={form.data.body}
                            rows={10}
                            maxLength={10000}
                            disabled={!data.can_manage}
                            aria-describedby={`${id}-variables`}
                            onChange={(event) =>
                                form.setData('body', event.target.value)
                            }
                        />
                        <InputError message={form.errors.body} />
                    </div>
                    <div id={`${id}-variables`} className="grid gap-1.5">
                        <p className="text-xs text-muted-foreground">
                            {t('invoicing.collect.mailing.variables')}
                        </p>
                        <ul className="flex flex-wrap gap-1.5">
                            {data.variables
                                .filter(
                                    (name) =>
                                        name !== 'rectificada' ||
                                        kind === 'credit_note',
                                )
                                .map((name) => (
                                    <li
                                        key={name}
                                        className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs"
                                        title={t(
                                            `invoicing.collect.mailing.variable_${name}` as 'invoicing.collect.mailing.variable_cliente',
                                        )}
                                    >
                                        [{name}]
                                    </li>
                                ))}
                        </ul>
                    </div>
                    {data.can_manage ? (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                disabled={form.processing || !dirty}
                            >
                                {t('invoicing.settings.save')}
                            </Button>
                            {template.custom ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() =>
                                        router.delete(url, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <RotateCcw aria-hidden="true" />
                                    {t('invoicing.collect.mailing.reset')}
                                </Button>
                            ) : null}
                        </div>
                    ) : null}
                </form>

                <figure
                    className="grid content-start gap-2 rounded-md border bg-card p-4"
                    aria-label={t('invoicing.collect.mailing.preview')}
                    data-test="mailing-preview"
                >
                    <figcaption className="flex items-center gap-2 text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase">
                        <Mail aria-hidden="true" className="size-3.5" />
                        {t('invoicing.collect.mailing.preview')}
                    </figcaption>
                    <p className="border-b pb-2 text-sm">
                        {fillTemplate(form.data.subject, values)}
                    </p>
                    <p className="text-sm whitespace-pre-line">
                        {fillTemplate(form.data.body, values)}
                    </p>
                    <span className="mt-2 inline-flex w-fit rounded-md bg-primary px-3 py-1.5 text-sm text-primary-foreground">
                        {t(
                            kind === 'credit_note'
                                ? 'invoicing.collect.mailing.button_credit'
                                : 'invoicing.collect.mailing.button',
                        )}
                    </span>
                </figure>
            </div>
        </PageSection>
    );
}

/** Texto de un escalón: «3 días antes», «El día del vencimiento», «7 días después». */
export function ruleLabel(days: number): string {
    if (days === 0) {
        return t('invoicing.collect.reminders.on_due');
    }

    return tCount(
        days < 0
            ? 'invoicing.collect.reminders.before'
            : 'invoicing.collect.reminders.after',
        Math.abs(days),
    );
}

/**
 * Los recordatorios de cobro por escalones (D-442): cada escalón con su interruptor, añadir uno
 * (días antes o después) y quitarlo. Se apagan por cliente en su ficha de facturación.
 */
export function ReminderSettings({ data }: { data: InvoicingMailingData }) {
    const id = useId();
    const [when, setWhen] = useState<'before' | 'on' | 'after'>('after');
    const form = useForm({ days: '15' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const days =
            when === 'on'
                ? 0
                : Number(form.data.days) * (when === 'before' ? -1 : 1);
        form.transform(() => ({ days, is_active: true }));
        form.post(`${BASE}/recordatorios`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <PageSection
            title={t('invoicing.collect.reminders.title')}
            description={t('invoicing.collect.reminders.description')}
        >
            {!data.reminders_active ? (
                <p className="flex max-w-3xl items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm">
                    <CircleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    {t('invoicing.collect.reminders.inactive')}
                </p>
            ) : null}
            <ul
                className="grid max-w-3xl divide-y rounded-md border bg-card"
                data-test="reminder-rules"
            >
                {data.rules.map((rule) => (
                    <li
                        key={rule.id}
                        className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                    >
                        <BellRing
                            aria-hidden="true"
                            className={cn(
                                'size-4 shrink-0',
                                rule.is_active
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        />
                        <span
                            className={cn(
                                'min-w-0 flex-1',
                                !rule.is_active && 'text-muted-foreground',
                            )}
                        >
                            {ruleLabel(rule.days)}
                            {rule.sent > 0 ? (
                                <span className="ml-2 text-xs text-muted-foreground">
                                    {tCount(
                                        'invoicing.collect.reminders.sent',
                                        rule.sent,
                                    )}
                                </span>
                            ) : null}
                        </span>
                        <Switch
                            checked={rule.is_active}
                            disabled={!data.can_manage}
                            aria-label={t(
                                'invoicing.collect.reminders.active',
                                {
                                    rule: ruleLabel(rule.days),
                                },
                            )}
                            onCheckedChange={(checked) =>
                                router.put(
                                    `${BASE}/recordatorios/${rule.id}`,
                                    { days: rule.days, is_active: checked },
                                    { preserveScroll: true },
                                )
                            }
                        />
                        {data.can_manage ? (
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={t(
                                    'invoicing.collect.reminders.remove',
                                    { rule: ruleLabel(rule.days) },
                                )}
                                onClick={() =>
                                    router.delete(
                                        `${BASE}/recordatorios/${rule.id}`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        ) : null}
                    </li>
                ))}
                {data.rules.length === 0 ? (
                    <li className="px-4 py-3 text-sm text-muted-foreground">
                        {t('invoicing.collect.reminders.none')}
                    </li>
                ) : null}
            </ul>
            {data.can_manage ? (
                <form
                    onSubmit={submit}
                    noValidate
                    className="flex max-w-3xl flex-wrap items-end gap-3"
                    data-test="reminder-add"
                >
                    <div className="grid content-start gap-1">
                        <Label htmlFor={`${id}-days`}>
                            {t('invoicing.collect.reminders.days')}
                        </Label>
                        <Input
                            id={`${id}-days`}
                            type="number"
                            min={1}
                            max={365}
                            className="w-24"
                            disabled={when === 'on'}
                            value={when === 'on' ? '0' : form.data.days}
                            onChange={(event) =>
                                form.setData('days', event.target.value)
                            }
                        />
                    </div>
                    <div className="grid content-start gap-1">
                        <Label htmlFor={`${id}-when`}>
                            {t('invoicing.collect.reminders.when')}
                        </Label>
                        <Select
                            value={when}
                            onValueChange={(value) =>
                                setWhen(value as 'before' | 'on' | 'after')
                            }
                        >
                            <SelectTrigger id={`${id}-when`} className="w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="before">
                                    {t(
                                        'invoicing.collect.reminders.when_before',
                                    )}
                                </SelectItem>
                                <SelectItem value="on">
                                    {t('invoicing.collect.reminders.when_on')}
                                </SelectItem>
                                <SelectItem value="after">
                                    {t(
                                        'invoicing.collect.reminders.when_after',
                                    )}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={
                            form.processing ||
                            (when !== 'on' && form.data.days === '')
                        }
                    >
                        <Plus aria-hidden="true" />
                        {t('invoicing.collect.reminders.add')}
                    </Button>
                    <InputError
                        message={(form.errors as Record<string, string>).days}
                    />
                </form>
            ) : null}
            <p className="max-w-3xl text-xs text-muted-foreground">
                {t('invoicing.collect.reminders.rules')}
            </p>
        </PageSection>
    );
}
