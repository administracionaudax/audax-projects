import { ArrowDown, ArrowUp, Plus, Text, Trash2 } from 'lucide-react';
import { useId } from 'react';
import { SearchableSelect } from '@/components/domain/searchable-select';
import InputError from '@/components/input-error';
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
import { formatCurrency } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    EditorLine,
    InvoicingServiceOption,
    InvoicingTaxOption,
    ServiceUnit,
} from '@/types';
import { taxLabel } from './invoicing-format';

const NONE = '__none__';

const UNITS: ServiceUnit[] = ['hour', 'unit', 'month'];

let counter = 0;

/** Una línea nueva del editor, con el impuesto por defecto. */
export function newLine(
    kind: 'item' | 'text',
    taxRateId: number | null,
): EditorLine {
    counter += 1;

    return {
        key: `new-${Date.now()}-${counter}`,
        id: null,
        kind,
        service_id: null,
        description: '',
        quantity: kind === 'item' ? '1' : '0',
        unit: 'unit',
        unit_price: kind === 'item' ? '0' : '0',
        discount_pct: '0',
        tax_rate_id: kind === 'item' ? taxRateId : null,
    };
}

/**
 * Las líneas del editor de factura (PLAN-EMISION §6.2, paso 3; H-070 a H-074): de concepto (servicio
 * del catálogo, que rellena precio, unidad e impuesto; descripción libre; cantidad; precio; descuento;
 * impuesto; base calculada al céntimo) o de texto (sin importe). Se ordenan con flechas y se quitan.
 * En el móvil, cada línea es una tarjeta en una columna.
 */
export function EditorLines({
    lines,
    bases,
    services,
    taxes,
    errors,
    defaultTax,
    onChange,
}: {
    lines: EditorLine[];
    /** La base de cada línea de concepto (por su clave). */
    bases: Record<string, string>;
    services: InvoicingServiceOption[];
    taxes: InvoicingTaxOption[];
    errors: Record<string, string | undefined>;
    defaultTax: number | null;
    onChange: (lines: EditorLine[]) => void;
}) {
    const id = useId();
    const update = (index: number, patch: Partial<EditorLine>) =>
        onChange(
            lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );
    const move = (index: number, delta: number) => {
        const next = [...lines];
        const [line] = next.splice(index, 1);
        next.splice(index + delta, 0, line);
        onChange(next);
    };

    const chooseService = (index: number, value: string) => {
        const service = services.find((one) => String(one.id) === value);

        update(
            index,
            service
                ? {
                      service_id: service.id,
                      unit: service.unit,
                      unit_price: service.unit_price,
                      tax_rate_id:
                          service.tax_rate_id ?? lines[index].tax_rate_id,
                  }
                : { service_id: null },
        );
    };

    const serviceGroups = [
        {
            label: null,
            options: [
                { value: NONE, label: t('invoicing.editor.no_service') },
                ...services.map((service) => ({
                    value: String(service.id),
                    label: service.name,
                    hint: service.code,
                    keywords: service.code,
                })),
            ],
        },
    ];

    return (
        <div className="grid gap-3">
            <ol className="grid gap-3" data-test="editor-lines">
                {lines.map((line, index) => {
                    const prefix = `${id}-${line.key}`;
                    const error = (field: string) =>
                        errors[`lines.${index}.${field}`];

                    return (
                        <li
                            key={line.key}
                            className={cn(
                                'grid gap-3 rounded-md border bg-card p-3',
                                line.kind === 'text' && 'bg-muted/40',
                            )}
                            data-test="editor-line"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase">
                                    {t(
                                        line.kind === 'item'
                                            ? 'invoicing.editor.line_item'
                                            : 'invoicing.editor.line_text',
                                        { n: index + 1 },
                                    )}
                                </p>
                                <div className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                        aria-label={t(
                                            'invoicing.editor.move_up',
                                            { n: index + 1 },
                                        )}
                                    >
                                        <ArrowUp aria-hidden="true" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        disabled={index === lines.length - 1}
                                        onClick={() => move(index, 1)}
                                        aria-label={t(
                                            'invoicing.editor.move_down',
                                            { n: index + 1 },
                                        )}
                                    >
                                        <ArrowDown aria-hidden="true" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        disabled={lines.length === 1}
                                        onClick={() =>
                                            onChange(
                                                lines.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                        aria-label={t(
                                            'invoicing.editor.remove_line',
                                            { n: index + 1 },
                                        )}
                                        data-test="remove-line"
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </Button>
                                </div>
                            </div>

                            {line.kind === 'text' ? (
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor={`${prefix}-text`}
                                        className="sr-only"
                                    >
                                        {t('invoicing.editor.text')}
                                    </Label>
                                    <Textarea
                                        id={`${prefix}-text`}
                                        value={line.description}
                                        placeholder={t(
                                            'invoicing.editor.text_placeholder',
                                        )}
                                        onChange={(event) =>
                                            update(index, {
                                                description: event.target.value,
                                            })
                                        }
                                        aria-invalid={
                                            error('description')
                                                ? true
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        message={error('description')}
                                    />
                                </div>
                            ) : (
                                <>
                                    <div className="grid gap-3 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]">
                                        <div className="grid content-start gap-1">
                                            <Label
                                                htmlFor={`${prefix}-service`}
                                            >
                                                {t('invoicing.editor.service')}
                                            </Label>
                                            <SearchableSelect
                                                id={`${prefix}-service`}
                                                value={
                                                    line.service_id === null
                                                        ? NONE
                                                        : String(
                                                              line.service_id,
                                                          )
                                                }
                                                placeholder={t(
                                                    'invoicing.editor.choose_service',
                                                )}
                                                groups={serviceGroups}
                                                onChange={(value) =>
                                                    chooseService(index, value)
                                                }
                                                search={t(
                                                    'invoicing.editor.search_service',
                                                )}
                                                empty={t(
                                                    'invoicing.editor.no_services',
                                                )}
                                                dataTest="line-service"
                                            />
                                        </div>
                                        <div className="grid content-start gap-1">
                                            <Label
                                                htmlFor={`${prefix}-description`}
                                            >
                                                {t(
                                                    'invoicing.editor.description',
                                                )}
                                            </Label>
                                            <Textarea
                                                id={`${prefix}-description`}
                                                value={line.description}
                                                rows={1}
                                                className="min-h-9"
                                                placeholder={t(
                                                    'invoicing.editor.description_placeholder',
                                                )}
                                                onChange={(event) =>
                                                    update(index, {
                                                        description:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    error('description')
                                                        ? true
                                                        : undefined
                                                }
                                                data-test="line-description"
                                            />
                                            <InputError
                                                message={error('description')}
                                            />
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-[6rem_8rem_8rem_6rem_minmax(0,1fr)_auto]">
                                        <Field
                                            id={`${prefix}-quantity`}
                                            label={t(
                                                'invoicing.editor.quantity',
                                            )}
                                            error={error('quantity')}
                                        >
                                            <Input
                                                id={`${prefix}-quantity`}
                                                inputMode="decimal"
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    update(index, {
                                                        quantity:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    error('quantity')
                                                        ? true
                                                        : undefined
                                                }
                                                className="tabular text-right"
                                                data-test="line-quantity"
                                            />
                                        </Field>
                                        <Field
                                            id={`${prefix}-unit`}
                                            label={t('invoicing.editor.unit')}
                                        >
                                            <Select
                                                value={line.unit}
                                                onValueChange={(value) =>
                                                    update(index, {
                                                        unit: value as ServiceUnit,
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    id={`${prefix}-unit`}
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {UNITS.map((unit) => (
                                                        <SelectItem
                                                            key={unit}
                                                            value={unit}
                                                        >
                                                            {t(
                                                                `invoicing.unit.${unit}_other`,
                                                            )}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                        <Field
                                            id={`${prefix}-price`}
                                            label={t('invoicing.editor.price')}
                                            error={error('unit_price')}
                                        >
                                            <Input
                                                id={`${prefix}-price`}
                                                inputMode="decimal"
                                                value={line.unit_price}
                                                onChange={(event) =>
                                                    update(index, {
                                                        unit_price:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    error('unit_price')
                                                        ? true
                                                        : undefined
                                                }
                                                className="tabular text-right"
                                                data-test="line-price"
                                            />
                                        </Field>
                                        <Field
                                            id={`${prefix}-discount`}
                                            label={t(
                                                'invoicing.editor.discount',
                                            )}
                                            error={error('discount_pct')}
                                        >
                                            <Input
                                                id={`${prefix}-discount`}
                                                inputMode="decimal"
                                                value={line.discount_pct}
                                                onChange={(event) =>
                                                    update(index, {
                                                        discount_pct:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    error('discount_pct')
                                                        ? true
                                                        : undefined
                                                }
                                                className="tabular text-right"
                                            />
                                        </Field>
                                        <Field
                                            id={`${prefix}-tax`}
                                            label={t('invoicing.editor.tax')}
                                            error={error('tax_rate_id')}
                                        >
                                            <Select
                                                value={
                                                    line.tax_rate_id === null
                                                        ? NONE
                                                        : String(
                                                              line.tax_rate_id,
                                                          )
                                                }
                                                onValueChange={(value) =>
                                                    update(index, {
                                                        tax_rate_id:
                                                            value === NONE
                                                                ? null
                                                                : Number(value),
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    id={`${prefix}-tax`}
                                                    className="w-full"
                                                    data-test="line-tax"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={NONE}>
                                                        {t(
                                                            'invoicing.editor.no_tax',
                                                        )}
                                                    </SelectItem>
                                                    {taxes.map((tax) => (
                                                        <SelectItem
                                                            key={tax.id}
                                                            value={String(
                                                                tax.id,
                                                            )}
                                                        >
                                                            {tax.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                        <div className="col-span-2 grid content-end gap-1 text-right sm:col-span-1">
                                            <span className="text-sm text-muted-foreground">
                                                {t('invoicing.editor.base')}
                                            </span>
                                            <output
                                                className="tabular flex h-9 items-center justify-end text-base"
                                                aria-label={t(
                                                    'invoicing.editor.base_of',
                                                    { n: index + 1 },
                                                )}
                                                data-test="line-base"
                                            >
                                                {formatCurrency(
                                                    bases[line.key] ?? '0',
                                                )}
                                            </output>
                                        </div>
                                    </div>
                                    {line.tax_rate_id !== null ? (
                                        <TaxHint
                                            tax={
                                                taxes.find(
                                                    (tax) =>
                                                        tax.id ===
                                                        line.tax_rate_id,
                                                ) ?? null
                                            }
                                        />
                                    ) : null}
                                </>
                            )}
                        </li>
                    );
                })}
            </ol>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        onChange([...lines, newLine('item', defaultTax)])
                    }
                    data-test="add-line"
                >
                    <Plus aria-hidden="true" />
                    {t('invoicing.editor.add_line')}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => onChange([...lines, newLine('text', null)])}
                >
                    <Text aria-hidden="true" />
                    {t('invoicing.editor.add_text')}
                </Button>
            </div>
        </div>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-1">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

/** Lo que significa un impuesto que no es el IVA normal (exenta, no sujeta, inversión). */
function TaxHint({ tax }: { tax: InvoicingTaxOption | null }) {
    if (tax === null || tax.operation_type === 'S1') {
        return null;
    }

    return (
        <p className="text-xs text-muted-foreground">
            {t('invoicing.editor.tax_hint', {
                tax: taxLabel(tax.operation_type, tax.rate),
            })}
        </p>
    );
}
