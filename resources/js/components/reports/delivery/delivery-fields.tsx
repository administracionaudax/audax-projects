import { Building2, Plus, X } from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import InputError from '@/components/input-error';
import { MultiSelectFilter } from '@/components/reports/multi-select-filter';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import type {
    DeliveryFormat,
    DeliveryPerson,
    ReportVersion,
} from '@/types/report-deliveries';
import type { ReportRequestData } from '@/types/reports';

/*
| Campos comunes de «Enviar por correo» y «Programar envío» (D-141): formato, destinatarios
| (personas de la app y correos externos, como mucho 20) y asunto y mensaje opcionales.
*/

/** Como DeliveryRecipients::MAX y ReportDeliveryRequest en el servidor. */
export const MAX_RECIPIENTS = 20;
export const MAX_SUBJECT = 150;
export const MAX_MESSAGE = 2000;

export const FORMATS: readonly DeliveryFormat[] = ['pdf', 'xlsx'];

/**
 * Los campos de los dos diálogos (POST /informes/enviar y /informes/envios). El informe
 * (ReportRequestData, con filtros de cualquier forma) no va en el estado del formulario: se añade
 * al enviar (DeliveryPayload).
 */
export type DeliveryData = {
    title: string;
    formats: DeliveryFormat[];
    recipient_user_ids: number[];
    recipient_emails: string[];
    subject: string;
    message: string;
};

export type DeliveryPayload<T extends DeliveryData = DeliveryData> = T & {
    request: ReportRequestData;
};

export type DeliveryErrors = Partial<Record<string, string>>;

/** Comprobación básica en el navegador; la buena la hace el servidor (email:rfc,strict). */
export function looksLikeEmail(value: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

/** Correos de un texto con comas, puntos y coma o espacios (pegar una lista). */
export function splitEmails(value: string): string[] {
    return value
        .split(/[\s,;]+/)
        .map((email) => email.trim().toLowerCase())
        .filter((email) => email !== '');
}

/** El primer error del servidor de un campo o de sus elementos (`recipient_emails.0`). */
export function firstError(
    errors: DeliveryErrors,
    field: string,
): string | undefined {
    if (errors[field]) {
        return errors[field];
    }

    const nested = Object.keys(errors).find((key) =>
        key.startsWith(`${field}.`),
    );

    return nested ? errors[nested] : undefined;
}

export function FormatsField({
    id,
    value,
    onChange,
    error,
}: {
    id: string;
    value: DeliveryFormat[];
    onChange: (formats: DeliveryFormat[]) => void;
    error?: string;
}) {
    const toggle = (format: DeliveryFormat, checked: boolean) =>
        onChange(
            FORMATS.filter((item) =>
                item === format ? checked : value.includes(item),
            ),
        );

    return (
        <fieldset
            className="grid gap-2"
            aria-describedby={error ? `${id}-error` : undefined}
        >
            <legend className="mb-2 text-sm font-medium">
                {t('deliveries.formats.label')}
            </legend>
            <div className="flex flex-wrap gap-4">
                {FORMATS.map((format) => (
                    <div key={format} className="flex items-center gap-2">
                        <Checkbox
                            id={`${id}-${format}`}
                            checked={value.includes(format)}
                            aria-invalid={error ? true : undefined}
                            onCheckedChange={(checked) =>
                                toggle(format, checked === true)
                            }
                        />
                        <Label
                            htmlFor={`${id}-${format}`}
                            className="font-normal"
                        >
                            {t(`deliveries.formats.${format}`)}
                        </Label>
                    </div>
                ))}
            </div>
            <InputError id={`${id}-error`} message={error} />
        </fieldset>
    );
}

/**
 * Versión del informe que se envía (D-240): la interna y completa o la del cliente (solo las horas
 * que vería en el portal, sin costes ni tarifas). Solo en los informes que la tienen.
 */
export function VersionField({
    id,
    versions,
    value,
    onChange,
}: {
    id: string;
    versions: readonly ReportVersion[];
    value: ReportVersion;
    onChange: (version: ReportVersion) => void;
}) {
    return (
        <fieldset className="grid gap-2" data-test="report-version-field">
            <legend className="mb-2 text-sm font-medium">
                {t('deliveries.version.label')}
            </legend>
            <RadioGroup
                value={value}
                onValueChange={(next) => onChange(next as ReportVersion)}
                className="grid gap-3"
                aria-label={t('deliveries.version.label')}
            >
                {versions.map((version) => (
                    <div key={version} className="flex items-start gap-2">
                        <RadioGroupItem
                            id={`${id}-${version}`}
                            value={version}
                            aria-describedby={`${id}-${version}-hint`}
                            className="mt-0.5"
                        />
                        <div className="grid gap-0.5">
                            <Label
                                htmlFor={`${id}-${version}`}
                                className="font-normal"
                            >
                                {t(`reports.version.${version}`)}
                            </Label>
                            <p
                                id={`${id}-${version}-hint`}
                                className="text-xs text-muted-foreground"
                            >
                                {t(`reports.version.${version}_hint`)}
                            </p>
                        </div>
                    </div>
                ))}
            </RadioGroup>
        </fieldset>
    );
}

/**
 * Destinatarios: el buscador de personas de los filtros de informes (MultiSelectFilter) y un campo
 * de correos externos (Intro, coma o «Añadir»). Si hay externos, avisa de que el informe sale de
 * la empresa. El borrador del correo lo guarda quien lo usa, para añadirlo también al enviar.
 */
export function RecipientsField({
    id,
    people,
    peopleFailed,
    userIds,
    emails,
    draft,
    onUserIds,
    onEmails,
    onDraft,
    errors,
}: {
    id: string;
    people: DeliveryPerson[] | null;
    peopleFailed: boolean;
    userIds: number[];
    emails: string[];
    draft: string;
    onUserIds: (ids: number[]) => void;
    onEmails: (emails: string[]) => void;
    onDraft: (draft: string) => void;
    errors: DeliveryErrors;
}) {
    const [localError, setLocalError] = useState<string | null>(null);
    const total = userIds.length + emails.length;
    const recipientsError =
        errors.recipients ?? firstError(errors, 'recipient_user_ids');
    const emailError = localError ?? firstError(errors, 'recipient_emails');
    const selected = (people ?? []).filter((person) =>
        userIds.includes(person.id),
    );

    const add = () => {
        const candidates = splitEmails(draft);

        if (candidates.length === 0) {
            return;
        }

        const invalid = candidates.find((email) => !looksLikeEmail(email));

        if (invalid) {
            setLocalError(
                t('deliveries.recipients.invalid', { email: invalid }),
            );

            return;
        }

        setLocalError(null);
        onEmails([...new Set([...emails, ...candidates])]);
        onDraft('');
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            add();
        }
    };

    return (
        <fieldset className="grid gap-3" data-test="delivery-recipients">
            <legend className="mb-1 text-sm font-medium">
                {t('deliveries.recipients.label')}{' '}
                <span className="font-normal text-muted-foreground">
                    ·{' '}
                    {total === 1
                        ? t('deliveries.recipients.count_one')
                        : t('deliveries.recipients.count_other', {
                              count: total,
                          })}
                </span>
            </legend>

            <div className="grid gap-2">
                <span id={`${id}-people-label`} className="text-sm">
                    {t('deliveries.recipients.people')}
                </span>
                {peopleFailed ? (
                    <p className="text-sm text-danger" role="alert">
                        {t('deliveries.recipients.people_error')}
                    </p>
                ) : (
                    <MultiSelectFilter
                        label={t('deliveries.recipients.people')}
                        emptyLabel={
                            people === null
                                ? t('deliveries.recipients.people_loading')
                                : t('deliveries.recipients.people_none')
                        }
                        options={people ?? []}
                        value={userIds}
                        onChange={onUserIds}
                        disabled={people === null}
                    />
                )}
                {selected.length > 0 ? (
                    <ul
                        className="flex flex-wrap gap-1.5"
                        aria-label={t('deliveries.recipients.people')}
                    >
                        {selected.map((person) => (
                            <RecipientChip
                                key={person.id}
                                label={person.name}
                                onRemove={() =>
                                    onUserIds(
                                        userIds.filter(
                                            (item) => item !== person.id,
                                        ),
                                    )
                                }
                            />
                        ))}
                    </ul>
                ) : null}
            </div>

            <Field
                id={`${id}-email`}
                label={t('deliveries.recipients.external')}
                help={t('deliveries.recipients.external_help')}
                error={emailError}
            >
                <div className="flex gap-2">
                    <Input
                        id={`${id}-email`}
                        type="email"
                        inputMode="email"
                        autoComplete="off"
                        value={draft}
                        placeholder={t(
                            'deliveries.recipients.external_placeholder',
                        )}
                        aria-invalid={emailError ? true : undefined}
                        aria-describedby={describedBy(`${id}-email`, {
                            help: true,
                            error: emailError,
                        })}
                        onChange={(event) => {
                            setLocalError(null);
                            onDraft(event.target.value);
                        }}
                        onKeyDown={onKeyDown}
                        onBlur={add}
                    />
                    <Button type="button" variant="outline" onClick={add}>
                        <Plus aria-hidden="true" />
                        {t('deliveries.recipients.add')}
                    </Button>
                </div>
            </Field>

            {emails.length > 0 ? (
                <ul
                    className="flex flex-wrap gap-1.5"
                    aria-label={t('deliveries.recipients.external')}
                >
                    {emails.map((email) => (
                        <RecipientChip
                            key={email}
                            label={email}
                            onRemove={() =>
                                onEmails(
                                    emails.filter((item) => item !== email),
                                )
                            }
                        />
                    ))}
                </ul>
            ) : null}

            {emails.length > 0 ? (
                <div
                    className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground"
                    role="status"
                    data-test="delivery-external-warning"
                >
                    <Building2
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    <span>
                        <span className="font-medium">
                            {t('deliveries.recipients.external_warning')}
                        </span>{' '}
                        {t('deliveries.recipients.external_warning_detail')}
                    </span>
                </div>
            ) : null}

            {total > MAX_RECIPIENTS ? (
                <p className="text-sm text-danger" role="alert">
                    {t('deliveries.recipients.max', { max: MAX_RECIPIENTS })}
                </p>
            ) : null}
            <InputError message={recipientsError} />
        </fieldset>
    );
}

function RecipientChip({
    label,
    onRemove,
}: {
    label: string;
    onRemove: () => void;
}) {
    return (
        <li className="inline-flex items-center gap-1 rounded-md border bg-muted py-0.5 pr-0.5 pl-2 text-sm">
            <span className="max-w-60 truncate">{label}</span>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-6"
                aria-label={t('deliveries.recipients.remove', { name: label })}
                onClick={onRemove}
            >
                <X aria-hidden="true" className="size-3.5" />
            </Button>
        </li>
    );
}

export function MessageFields({
    id,
    title,
    subject,
    message,
    onSubject,
    onMessage,
    errors,
}: {
    id: string;
    title: string;
    subject: string;
    message: string;
    onSubject: (subject: string) => void;
    onMessage: (message: string) => void;
    errors: DeliveryErrors;
}) {
    return (
        <div className="grid gap-4">
            <Field
                id={`${id}-subject`}
                label={t('deliveries.fields.subject')}
                optional={t('deliveries.fields.optional')}
                error={errors.subject}
            >
                <Input
                    id={`${id}-subject`}
                    value={subject}
                    maxLength={MAX_SUBJECT}
                    autoComplete="off"
                    placeholder={t('deliveries.fields.subject_placeholder', {
                        title,
                    })}
                    aria-invalid={errors.subject ? true : undefined}
                    aria-describedby={describedBy(`${id}-subject`, {
                        error: errors.subject,
                    })}
                    onChange={(event) => onSubject(event.target.value)}
                />
            </Field>
            <Field
                id={`${id}-message`}
                label={t('deliveries.fields.message')}
                optional={t('deliveries.fields.optional')}
                error={errors.message}
            >
                <Textarea
                    id={`${id}-message`}
                    rows={3}
                    maxLength={MAX_MESSAGE}
                    value={message}
                    aria-invalid={errors.message ? true : undefined}
                    aria-describedby={describedBy(`${id}-message`, {
                        error: errors.message,
                    })}
                    onChange={(event) => onMessage(event.target.value)}
                />
            </Field>
        </div>
    );
}

/**
 * El borrador del campo de correos, añadido a la lista al enviar (si parece un correo). Devuelve
 * null si no lo parece: el diálogo no envía y lo explica.
 */
export function withDraft(emails: string[], draft: string): string[] | null {
    const candidates = splitEmails(draft);

    if (candidates.some((email) => !looksLikeEmail(email))) {
        return null;
    }

    return [...new Set([...emails, ...candidates])];
}
