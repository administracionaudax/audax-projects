import { Head, Link, useForm } from '@inertiajs/react';
import { CircleAlert, Eye, FileCheck2, Save } from 'lucide-react';
import type { FormEvent } from 'react';
import { useId, useRef } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import { SearchableSelect } from '@/components/domain/searchable-select';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
import { ClientFiscalDialog } from '@/components/invoicing/client-fiscal-dialog';
import { EditorLines, newLine } from '@/components/invoicing/editor-lines';
import { InvoiceTotalsPanel } from '@/components/invoicing/invoice-totals-panel';
import { computeTotals } from '@/components/invoicing/totals';
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
import { Textarea } from '@/components/ui/textarea';
import { useAbilities } from '@/hooks/use-auth';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    EditorLine,
    EditorOptions,
    FiscalField,
    SalesDocumentForm,
} from '@/types';

type Props = {
    document: SalesDocumentForm | null;
    defaults: {
        client_id: number | null;
        project_id: number | null;
        series_id: number | null;
        issue_date: string;
        payment_method_id: number | null;
    } | null;
    options: EditorOptions;
    preview_token: string;
};

type FormData = {
    client_id: number | null;
    series_id: number | null;
    issue_date: string;
    operation_date: string | null;
    due_date: string | null;
    payment_method_id: number | null;
    withholding_rate_id: number | null;
    body: string;
    internal_note: string;
    customer_reference: string;
    project_id: number | null;
    hour_bank_id: number | null;
    lines: EditorLine[];
    after: 'emitir' | null;
};

const NONE = '__none__';

function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const result = new Date(Date.UTC(year, month - 1, day + days));

    return result.toISOString().slice(0, 10);
}

/** Nombres de campo de un formulario HTML («lines[0][kind]») para la vista previa sin guardar. */
function flatten(
    value: unknown,
    prefix: string,
    out: [string, string][],
): [string, string][] {
    if (value === null || value === undefined) {
        out.push([prefix, '']);
    } else if (Array.isArray(value)) {
        value.forEach((item, index) =>
            flatten(item, `${prefix}[${index}]`, out),
        );
    } else if (typeof value === 'object') {
        for (const [key, item] of Object.entries(value)) {
            flatten(item, prefix === '' ? key : `${prefix}[${key}]`, out);
        }
    } else {
        out.push([prefix, String(value as string | number | boolean)]);
    }

    return out;
}

/**
 * El editor de factura (PLAN-EMISION §6.2, «Nueva factura»; D-428), con el aspecto de Audax: el
 * cliente con su ficha fiscal (si falta algo, se avisa y se completa aquí mismo), la serie y las
 * fechas, la forma de pago y el vencimiento, el proyecto y la bolsa, las líneas y, a la derecha, los
 * totales con el cuadro de impuestos (calculados como en el servidor), la vista previa del PDF sin
 * guardar, «Guardar borrador» y, con manage-billing, «Guardar y emitir…».
 */
export default function InvoiceEditor({
    document,
    defaults,
    options,
    preview_token: previewToken,
}: Props) {
    const id = useId();
    const can = useAbilities();
    const previewForm = useRef<HTMLFormElement>(null);
    const defaultTax =
        options.taxes.find((tax) => tax.is_default)?.id ??
        options.taxes[0]?.id ??
        null;
    const today = options.today;

    const initial: FormData = document
        ? {
              client_id: document.client_id,
              series_id: document.series_id,
              issue_date: document.issue_date,
              operation_date: document.operation_date,
              due_date: document.due_date,
              payment_method_id: document.payment_method_id,
              withholding_rate_id: document.withholding_rate_id,
              body: document.body ?? '',
              internal_note: document.internal_note ?? '',
              customer_reference: document.customer_reference ?? '',
              project_id: document.project_id,
              hour_bank_id: document.hour_bank_id,
              lines: document.lines.map((line, index) => ({
                  ...line,
                  key: `line-${line.id ?? index}`,
              })),
              after: null,
          }
        : {
              client_id: defaults?.client_id ?? null,
              series_id: defaults?.series_id ?? null,
              issue_date: defaults?.issue_date ?? today,
              operation_date: null,
              due_date: (() => {
                  const method = options.payment_methods.find(
                      (one) => one.id === defaults?.payment_method_id,
                  );

                  return method?.due_days != null
                      ? addDays(defaults?.issue_date ?? today, method.due_days)
                      : null;
              })(),
              payment_method_id: defaults?.payment_method_id ?? null,
              withholding_rate_id: null,
              body: '',
              internal_note: '',
              customer_reference: '',
              project_id: defaults?.project_id ?? null,
              hour_bank_id: null,
              lines: [newLine('item', defaultTax)],
              after: null,
          };

    const form = useForm<FormData>(initial);
    const data = form.data;
    const errors = form.errors as Record<string, string | undefined>;

    const client =
        options.clients.find((one) => one.id === data.client_id) ?? null;
    const series =
        options.series.find((one) => one.id === data.series_id) ?? null;
    const projects = options.projects.filter(
        (project) => project.client_id === data.client_id,
    );
    const project =
        options.projects.find((one) => one.id === data.project_id) ?? null;
    const withholding =
        options.withholdings.find(
            (one) => one.id === data.withholding_rate_id,
        ) ?? null;

    const items = data.lines.filter((line) => line.kind === 'item');
    const totals = computeTotals(
        items.map((line) => {
            const tax = options.taxes.find(
                (one) => one.id === line.tax_rate_id,
            );

            return {
                quantity: line.quantity,
                unit_price: line.unit_price,
                discount_pct: line.discount_pct,
                tax: tax
                    ? {
                          key: String(tax.id),
                          rate: tax.rate,
                          operation_type: tax.operation_type ?? 'S1',
                      }
                    : null,
            };
        }),
        withholding?.rate ?? null,
    );
    const bases = Object.fromEntries(
        items.map((line, index) => [
            line.key,
            totals.lines[index]?.base ?? '0.00',
        ]),
    );

    const setClient = (value: string) => {
        const next =
            options.clients.find((one) => String(one.id) === value) ?? null;
        const keepsProject = project !== null && project.client_id === next?.id;
        const days = next?.profile.payment_days ?? null;

        form.setData((current) => ({
            ...current,
            client_id: next?.id ?? null,
            project_id: keepsProject ? current.project_id : null,
            hour_bank_id: keepsProject ? current.hour_bank_id : null,
            due_date:
                days !== null
                    ? addDays(current.issue_date, days)
                    : current.due_date,
        }));
    };

    const setPaymentMethod = (value: string) => {
        const method =
            options.payment_methods.find((one) => String(one.id) === value) ??
            null;

        form.setData((current) => ({
            ...current,
            payment_method_id: method?.id ?? null,
            due_date:
                method?.due_days != null
                    ? addDays(current.issue_date, method.due_days)
                    : current.due_date,
        }));
    };

    const payload = () => ({
        ...data,
        lines: data.lines.map(({ key: _key, ...line }) => line),
    });

    const save = (after: 'emitir' | null) => (event?: FormEvent) => {
        event?.preventDefault();
        form.transform(() => ({ ...payload(), after }));

        if (document) {
            form.put(`/facturacion/documentos/${document.id}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/facturacion/documentos', { preserveScroll: true });
        }
    };

    const preview = () => previewForm.current?.submit();

    const missing: FiscalField[] = client?.missing ?? [];
    const title = document
        ? t('invoicing.editor.title_draft')
        : t('invoicing.editor.title_new');

    return (
        <>
            <Head title={title} />

            <form
                onSubmit={save(null)}
                noValidate
                className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6"
                data-test="invoice-editor"
            >
                <BillingHeader
                    current="facturas"
                    kicker={t('invoicing.editor.kicker')}
                    title={title}
                    description={t('invoicing.editor.description_page')}
                />

                {options.issuer_missing.length > 0 ? (
                    <p
                        className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm"
                        role="status"
                        data-test="issuer-missing"
                    >
                        <CircleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-warning"
                        />
                        <span>
                            {t('invoicing.editor.issuer_missing', {
                                fields: options.issuer_missing
                                    .map((field) =>
                                        t(`invoicing.field.${field}`),
                                    )
                                    .join(', '),
                            })}{' '}
                            <Link
                                href="/facturacion/ajustes"
                                className="text-primary-text underline"
                            >
                                {t('invoicing.editor.issuer_link')}
                            </Link>
                        </span>
                    </p>
                ) : null}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="grid min-w-0 content-start gap-6">
                        <PageSection
                            title={t('invoicing.editor.client_section')}
                        >
                            <div className="grid gap-4 rounded-md border bg-card p-4 sm:grid-cols-2">
                                <div className="grid content-start gap-1 sm:col-span-2">
                                    <Label htmlFor={`${id}-client`}>
                                        {t('invoicing.editor.client')}
                                    </Label>
                                    <SearchableSelect
                                        id={`${id}-client`}
                                        value={
                                            data.client_id === null
                                                ? null
                                                : String(data.client_id)
                                        }
                                        placeholder={t(
                                            'invoicing.editor.choose_client',
                                        )}
                                        groups={[
                                            {
                                                label: null,
                                                options: options.clients.map(
                                                    (one) => ({
                                                        value: String(one.id),
                                                        label: one.name,
                                                        hint: one.profile
                                                            .tax_id,
                                                        keywords: [
                                                            one.profile.tax_id,
                                                            one.profile
                                                                .legal_name,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' '),
                                                    }),
                                                ),
                                            },
                                        ]}
                                        onChange={setClient}
                                        search={t(
                                            'invoicing.editor.search_client',
                                        )}
                                        empty={t('invoicing.editor.no_clients')}
                                        invalid={errors.client_id !== undefined}
                                        dataTest="editor-client"
                                    />
                                    <InputError message={errors.client_id} />
                                    {client ? (
                                        <ClientFiscalSummary
                                            client={client}
                                            missing={missing}
                                        />
                                    ) : null}
                                </div>

                                <div className="grid content-start gap-1">
                                    <Label htmlFor={`${id}-series`}>
                                        {t('invoicing.editor.series')}
                                    </Label>
                                    <Select
                                        value={
                                            data.series_id === null
                                                ? NONE
                                                : String(data.series_id)
                                        }
                                        onValueChange={(value) =>
                                            form.setData(
                                                'series_id',
                                                value === NONE
                                                    ? null
                                                    : Number(value),
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id={`${id}-series`}
                                            className="w-full"
                                            data-test="editor-series"
                                        >
                                            <SelectValue
                                                placeholder={t(
                                                    'invoicing.editor.choose_series',
                                                )}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.series.map((one) => (
                                                <SelectItem
                                                    key={one.id}
                                                    value={String(one.id)}
                                                >
                                                    {one.code} · {one.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {series ? (
                                        <p className="text-xs text-muted-foreground">
                                            {series.is_test
                                                ? t(
                                                      'invoicing.editor.series_test',
                                                  )
                                                : series.starts_on &&
                                                    series.starts_on >
                                                        data.issue_date
                                                  ? t(
                                                        'invoicing.editor.series_starts',
                                                        {
                                                            date: formatDate(
                                                                series.starts_on,
                                                            ),
                                                        },
                                                    )
                                                  : t(
                                                        'invoicing.editor.series_next',
                                                        { number: series.next },
                                                    )}
                                        </p>
                                    ) : null}
                                    <InputError message={errors.series_id} />
                                </div>

                                <div className="grid content-start gap-1">
                                    <Label htmlFor={`${id}-reference`}>
                                        {t('invoicing.editor.reference')}
                                    </Label>
                                    <Input
                                        id={`${id}-reference`}
                                        value={data.customer_reference}
                                        maxLength={120}
                                        onChange={(event) =>
                                            form.setData(
                                                'customer_reference',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.customer_reference}
                                    />
                                </div>

                                <div className="grid content-start gap-1">
                                    <Label htmlFor={`${id}-issue`}>
                                        {t('invoicing.editor.issue_date')}
                                    </Label>
                                    <DatePicker
                                        id={`${id}-issue`}
                                        value={data.issue_date}
                                        clearable={false}
                                        max={today}
                                        onChange={(value) =>
                                            form.setData(
                                                'issue_date',
                                                value ?? today,
                                            )
                                        }
                                        invalid={
                                            errors.issue_date !== undefined
                                        }
                                    />
                                    <InputError message={errors.issue_date} />
                                </div>

                                <div className="grid content-start gap-1">
                                    <Label htmlFor={`${id}-operation`}>
                                        {t('invoicing.editor.operation_date')}
                                    </Label>
                                    <DatePicker
                                        id={`${id}-operation`}
                                        value={data.operation_date}
                                        placeholder={t(
                                            'invoicing.editor.operation_date_same',
                                        )}
                                        onChange={(value) =>
                                            form.setData(
                                                'operation_date',
                                                value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.operation_date}
                                    />
                                </div>

                                <div className="grid content-start gap-1">
                                    <Label htmlFor={`${id}-payment`}>
                                        {t('invoicing.editor.payment_method')}
                                    </Label>
                                    <Select
                                        value={
                                            data.payment_method_id === null
                                                ? NONE
                                                : String(data.payment_method_id)
                                        }
                                        onValueChange={setPaymentMethod}
                                    >
                                        <SelectTrigger
                                            id={`${id}-payment`}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>
                                                {t(
                                                    'invoicing.editor.no_payment_method',
                                                )}
                                            </SelectItem>
                                            {options.payment_methods.map(
                                                (method) => (
                                                    <SelectItem
                                                        key={method.id}
                                                        value={String(
                                                            method.id,
                                                        )}
                                                    >
                                                        {method.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid content-start gap-1">
                                    <Label htmlFor={`${id}-due`}>
                                        {t('invoicing.editor.due_date')}
                                    </Label>
                                    <DatePicker
                                        id={`${id}-due`}
                                        value={data.due_date}
                                        min={data.issue_date}
                                        onChange={(value) =>
                                            form.setData('due_date', value)
                                        }
                                        invalid={errors.due_date !== undefined}
                                    />
                                    <InputError message={errors.due_date} />
                                </div>

                                <div className="grid content-start gap-1">
                                    <Label htmlFor={`${id}-project`}>
                                        {t('invoicing.editor.project')}
                                    </Label>
                                    <SearchableSelect
                                        id={`${id}-project`}
                                        value={
                                            data.project_id === null
                                                ? NONE
                                                : String(data.project_id)
                                        }
                                        placeholder={t(
                                            'invoicing.editor.choose_project',
                                        )}
                                        groups={[
                                            {
                                                label: null,
                                                options: [
                                                    {
                                                        value: NONE,
                                                        label: t(
                                                            'invoicing.editor.no_project',
                                                        ),
                                                    },
                                                    ...projects.map((one) => ({
                                                        value: String(one.id),
                                                        label: `${one.code} · ${one.name}`,
                                                        keywords: one.code,
                                                    })),
                                                ],
                                            },
                                        ]}
                                        onChange={(value) =>
                                            form.setData((current) => ({
                                                ...current,
                                                project_id:
                                                    value === NONE
                                                        ? null
                                                        : Number(value),
                                                hour_bank_id: null,
                                            }))
                                        }
                                        search={t(
                                            'invoicing.editor.search_project',
                                        )}
                                        empty={t(
                                            'invoicing.editor.no_projects',
                                        )}
                                        disabled={client === null}
                                        dataTest="editor-project"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t('invoicing.editor.project_hint')}
                                    </p>
                                    <InputError message={errors.project_id} />
                                </div>

                                {project && project.banks.length > 0 ? (
                                    <div className="grid content-start gap-1">
                                        <Label htmlFor={`${id}-bank`}>
                                            {t('invoicing.editor.bank')}
                                        </Label>
                                        <Select
                                            value={
                                                data.hour_bank_id === null
                                                    ? NONE
                                                    : String(data.hour_bank_id)
                                            }
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'hour_bank_id',
                                                    value === NONE
                                                        ? null
                                                        : Number(value),
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id={`${id}-bank`}
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>
                                                    {t(
                                                        'invoicing.editor.no_bank',
                                                    )}
                                                </SelectItem>
                                                {project.banks.map((bank) => (
                                                    <SelectItem
                                                        key={bank.id}
                                                        value={String(bank.id)}
                                                    >
                                                        {bank.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.hour_bank_id}
                                        />
                                    </div>
                                ) : null}
                            </div>
                        </PageSection>

                        <PageSection title={t('invoicing.editor.lines')}>
                            <EditorLines
                                lines={data.lines}
                                bases={bases}
                                services={options.services}
                                taxes={options.taxes}
                                errors={errors}
                                defaultTax={defaultTax}
                                onChange={(lines) =>
                                    form.setData('lines', lines)
                                }
                            />
                            <InputError message={errors.lines} />
                        </PageSection>

                        <PageSection title={t('invoicing.editor.texts')}>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid content-start gap-1">
                                    <Label htmlFor={`${id}-body`}>
                                        {t('invoicing.editor.body')}
                                    </Label>
                                    <Textarea
                                        id={`${id}-body`}
                                        value={data.body}
                                        maxLength={5000}
                                        placeholder={t(
                                            'invoicing.editor.body_placeholder',
                                        )}
                                        onChange={(event) =>
                                            form.setData(
                                                'body',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t('invoicing.editor.body_hint')}
                                    </p>
                                    <InputError message={errors.body} />
                                </div>
                                <div className="grid content-start gap-1">
                                    <Label htmlFor={`${id}-note`}>
                                        {t('invoicing.editor.internal_note')}
                                    </Label>
                                    <Textarea
                                        id={`${id}-note`}
                                        value={data.internal_note}
                                        maxLength={5000}
                                        onChange={(event) =>
                                            form.setData(
                                                'internal_note',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'invoicing.editor.internal_note_hint',
                                        )}
                                    </p>
                                    <InputError
                                        message={errors.internal_note}
                                    />
                                </div>
                            </div>
                        </PageSection>
                    </div>

                    <aside className="grid content-start gap-4 lg:sticky lg:top-4 lg:self-start">
                        <InvoiceTotalsPanel
                            totals={totals}
                            taxes={options.taxes}
                            withholdingLabel={withholding?.name ?? null}
                        />

                        <div className="grid gap-1">
                            <Label htmlFor={`${id}-withholding`}>
                                {t('invoicing.editor.withholding')}
                            </Label>
                            <Select
                                value={
                                    data.withholding_rate_id === null
                                        ? NONE
                                        : String(data.withholding_rate_id)
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'withholding_rate_id',
                                        value === NONE ? null : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger
                                    id={`${id}-withholding`}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        {t('invoicing.editor.no_withholding')}
                                    </SelectItem>
                                    {options.withholdings.map((one) => (
                                        <SelectItem
                                            key={one.id}
                                            value={String(one.id)}
                                        >
                                            {one.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            className={cn(
                                'grid gap-2',
                                'max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-20 max-lg:grid-cols-2 max-lg:border-t max-lg:bg-background max-lg:p-3',
                            )}
                        >
                            {can.manageBilling ? (
                                <Button
                                    type="button"
                                    onClick={() => save('emitir')()}
                                    disabled={form.processing}
                                    className="max-lg:col-span-2"
                                    data-test="editor-save-issue"
                                >
                                    <FileCheck2 aria-hidden="true" />
                                    {t('invoicing.editor.save_issue')}
                                </Button>
                            ) : null}
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={form.processing}
                                data-test="editor-save"
                            >
                                <Save aria-hidden="true" />
                                {t('invoicing.editor.save')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={preview}
                                disabled={data.client_id === null}
                                data-test="editor-preview"
                            >
                                <Eye aria-hidden="true" />
                                {t('invoicing.editor.preview')}
                            </Button>
                            <p className="text-xs text-muted-foreground max-lg:hidden">
                                {t(
                                    can.manageBilling
                                        ? 'invoicing.editor.save_hint'
                                        : 'invoicing.editor.save_hint_prepare',
                                )}
                            </p>
                        </div>
                    </aside>
                </div>
                {/* Hueco para la barra fija de abajo en el móvil. */}
                <div className="h-24 lg:hidden" aria-hidden="true" />
            </form>

            {/* Vista previa sin guardar: el formulario tal cual, en otra pestaña. */}
            <form
                ref={previewForm}
                method="post"
                action="/facturacion/documentos/vista-previa"
                target="_blank"
                className="hidden"
                aria-hidden="true"
            >
                <input type="hidden" name="_token" value={previewToken} />
                {flatten(payload(), '', []).map(([name, value]) => (
                    <input key={name} type="hidden" name={name} value={value} />
                ))}
            </form>
        </>
    );
}

function ClientFiscalSummary({
    client,
    missing,
}: {
    client: EditorOptions['clients'][number];
    missing: FiscalField[];
}) {
    const profile = client.profile;
    const address = [
        profile.address,
        [profile.postal_code, profile.city].filter(Boolean).join(' '),
        profile.country_code !== 'ES' ? profile.country_code : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <div
            className={cn(
                'mt-1 flex flex-wrap items-start justify-between gap-2 rounded-md px-3 py-2 text-sm',
                missing.length > 0 ? 'bg-warning-soft' : 'bg-muted',
            )}
            data-test="client-fiscal"
        >
            <div className="grid min-w-0 gap-0.5">
                <span>{profile.legal_name ?? client.name}</span>
                <span className="text-muted-foreground">
                    {[profile.tax_id ?? profile.eu_vat_number, address]
                        .filter(Boolean)
                        .join(' · ') || t('invoicing.editor.no_fiscal')}
                </span>
                {missing.length > 0 ? (
                    <span className="flex items-center gap-1.5">
                        <CircleAlert
                            aria-hidden="true"
                            className="size-4 text-warning"
                        />
                        {t('invoicing.editor.client_missing', {
                            fields: missing
                                .map((field) => t(`invoicing.field.${field}`))
                                .join(', '),
                        })}
                    </span>
                ) : null}
                {profile.tax_regime === 'intra_eu' &&
                missing.includes('eu_vat_number') ? (
                    <span
                        className="text-muted-foreground"
                        data-test="intra-eu-warning"
                    >
                        {t('invoicing.editor.intra_eu_warning')}
                    </span>
                ) : null}
            </div>
            <ClientFiscalDialog
                client={client}
                trigger={
                    <Button
                        type="button"
                        variant={missing.length > 0 ? 'outline' : 'ghost'}
                        size="sm"
                        data-test="complete-fiscal"
                    >
                        {t(
                            missing.length > 0
                                ? 'invoicing.editor.complete_fiscal'
                                : 'invoicing.editor.edit_fiscal',
                        )}
                    </Button>
                }
            />
        </div>
    );
}

InvoiceEditor.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion' },
        { title: t('billing.invoices.title'), href: '/facturacion/facturas' },
        {
            title: t('invoicing.editor.breadcrumb'),
            href: '/facturacion/facturas/nueva',
        },
    ],
};
