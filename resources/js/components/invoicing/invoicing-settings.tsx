import { router, useForm, usePage } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    Download,
    ImageUp,
    PencilLine,
    Plus,
    Trash2,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useId, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { PageSection } from '@/components/projects-list/page-section';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency, formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { InvoicingSettingsData } from '@/types';
import { formatQuantity, taxLabel } from './invoicing-format';

const BASE = '/facturacion/ajustes/emision';
const NONE = '__none__';

type FieldSpec = {
    key: string;
    label: string;
    type?: 'text' | 'number' | 'date' | 'textarea' | 'select' | 'switch';
    options?: { value: string; label: string }[];
    hint?: string;
    wide?: boolean;
    disabled?: boolean;
};

/**
 * Un diálogo de alta o edición con sus campos (series, impuestos, servicios y formas de pago):
 * guarda con PUT o POST en Ajustes de la emisión y enseña los errores debajo de cada campo.
 */
function EditDialog({
    trigger,
    title,
    description,
    fields,
    initial,
    url,
    method,
}: {
    trigger: ReactNode;
    title: string;
    description?: string;
    fields: FieldSpec[];
    initial: Record<string, string | boolean | null>;
    url: string;
    method: 'post' | 'put';
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<Record<string, string | boolean | null>>(initial);
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form[method](url, {
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
                    form.setData(initial);
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-xl">
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>
                    {description ?? t('invoicing.settings.dialog_description')}
                </DialogDescription>
                <form
                    onSubmit={submit}
                    noValidate
                    className="grid gap-3 sm:grid-cols-2"
                >
                    {fields.map((field) => {
                        const value = form.data[field.key];
                        const fieldId = `${id}-${field.key}`;

                        return (
                            <div
                                key={field.key}
                                className={cn(
                                    'grid content-start gap-1',
                                    (field.wide ||
                                        field.type === 'textarea' ||
                                        field.type === 'switch') &&
                                        'sm:col-span-2',
                                )}
                            >
                                {field.type === 'switch' ? (
                                    <div className="flex items-center justify-between gap-3 rounded-md border px-3 py-2">
                                        <Label htmlFor={fieldId}>
                                            {field.label}
                                        </Label>
                                        <Switch
                                            id={fieldId}
                                            checked={value === true}
                                            disabled={field.disabled}
                                            onCheckedChange={(checked) =>
                                                form.setData(field.key, checked)
                                            }
                                        />
                                    </div>
                                ) : (
                                    <Label htmlFor={fieldId}>
                                        {field.label}
                                    </Label>
                                )}
                                {field.type === 'textarea' ? (
                                    <Textarea
                                        id={fieldId}
                                        value={String(value ?? '')}
                                        disabled={field.disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                field.key,
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            errors[field.key] ? true : undefined
                                        }
                                    />
                                ) : field.type === 'select' ? (
                                    <Select
                                        value={
                                            value === null || value === ''
                                                ? NONE
                                                : String(value)
                                        }
                                        disabled={field.disabled}
                                        onValueChange={(next) =>
                                            form.setData(
                                                field.key,
                                                next === NONE ? null : next,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id={fieldId}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(field.options ?? []).map(
                                                (option) => (
                                                    <SelectItem
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                ) : field.type === 'switch' ? null : (
                                    <Input
                                        id={fieldId}
                                        type={
                                            field.type === 'date'
                                                ? 'date'
                                                : 'text'
                                        }
                                        inputMode={
                                            field.type === 'number'
                                                ? 'decimal'
                                                : undefined
                                        }
                                        value={String(value ?? '')}
                                        disabled={field.disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                field.key,
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            errors[field.key] ? true : undefined
                                        }
                                    />
                                )}
                                {field.hint ? (
                                    <p className="text-xs text-muted-foreground">
                                        {field.hint}
                                    </p>
                                ) : null}
                                <InputError message={errors[field.key]} />
                            </div>
                        );
                    })}
                    <DialogFooter className="gap-2 sm:col-span-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setOpen(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t('invoicing.settings.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditButton({ label }: { label: string }) {
    return (
        <Button type="button" variant="ghost" size="sm" aria-label={label}>
            <PencilLine aria-hidden="true" />
            {t('invoicing.settings.edit')}
        </Button>
    );
}

function Table({
    caption,
    head,
    children,
}: {
    caption: string;
    head: string[];
    children: ReactNode;
}) {
    return (
        <div
            className="overflow-x-auto rounded-md border"
            role="region"
            aria-label={caption}
            tabIndex={0}
        >
            <table className="tabular w-full min-w-[40rem] text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b text-left text-xs tracking-[0.12em] text-muted-foreground uppercase">
                        {head.map((cell, index) => (
                            <th
                                key={cell}
                                scope="col"
                                className={cn(
                                    'px-3 py-2 font-medium',
                                    index === head.length - 1 && 'text-right',
                                )}
                            >
                                {cell}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

function Archived({ archived }: { archived: boolean }) {
    return archived ? (
        <span className="ml-1 rounded-md bg-muted px-1.5 text-xs text-muted-foreground">
            {t('invoicing.settings.archived')}
        </span>
    ) : null;
}

/** Series y contadores (D-419): formato, desde cuándo, el siguiente número y el primero del año. */
export function SeriesSettings({ data }: { data: InvoicingSettingsData }) {
    return (
        <PageSection
            title={t('invoicing.settings.series_title')}
            description={t('invoicing.settings.series_description')}
        >
            <Table
                caption={t('invoicing.settings.series_title')}
                head={[
                    t('invoicing.settings.series'),
                    t('invoicing.settings.format'),
                    t('invoicing.settings.starts_on'),
                    t('invoicing.settings.next', { year: data.year }),
                    t('invoicing.settings.issued'),
                    '',
                ]}
            >
                {data.series.map((series) => (
                    <tr
                        key={series.id}
                        className="border-b last:border-0"
                        data-test="series-row"
                    >
                        <th
                            scope="row"
                            className="px-3 py-2 text-left font-normal"
                        >
                            {series.code} · {series.name}
                            <Archived archived={series.archived} />
                            <span className="block text-xs text-muted-foreground">
                                {t(
                                    `invoicing.settings.series_kind.${series.kind}`,
                                )}{' '}
                                ·{' '}
                                {t(
                                    series.document_type === 'invoice'
                                        ? 'invoicing.settings.type_invoice'
                                        : 'invoicing.settings.type_credit',
                                )}
                                {series.refund
                                    ? ` · ${t('invoicing.settings.refund', { series: series.refund })}`
                                    : ''}
                                {series.is_default
                                    ? ` · ${t('invoicing.settings.default')}`
                                    : ''}
                            </span>
                        </th>
                        <td className="px-3 py-2 font-mono text-xs">
                            {series.format}
                        </td>
                        <td className="px-3 py-2">
                            {series.starts_on
                                ? formatDate(series.starts_on)
                                : t('invoicing.settings.always')}
                        </td>
                        <td className="px-3 py-2">
                            {series.counters[0]?.next_number}
                        </td>
                        <td className="px-3 py-2">{series.issued}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap">
                            {data.can_manage ? (
                                <>
                                    <EditDialog
                                        trigger={
                                            <EditButton
                                                label={t(
                                                    'invoicing.settings.edit_named',
                                                    { name: series.code },
                                                )}
                                            />
                                        }
                                        title={t(
                                            'invoicing.settings.edit_series',
                                            { code: series.code },
                                        )}
                                        url={`${BASE}/series/${series.id}`}
                                        method="put"
                                        initial={{
                                            name: series.name,
                                            format: series.format,
                                            starts_on: series.starts_on ?? '',
                                            is_default: series.is_default,
                                            archived: series.archived,
                                        }}
                                        fields={[
                                            {
                                                key: 'name',
                                                label: t(
                                                    'invoicing.settings.name',
                                                ),
                                            },
                                            {
                                                key: 'format',
                                                label: t(
                                                    'invoicing.settings.format',
                                                ),
                                                hint:
                                                    series.issued > 0
                                                        ? t(
                                                              'invoicing.settings.format_locked',
                                                          )
                                                        : t(
                                                              'invoicing.settings.format_hint',
                                                          ),
                                                disabled: series.issued > 0,
                                            },
                                            {
                                                key: 'starts_on',
                                                label: t(
                                                    'invoicing.settings.starts_on',
                                                ),
                                                type: 'date',
                                                hint: t(
                                                    'invoicing.settings.starts_on_hint',
                                                ),
                                            },
                                            {
                                                key: 'is_default',
                                                label: t(
                                                    'invoicing.settings.is_default',
                                                ),
                                                type: 'switch',
                                            },
                                            {
                                                key: 'archived',
                                                label: t(
                                                    'invoicing.settings.archive',
                                                ),
                                                type: 'switch',
                                            },
                                        ]}
                                    />
                                    <EditDialog
                                        trigger={
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                            >
                                                {t(
                                                    'invoicing.settings.counter',
                                                )}
                                            </Button>
                                        }
                                        title={t(
                                            'invoicing.settings.counter_title',
                                            { code: series.code },
                                        )}
                                        description={t(
                                            'invoicing.settings.counter_description',
                                        )}
                                        url={`${BASE}/series/${series.id}/contador`}
                                        method="put"
                                        initial={{
                                            year: String(data.year),
                                            first_number: String(
                                                series.counters[0]?.next ?? 1,
                                            ),
                                        }}
                                        fields={[
                                            {
                                                key: 'year',
                                                label: t(
                                                    'invoicing.settings.year',
                                                ),
                                                type: 'select',
                                                options: series.counters.map(
                                                    (counter) => ({
                                                        value: String(
                                                            counter.year,
                                                        ),
                                                        label: `${counter.year} · ${t('invoicing.settings.next_short', { number: counter.next_number })}${counter.used ? ` · ${t('invoicing.settings.counter_used')}` : ''}`,
                                                    }),
                                                ),
                                            },
                                            {
                                                key: 'first_number',
                                                label: t(
                                                    'invoicing.settings.first_number',
                                                ),
                                                type: 'number',
                                            },
                                        ]}
                                    />
                                </>
                            ) : null}
                        </td>
                    </tr>
                ))}
            </Table>
        </PageSection>
    );
}

/** Impuestos (D-423): IVA con su calificación y su mención legal, y las retenciones. */
export function TaxSettings({ data }: { data: InvoicingSettingsData }) {
    const fields = (editing: boolean): FieldSpec[] => [
        {
            key: 'kind',
            label: t('invoicing.settings.tax_kind'),
            type: 'select',
            options: [
                { value: 'vat', label: t('invoicing.settings.kind_vat') },
                {
                    value: 'withholding',
                    label: t('invoicing.settings.kind_withholding'),
                },
            ],
        },
        { key: 'name', label: t('invoicing.settings.name') },
        {
            key: 'rate',
            label: t('invoicing.settings.rate'),
            type: 'number',
            hint: editing ? t('invoicing.settings.tax_locked') : undefined,
        },
        {
            key: 'operation_type',
            label: t('invoicing.settings.operation_type'),
            type: 'select',
            options: data.operation_types.map((type) => ({
                value: type,
                label: t(
                    `invoicing.settings.operation.${type}` as 'invoicing.settings.operation.S1',
                ),
            })),
        },
        {
            key: 'legal_mention',
            label: t('invoicing.settings.legal_mention'),
            type: 'textarea',
            hint: t('invoicing.settings.legal_mention_hint'),
        },
        {
            key: 'legal_mention_en',
            label: t('invoicing.settings.legal_mention_en'),
            type: 'textarea',
        },
        {
            key: 'is_default',
            label: t('invoicing.settings.is_default'),
            type: 'switch',
        },
        {
            key: 'archived',
            label: t('invoicing.settings.archive'),
            type: 'switch',
        },
    ];

    return (
        <PageSection
            title={t('invoicing.settings.taxes_title')}
            description={t('invoicing.settings.taxes_description')}
            action={
                data.can_manage ? (
                    <EditDialog
                        trigger={
                            <Button type="button" variant="outline" size="sm">
                                <Plus aria-hidden="true" />
                                {t('invoicing.settings.add_tax')}
                            </Button>
                        }
                        title={t('invoicing.settings.add_tax')}
                        url={`${BASE}/impuestos`}
                        method="post"
                        initial={{
                            kind: 'vat',
                            name: '',
                            rate: '',
                            operation_type: 'S1',
                            legal_mention: '',
                            legal_mention_en: '',
                            is_default: false,
                            archived: false,
                        }}
                        fields={fields(false)}
                    />
                ) : null
            }
        >
            <Table
                caption={t('invoicing.settings.taxes_title')}
                head={[
                    t('invoicing.settings.name'),
                    t('invoicing.settings.rate'),
                    t('invoicing.settings.operation_type'),
                    t('invoicing.settings.legal_mention'),
                    '',
                ]}
            >
                {data.taxes.map((tax) => (
                    <tr key={tax.id} className="border-b last:border-0">
                        <th
                            scope="row"
                            className="px-3 py-2 text-left font-normal"
                        >
                            {tax.name}
                            <Archived archived={tax.archived} />
                            {tax.is_default ? (
                                <span className="block text-xs text-muted-foreground">
                                    {t('invoicing.settings.default')}
                                </span>
                            ) : null}
                        </th>
                        <td className="px-3 py-2">
                            {formatQuantity(tax.rate)} %
                        </td>
                        <td className="px-3 py-2">
                            {tax.kind === 'vat'
                                ? taxLabel(tax.operation_type, tax.rate)
                                : t('invoicing.settings.kind_withholding')}
                        </td>
                        <td className="max-w-80 px-3 py-2 text-xs text-muted-foreground">
                            {tax.legal_mention ?? '—'}
                        </td>
                        <td className="px-3 py-2 text-right">
                            {data.can_manage ? (
                                <EditDialog
                                    trigger={
                                        <EditButton
                                            label={t(
                                                'invoicing.settings.edit_named',
                                                { name: tax.name },
                                            )}
                                        />
                                    }
                                    title={t('invoicing.settings.edit_tax', {
                                        name: tax.name,
                                    })}
                                    url={`${BASE}/impuestos/${tax.id}`}
                                    method="put"
                                    initial={{
                                        kind: tax.kind,
                                        name: tax.name,
                                        rate: tax.rate,
                                        operation_type: tax.operation_type,
                                        legal_mention: tax.legal_mention ?? '',
                                        legal_mention_en:
                                            tax.legal_mention_en ?? '',
                                        is_default: tax.is_default,
                                        archived: tax.archived,
                                    }}
                                    fields={fields(true)}
                                />
                            ) : null}
                        </td>
                    </tr>
                ))}
            </Table>
        </PageSection>
    );
}

/** El catálogo de servicios (D-425), con la importación desde las líneas de Holded. */
export function ServiceSettings({ data }: { data: InvoicingSettingsData }) {
    const taxOptions = [
        { value: NONE, label: t('invoicing.editor.no_tax') },
        ...data.taxes
            .filter((tax) => tax.kind === 'vat' && !tax.archived)
            .map((tax) => ({ value: String(tax.id), label: tax.name })),
    ];
    const fields: FieldSpec[] = [
        {
            key: 'code',
            label: t('invoicing.settings.code'),
            hint: t('invoicing.settings.code_hint'),
        },
        { key: 'name', label: t('invoicing.settings.name') },
        {
            key: 'description',
            label: t('invoicing.settings.description'),
            type: 'textarea',
        },
        {
            key: 'unit',
            label: t('invoicing.editor.unit'),
            type: 'select',
            options: data.units.map((unit) => ({
                value: unit,
                label: t(`invoicing.unit.${unit}_other`),
            })),
        },
        {
            key: 'unit_price',
            label: t('invoicing.editor.price'),
            type: 'number',
        },
        {
            key: 'tax_rate_id',
            label: t('invoicing.editor.tax'),
            type: 'select',
            options: taxOptions,
        },
        {
            key: 'archived',
            label: t('invoicing.settings.archive'),
            type: 'switch',
        },
    ];

    return (
        <PageSection
            title={t('invoicing.settings.services_title')}
            description={t('invoicing.settings.services_description')}
            action={
                data.can_manage ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `${BASE}/servicios/importar`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            data-test="import-services"
                        >
                            <Download aria-hidden="true" />
                            {t('invoicing.settings.import_services')}
                        </Button>
                        <EditDialog
                            trigger={
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                >
                                    <Plus aria-hidden="true" />
                                    {t('invoicing.settings.add_service')}
                                </Button>
                            }
                            title={t('invoicing.settings.add_service')}
                            url={`${BASE}/servicios`}
                            method="post"
                            initial={{
                                code: '',
                                name: '',
                                description: '',
                                unit: 'hour',
                                unit_price: '0',
                                tax_rate_id: String(
                                    data.taxes.find(
                                        (tax) =>
                                            tax.is_default &&
                                            tax.kind === 'vat',
                                    )?.id ?? '',
                                ),
                                archived: false,
                            }}
                            fields={fields}
                        />
                    </div>
                ) : null
            }
        >
            {data.services.length === 0 ? (
                <p className="rounded-md border border-dashed px-4 py-6 text-sm text-muted-foreground">
                    {t('invoicing.settings.no_services')}
                </p>
            ) : (
                <Table
                    caption={t('invoicing.settings.services_title')}
                    head={[
                        t('invoicing.settings.code'),
                        t('invoicing.settings.name'),
                        t('invoicing.editor.unit'),
                        t('invoicing.editor.price'),
                        t('invoicing.editor.tax'),
                        '',
                    ]}
                >
                    {data.services.map((service) => (
                        <tr key={service.id} className="border-b last:border-0">
                            <td className="px-3 py-2 font-mono text-xs">
                                {service.code ?? '—'}
                            </td>
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-normal"
                            >
                                {service.name}
                                <Archived archived={service.archived} />
                            </th>
                            <td className="px-3 py-2">
                                {t(`invoicing.unit.${service.unit}_other`)}
                            </td>
                            <td className="px-3 py-2">
                                {formatCurrency(service.unit_price)}
                            </td>
                            <td className="px-3 py-2">
                                {data.taxes.find(
                                    (tax) => tax.id === service.tax_rate_id,
                                )?.name ?? '—'}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {data.can_manage ? (
                                    <EditDialog
                                        trigger={
                                            <EditButton
                                                label={t(
                                                    'invoicing.settings.edit_named',
                                                    { name: service.name },
                                                )}
                                            />
                                        }
                                        title={t(
                                            'invoicing.settings.edit_service',
                                            { name: service.name },
                                        )}
                                        url={`${BASE}/servicios/${service.id}`}
                                        method="put"
                                        initial={{
                                            code: service.code ?? '',
                                            name: service.name,
                                            description:
                                                service.description ?? '',
                                            unit: service.unit,
                                            unit_price: service.unit_price,
                                            tax_rate_id:
                                                service.tax_rate_id === null
                                                    ? null
                                                    : String(
                                                          service.tax_rate_id,
                                                      ),
                                            archived: service.archived,
                                        }}
                                        fields={fields}
                                    />
                                ) : null}
                            </td>
                        </tr>
                    ))}
                </Table>
            )}
        </PageSection>
    );
}

/** Formas de pago (D-423): texto del PDF, IBAN y días hasta el vencimiento. */
export function PaymentMethodSettings({
    data,
}: {
    data: InvoicingSettingsData;
}) {
    const fields: FieldSpec[] = [
        { key: 'name', label: t('invoicing.settings.name') },
        {
            key: 'due_days',
            label: t('invoicing.settings.due_days'),
            type: 'number',
        },
        {
            key: 'document_text',
            label: t('invoicing.settings.document_text'),
            type: 'textarea',
            hint: t('invoicing.settings.document_text_hint'),
        },
        {
            key: 'document_text_en',
            label: t('invoicing.settings.document_text_en'),
            type: 'textarea',
        },
        {
            key: 'iban',
            label: t('invoicing.settings.iban'),
            hint: t('invoicing.settings.iban_hint'),
            wide: true,
        },
        {
            key: 'is_default',
            label: t('invoicing.settings.is_default'),
            type: 'switch',
        },
        {
            key: 'archived',
            label: t('invoicing.settings.archive'),
            type: 'switch',
        },
    ];

    return (
        <PageSection
            title={t('invoicing.settings.payment_title')}
            description={t('invoicing.settings.payment_description')}
            action={
                data.can_manage ? (
                    <EditDialog
                        trigger={
                            <Button type="button" variant="outline" size="sm">
                                <Plus aria-hidden="true" />
                                {t('invoicing.settings.add_payment')}
                            </Button>
                        }
                        title={t('invoicing.settings.add_payment')}
                        url={`${BASE}/formas-de-pago`}
                        method="post"
                        initial={{
                            name: '',
                            due_days: '30',
                            document_text: '',
                            document_text_en: '',
                            iban: '',
                            is_default: false,
                            archived: false,
                        }}
                        fields={fields}
                    />
                ) : null
            }
        >
            <Table
                caption={t('invoicing.settings.payment_title')}
                head={[
                    t('invoicing.settings.name'),
                    t('invoicing.settings.due_days'),
                    t('invoicing.settings.document_text'),
                    '',
                ]}
            >
                {data.payment_methods.map((method) => (
                    <tr key={method.id} className="border-b last:border-0">
                        <th
                            scope="row"
                            className="px-3 py-2 text-left font-normal"
                        >
                            {method.name}
                            <Archived archived={method.archived} />
                            {method.is_default ? (
                                <span className="block text-xs text-muted-foreground">
                                    {t('invoicing.settings.default')}
                                </span>
                            ) : null}
                        </th>
                        <td className="px-3 py-2">
                            {method.due_days === null
                                ? '—'
                                : t('invoicing.settings.days', {
                                      days: method.due_days,
                                  })}
                        </td>
                        <td className="max-w-80 px-3 py-2 text-xs text-muted-foreground">
                            {method.document_text ?? '—'}
                        </td>
                        <td className="px-3 py-2 text-right">
                            {data.can_manage ? (
                                <EditDialog
                                    trigger={
                                        <EditButton
                                            label={t(
                                                'invoicing.settings.edit_named',
                                                { name: method.name },
                                            )}
                                        />
                                    }
                                    title={t(
                                        'invoicing.settings.edit_payment',
                                        { name: method.name },
                                    )}
                                    url={`${BASE}/formas-de-pago/${method.id}`}
                                    method="put"
                                    initial={{
                                        name: method.name,
                                        due_days:
                                            method.due_days === null
                                                ? ''
                                                : String(method.due_days),
                                        document_text:
                                            method.document_text ?? '',
                                        document_text_en:
                                            method.document_text_en ?? '',
                                        iban: method.iban ?? '',
                                        is_default: method.is_default,
                                        archived: method.archived,
                                    }}
                                    fields={fields}
                                />
                            ) : null}
                        </td>
                    </tr>
                ))}
            </Table>
        </PageSection>
    );
}

/** La plantilla del PDF (G-4, D-426): pie, texto legal, logo y si la ha validado la gestoría. */
export function DocumentSettings({ data }: { data: InvoicingSettingsData }) {
    const id = useId();
    const file = useRef<HTMLInputElement>(null);
    const form = useForm({
        footer: data.document.footer ?? '',
        legal_text: data.document.legal_text ?? '',
        validated: data.document.validated,
    });
    const pageErrors = usePage().props.errors as Record<
        string,
        string | undefined
    >;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(`${BASE}/documento`, { preserveScroll: true });
    };

    return (
        <PageSection
            title={t('invoicing.settings.document_title')}
            description={t('invoicing.settings.document_description')}
        >
            <p
                className={cn(
                    'flex items-start gap-2 rounded-md px-3 py-2 text-sm',
                    data.document.validated
                        ? 'bg-success-soft'
                        : 'bg-warning-soft',
                )}
                data-test="template-validation"
            >
                {data.document.validated ? (
                    <CircleCheck
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-success"
                    />
                ) : (
                    <CircleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                )}
                {t(
                    data.document.validated
                        ? 'invoicing.settings.validated'
                        : 'invoicing.settings.pending_validation',
                )}
            </p>
            <form onSubmit={submit} noValidate className="grid max-w-3xl gap-4">
                <div className="grid gap-1">
                    <Label htmlFor={`${id}-footer`}>
                        {t('invoicing.settings.footer')}
                    </Label>
                    <Textarea
                        id={`${id}-footer`}
                        value={form.data.footer}
                        maxLength={1000}
                        disabled={!data.can_manage}
                        onChange={(event) =>
                            form.setData('footer', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('invoicing.settings.footer_hint')}
                    </p>
                    <InputError message={form.errors.footer} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor={`${id}-legal`}>
                        {t('invoicing.settings.legal_text')}
                    </Label>
                    <Textarea
                        id={`${id}-legal`}
                        value={form.data.legal_text}
                        maxLength={2000}
                        disabled={!data.can_manage}
                        onChange={(event) =>
                            form.setData('legal_text', event.target.value)
                        }
                    />
                    <InputError message={form.errors.legal_text} />
                </div>
                <div className="flex items-center justify-between gap-3 rounded-md border px-3 py-2">
                    <Label htmlFor={`${id}-validated`}>
                        {t('invoicing.settings.validated_switch')}
                    </Label>
                    <Switch
                        id={`${id}-validated`}
                        checked={form.data.validated}
                        disabled={!data.can_manage}
                        onCheckedChange={(checked) =>
                            form.setData('validated', checked)
                        }
                    />
                </div>
                {data.can_manage ? (
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {t('invoicing.settings.save')}
                        </Button>
                    </div>
                ) : null}
            </form>

            <div className="grid max-w-3xl gap-2">
                <h3 className="text-base font-normal">
                    {t('invoicing.settings.logo')}
                </h3>
                <p className="text-sm text-muted-foreground">
                    {t(
                        data.document.logo
                            ? 'invoicing.settings.logo_custom'
                            : 'invoicing.settings.logo_default',
                    )}
                </p>
                {data.can_manage ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            ref={file}
                            type="file"
                            accept="image/png,image/jpeg"
                            className="sr-only"
                            id={`${id}-logo`}
                            onChange={(event) => {
                                const chosen = event.target.files?.[0] ?? null;
                                if (chosen) {
                                    router.post(
                                        `${BASE}/documento/logo`,
                                        { logo: chosen },
                                        {
                                            forceFormData: true,
                                            preserveScroll: true,
                                        },
                                    );
                                }
                            }}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => file.current?.click()}
                        >
                            <ImageUp aria-hidden="true" />
                            {t('invoicing.settings.upload_logo')}
                        </Button>
                        {data.document.logo ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.delete(`${BASE}/documento/logo`, {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                <Trash2 aria-hidden="true" />
                                {t('invoicing.settings.remove_logo')}
                            </Button>
                        ) : null}
                        <InputError message={pageErrors.logo} />
                    </div>
                ) : null}
            </div>
        </PageSection>
    );
}
