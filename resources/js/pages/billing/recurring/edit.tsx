import { Head, Link, useForm } from '@inertiajs/react';
import { Save } from 'lucide-react';
import type { FormEvent } from 'react';
import { useId } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import { DatePicker } from '@/components/domain/date-picker';
import { SearchableSelect } from '@/components/domain/searchable-select';
import InputError from '@/components/input-error';
import { EditorLines, newLine } from '@/components/invoicing/editor-lines';
import { InvoiceTotalsPanel } from '@/components/invoicing/invoice-totals-panel';
import { computeTotals } from '@/components/invoicing/totals';
import { PageSection } from '@/components/projects-list/page-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import type {
    EditorLine,
    EditorOptions,
    RecurringForm,
    RecurringPeriodicity,
} from '@/types';

type Props = {
    recurring: RecurringForm;
    options: EditorOptions;
};

type FormData = Omit<RecurringForm, 'id' | 'lines'> & { lines: EditorLine[] };

const NONE = '__none__';

const PERIODICITIES: RecurringPeriodicity[] = [
    'monthly',
    'bimonthly',
    'quarterly',
    'semiannual',
    'annual',
    'custom',
];

const WORDS = [
    '[mes]',
    '[Mes]',
    '[mes anterior]',
    '[mes siguiente]',
    '[año]',
    '[año del mes anterior]',
    '[trimestre]',
    '[fecha]',
];

const DAYS = Array.from({ length: 31 }, (_, index) => index + 1);

/**
 * El editor de una factura recurrente (PLAN-EMISION §3.1 y §6.2; E3): el cliente y su nombre, el
 * calendario (periodicidad, día, inicio y fin por fecha o por número de veces), qué hacer cada
 * periodo (un borrador, como hoy en Holded, o emitirla y enviarla sola) y la plantilla de la factura
 * con sus palabras dinámicas ([mes], [trimestre], [año]…).
 */
export default function RecurringEditor({ recurring, options }: Props) {
    const id = useId();
    const defaultTax =
        options.taxes.find((tax) => tax.is_default)?.id ??
        options.taxes[0]?.id ??
        null;
    const { id: recurringId, lines, ...rest } = recurring;

    const form = useForm<FormData>({
        ...rest,
        lines:
            lines.length > 0
                ? lines.map((line, index) => ({
                      ...line,
                      key: `line-${line.id ?? index}`,
                  }))
                : [newLine('item', defaultTax)],
    });
    const data = form.data;
    const errors = form.errors as Record<string, string | undefined>;
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

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((current) => ({
            ...current,
            lines: current.lines.map(({ key: _key, ...line }) => line),
        }));
        if (recurringId) {
            form.put(`/facturacion/recurrentes/${recurringId}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/facturacion/recurrentes', { preserveScroll: true });
        }
    };

    const title = recurringId
        ? t('invoicing.recurring.editor.title_edit')
        : t('invoicing.recurring.editor.title_new');
    const field = (name: string) => `${id}-${name}`;

    return (
        <>
            <Head title={title} />

            <form
                onSubmit={submit}
                noValidate
                className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6"
                data-test="recurring-editor"
            >
                <BillingHeader
                    current="facturas"
                    kicker={t('invoicing.recurring.kicker')}
                    title={title}
                    description={t('invoicing.recurring.editor.description')}
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="grid min-w-0 content-start gap-6">
                        <PageSection
                            title={t('invoicing.recurring.editor.what')}
                        >
                            <div className="grid gap-4 rounded-md border bg-card p-4 sm:grid-cols-2">
                                <div className="grid content-start gap-1 sm:col-span-2">
                                    <Label htmlFor={field('name')}>
                                        {t('invoicing.recurring.editor.name')}
                                    </Label>
                                    <Input
                                        id={field('name')}
                                        value={data.name}
                                        maxLength={200}
                                        placeholder={t(
                                            'invoicing.recurring.editor.name_placeholder',
                                        )}
                                        onChange={(event) =>
                                            form.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            errors.name ? true : undefined
                                        }
                                        data-test="recurring-name-input"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid content-start gap-1 sm:col-span-2">
                                    <Label htmlFor={field('client')}>
                                        {t('invoicing.editor.client')}
                                    </Label>
                                    <SearchableSelect
                                        id={field('client')}
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
                                                    }),
                                                ),
                                            },
                                        ]}
                                        onChange={(value) =>
                                            form.setData((current) => ({
                                                ...current,
                                                client_id: Number(value),
                                                project_id: null,
                                                hour_bank_id: null,
                                            }))
                                        }
                                        search={t(
                                            'invoicing.editor.search_client',
                                        )}
                                        empty={t('invoicing.editor.no_clients')}
                                        invalid={errors.client_id !== undefined}
                                        dataTest="recurring-client"
                                    />
                                    <InputError message={errors.client_id} />
                                </div>
                                <div className="grid content-start gap-1">
                                    <Label htmlFor={field('project')}>
                                        {t('invoicing.editor.project')}
                                    </Label>
                                    <Select
                                        value={
                                            data.project_id === null
                                                ? NONE
                                                : String(data.project_id)
                                        }
                                        onValueChange={(value) =>
                                            form.setData((current) => ({
                                                ...current,
                                                project_id:
                                                    value === NONE
                                                        ? null
                                                        : Number(value),
                                                hour_bank_id: null,
                                            }))
                                        }
                                        disabled={data.client_id === null}
                                    >
                                        <SelectTrigger
                                            id={field('project')}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>
                                                {t(
                                                    'invoicing.editor.no_project',
                                                )}
                                            </SelectItem>
                                            {projects.map((one) => (
                                                <SelectItem
                                                    key={one.id}
                                                    value={String(one.id)}
                                                >
                                                    {one.code} · {one.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {project && project.banks.length > 0 ? (
                                    <div className="grid content-start gap-1">
                                        <Label htmlFor={field('bank')}>
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
                                                id={field('bank')}
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

                        <PageSection
                            title={t('invoicing.recurring.editor.when')}
                            description={t(
                                'invoicing.recurring.editor.when_description',
                            )}
                        >
                            <div className="grid gap-4 rounded-md border bg-card p-4 sm:grid-cols-2">
                                <div className="grid content-start gap-1">
                                    <Label htmlFor={field('periodicity')}>
                                        {t(
                                            'invoicing.recurring.field.periodicity',
                                        )}
                                    </Label>
                                    <Select
                                        value={data.periodicity}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'periodicity',
                                                value as RecurringPeriodicity,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id={field('periodicity')}
                                            className="w-full"
                                            data-test="recurring-periodicity"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {PERIODICITIES.map((one) => (
                                                <SelectItem
                                                    key={one}
                                                    value={one}
                                                >
                                                    {t(
                                                        `invoicing.recurring.periodicity.${one}`,
                                                    )}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {data.periodicity === 'custom' ? (
                                    <div className="grid content-start gap-1">
                                        <Label htmlFor={field('interval')}>
                                            {t(
                                                'invoicing.recurring.editor.interval',
                                            )}
                                        </Label>
                                        <Input
                                            id={field('interval')}
                                            type="number"
                                            min={1}
                                            max={24}
                                            value={data.interval_months}
                                            onChange={(event) =>
                                                form.setData(
                                                    'interval_months',
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={errors.interval_months}
                                        />
                                    </div>
                                ) : null}
                                <div className="grid content-start gap-1">
                                    <Label htmlFor={field('day')}>
                                        {t('invoicing.recurring.editor.day')}
                                    </Label>
                                    <Select
                                        value={String(data.day_of_month)}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'day_of_month',
                                                Number(value),
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id={field('day')}
                                            className="w-full"
                                            data-test="recurring-day"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {DAYS.map((day) => (
                                                <SelectItem
                                                    key={day}
                                                    value={String(day)}
                                                >
                                                    {day === 31
                                                        ? t(
                                                              'invoicing.recurring.editor.day_last',
                                                          )
                                                        : String(day)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'invoicing.recurring.editor.day_hint',
                                        )}
                                    </p>
                                </div>
                                <div className="grid content-start gap-1">
                                    <Label htmlFor={field('start')}>
                                        {t('invoicing.recurring.field.start')}
                                    </Label>
                                    <DatePicker
                                        id={field('start')}
                                        value={data.start_date}
                                        clearable={false}
                                        invalid={Boolean(errors.start_date)}
                                        onChange={(value) =>
                                            form.setData('start_date', value)
                                        }
                                    />
                                    <InputError message={errors.start_date} />
                                </div>
                                <fieldset className="grid content-start gap-2 sm:col-span-2">
                                    <legend className="mb-1 text-sm">
                                        {t('invoicing.recurring.field.end')}
                                    </legend>
                                    <RadioGroup
                                        value={data.end}
                                        onValueChange={(value) =>
                                            form.setData(
                                                'end',
                                                value as FormData['end'],
                                            )
                                        }
                                        className="grid gap-2 sm:grid-cols-3"
                                    >
                                        {(
                                            ['never', 'date', 'count'] as const
                                        ).map((one) => (
                                            <div
                                                key={one}
                                                className="flex items-center gap-2"
                                            >
                                                <RadioGroupItem
                                                    id={field(`end-${one}`)}
                                                    value={one}
                                                />
                                                <Label
                                                    htmlFor={field(
                                                        `end-${one}`,
                                                    )}
                                                    className="font-normal"
                                                >
                                                    {t(
                                                        `invoicing.recurring.editor.end_${one}`,
                                                    )}
                                                </Label>
                                            </div>
                                        ))}
                                    </RadioGroup>
                                    {data.end === 'date' ? (
                                        <div className="grid max-w-xs content-start gap-1">
                                            <Label
                                                htmlFor={field(
                                                    'end-date-value',
                                                )}
                                                className="sr-only"
                                            >
                                                {t(
                                                    'invoicing.recurring.editor.end_date',
                                                )}
                                            </Label>
                                            <DatePicker
                                                id={field('end-date-value')}
                                                value={data.end_date}
                                                clearable={false}
                                                invalid={Boolean(
                                                    errors.end_date,
                                                )}
                                                aria-label={t(
                                                    'invoicing.recurring.editor.end_date',
                                                )}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'end_date',
                                                        value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={errors.end_date}
                                            />
                                        </div>
                                    ) : null}
                                    {data.end === 'count' ? (
                                        <div className="grid max-w-xs content-start gap-1">
                                            <Label
                                                htmlFor={field(
                                                    'end-count-value',
                                                )}
                                            >
                                                {t(
                                                    'invoicing.recurring.editor.end_count_value',
                                                )}
                                            </Label>
                                            <Input
                                                id={field('end-count-value')}
                                                type="number"
                                                min={1}
                                                max={999}
                                                value={
                                                    data.max_occurrences ?? ''
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'max_occurrences',
                                                        event.target.value ===
                                                            ''
                                                            ? null
                                                            : Number(
                                                                  event.target
                                                                      .value,
                                                              ),
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={errors.max_occurrences}
                                            />
                                        </div>
                                    ) : null}
                                </fieldset>
                            </div>
                        </PageSection>

                        <PageSection
                            title={t('invoicing.recurring.editor.each')}
                        >
                            <div className="grid gap-3 rounded-md border bg-card p-4">
                                <RadioGroup
                                    value={data.mode}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'mode',
                                            value as FormData['mode'],
                                        )
                                    }
                                    className="grid gap-3"
                                >
                                    <div className="flex items-start gap-2">
                                        <RadioGroupItem
                                            id={field('mode-draft')}
                                            value="draft"
                                            className="mt-0.5"
                                        />
                                        <Label
                                            htmlFor={field('mode-draft')}
                                            className="grid gap-0.5 font-normal"
                                        >
                                            <span>
                                                {t(
                                                    'invoicing.recurring.editor.mode_draft',
                                                )}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t(
                                                    'invoicing.recurring.editor.mode_draft_hint',
                                                )}
                                            </span>
                                        </Label>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <RadioGroupItem
                                            id={field('mode-issue')}
                                            value="issue"
                                            className="mt-0.5"
                                            data-test="recurring-mode-issue"
                                        />
                                        <Label
                                            htmlFor={field('mode-issue')}
                                            className="grid gap-0.5 font-normal"
                                        >
                                            <span>
                                                {t(
                                                    'invoicing.recurring.editor.mode_issue',
                                                )}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t(
                                                    'invoicing.recurring.editor.mode_issue_hint',
                                                )}
                                            </span>
                                        </Label>
                                    </div>
                                </RadioGroup>
                                {data.mode === 'issue' ? (
                                    <div className="flex items-start gap-2 pl-6">
                                        <Checkbox
                                            id={field('send')}
                                            checked={data.auto_send}
                                            onCheckedChange={(value) =>
                                                form.setData(
                                                    'auto_send',
                                                    value === true,
                                                )
                                            }
                                            className="mt-0.5"
                                        />
                                        <Label
                                            htmlFor={field('send')}
                                            className="grid gap-0.5 font-normal"
                                        >
                                            <span>
                                                {t(
                                                    'invoicing.recurring.editor.auto_send',
                                                )}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t(
                                                    'invoicing.recurring.editor.auto_send_hint',
                                                )}
                                            </span>
                                        </Label>
                                    </div>
                                ) : null}
                            </div>
                        </PageSection>

                        <PageSection
                            title={t('invoicing.editor.lines')}
                            description={t('invoicing.recurring.editor.words', {
                                words: WORDS.join('  '),
                            })}
                        >
                            <EditorLines
                                lines={data.lines}
                                bases={bases}
                                services={options.services}
                                taxes={options.taxes}
                                errors={errors}
                                defaultTax={defaultTax}
                                onChange={(next) => form.setData('lines', next)}
                            />
                            <InputError message={errors.lines} />
                        </PageSection>

                        <PageSection title={t('invoicing.editor.texts')}>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid content-start gap-1">
                                    <Label htmlFor={field('body')}>
                                        {t('invoicing.editor.body')}
                                    </Label>
                                    <Textarea
                                        id={field('body')}
                                        value={data.body ?? ''}
                                        maxLength={5000}
                                        onChange={(event) =>
                                            form.setData(
                                                'body',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.body} />
                                </div>
                                <div className="grid content-start gap-1">
                                    <Label htmlFor={field('note')}>
                                        {t('invoicing.editor.internal_note')}
                                    </Label>
                                    <Textarea
                                        id={field('note')}
                                        value={data.internal_note ?? ''}
                                        maxLength={5000}
                                        onChange={(event) =>
                                            form.setData(
                                                'internal_note',
                                                event.target.value,
                                            )
                                        }
                                    />
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
                            <Label htmlFor={field('series')}>
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
                                        value === NONE ? null : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger
                                    id={field('series')}
                                    className="w-full"
                                    data-test="recurring-series"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        {t(
                                            'invoicing.recurring.default_series',
                                        )}
                                    </SelectItem>
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
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={field('payment')}>
                                {t('invoicing.editor.payment_method')}
                            </Label>
                            <Select
                                value={
                                    data.payment_method_id === null
                                        ? NONE
                                        : String(data.payment_method_id)
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'payment_method_id',
                                        value === NONE ? null : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger
                                    id={field('payment')}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        {t(
                                            'invoicing.recurring.default_payment',
                                        )}
                                    </SelectItem>
                                    {options.payment_methods.map((one) => (
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
                        <div className="grid gap-1">
                            <Label htmlFor={field('due')}>
                                {t('invoicing.recurring.editor.due_days')}
                            </Label>
                            <Input
                                id={field('due')}
                                type="number"
                                min={0}
                                max={365}
                                value={data.due_days ?? ''}
                                placeholder={t(
                                    'invoicing.recurring.due_default',
                                )}
                                onChange={(event) =>
                                    form.setData(
                                        'due_days',
                                        event.target.value === ''
                                            ? null
                                            : Number(event.target.value),
                                    )
                                }
                            />
                            <InputError message={errors.due_days} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={field('reference')}>
                                {t('invoicing.recurring.field.reference')}
                            </Label>
                            <Input
                                id={field('reference')}
                                value={data.customer_reference ?? ''}
                                maxLength={120}
                                onChange={(event) =>
                                    form.setData(
                                        'customer_reference',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-2 max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-20 max-lg:grid-cols-2 max-lg:border-t max-lg:bg-background max-lg:p-3">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                data-test="recurring-save"
                            >
                                <Save aria-hidden="true" />
                                {t('invoicing.recurring.editor.save')}
                            </Button>
                            <Button type="button" variant="outline" asChild>
                                <Link
                                    href={
                                        recurringId
                                            ? `/facturacion/recurrentes/${recurringId}`
                                            : '/facturacion/recurrentes'
                                    }
                                >
                                    {t('common.cancel')}
                                </Link>
                            </Button>
                        </div>
                    </aside>
                </div>
                <div className="h-24 lg:hidden" aria-hidden="true" />
            </form>
        </>
    );
}

RecurringEditor.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion' },
        { title: t('billing.invoices.title'), href: '/facturacion/facturas' },
        {
            title: t('invoicing.recurring.tab'),
            href: '/facturacion/recurrentes',
        },
    ],
};
