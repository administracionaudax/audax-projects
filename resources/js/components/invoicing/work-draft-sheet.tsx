import { router } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    Clock,
    FilePlus2,
    RefreshCw,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useId, useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrency, formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { UnbilledWork, WorkGrouping } from '@/types';

const GROUPINGS: WorkGrouping[] = [
    'task',
    'person',
    'type',
    'project',
    'entry',
];

/** Pide lo que un cliente tiene por facturar (exportada para los tests). */
export async function fetchUnbilledWork(
    clientId: number,
    query: Record<string, string | number | null | undefined>,
    signal?: AbortSignal,
): Promise<UnbilledWork> {
    const params = new URLSearchParams();
    Object.entries(query).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, String(value));
        }
    });
    const response = await fetch(
        `/facturacion/por-facturar/clientes/${clientId}/borrador?${params.toString()}`,
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal,
        },
    );

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as UnbilledWork;
}

/** Suma de importes en céntimos (sin float). */
function cents(amount: string): number {
    const [whole, decimals = ''] = amount.replace(',', '.').split('.');
    const sign = whole.startsWith('-') ? -1 : 1;
    const units = Math.abs(Number(whole || '0'));

    return sign * (units * 100 + Number((decimals + '00').slice(0, 2)));
}

function fromCents(value: number): string {
    const sign = value < 0 ? '-' : '';
    const abs = Math.abs(value);

    return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

type Selection = {
    hours: Set<string>;
    banks: Set<number>;
    overage: Set<number>;
    fees: Set<string>;
    fixed: Record<number, { on: boolean; amount: string; description: string }>;
};

/** Lo que llega marcado: todo lo pendiente salvo los precios cerrados (un hito se decide) y el exceso que va con otra bolsa. */
export function defaultSelection(work: UnbilledWork): Selection {
    const banks = new Set(work.banks.map((bank) => bank.bank.id));

    return {
        hours: new Set(
            work.hours.flatMap((project) =>
                project.lines.map(
                    (line) => `${project.project.id}|${line.key}`,
                ),
            ),
        ),
        banks,
        overage: new Set(
            work.overage
                .filter(
                    (item) =>
                        item.next_bank === null || banks.has(item.next_bank.id),
                )
                .map((item) => item.bank.id),
        ),
        fees: new Set(work.fees.map((fee) => `${fee.project.id}|${fee.month}`)),
        fixed: Object.fromEntries(
            work.fixed.map((item) => [
                item.project.id,
                {
                    on: false,
                    amount: item.pending,
                    description: item.description,
                },
            ]),
        ),
    };
}

/** El total sin IVA de lo marcado, al céntimo. */
export function selectionTotal(
    work: UnbilledWork,
    selection: Selection,
): string {
    let total = 0;
    work.hours.forEach((project) =>
        project.lines.forEach((line) => {
            if (selection.hours.has(`${project.project.id}|${line.key}`)) {
                total += cents(line.amount);
            }
        }),
    );
    work.banks.forEach((bank) => {
        if (selection.banks.has(bank.bank.id)) {
            total += cents(bank.price);
        }
    });
    work.overage.forEach((item) => {
        if (selection.overage.has(item.bank.id)) {
            total += cents(item.amount);
        }
    });
    work.fees.forEach((fee) => {
        if (selection.fees.has(`${fee.project.id}|${fee.month}`)) {
            total += cents(fee.amount);
        }
    });
    work.fixed.forEach((item) => {
        const chosen = selection.fixed[item.project.id];
        if (chosen?.on && /^\d+([.,]\d{1,2})?$/.test(chosen.amount.trim())) {
            total += cents(chosen.amount.trim());
        }
    });

    return fromCents(total);
}

/** Lo que se manda al servidor para crear el borrador. */
export function selectionPayload(
    work: UnbilledWork,
    selection: Selection,
    grouping: WorkGrouping,
) {
    return {
        agrupar: grouping,
        entries: work.hours.flatMap((project) =>
            project.lines
                .filter((line) =>
                    selection.hours.has(`${project.project.id}|${line.key}`),
                )
                .flatMap((line) => line.entry_ids),
        ),
        overage_entries: work.overage
            .filter((item) => selection.overage.has(item.bank.id))
            .flatMap((item) => item.lines.flatMap((line) => line.entry_ids)),
        banks: work.banks
            .filter((bank) => selection.banks.has(bank.bank.id))
            .map((bank) => bank.bank.id),
        fees: work.fees
            .filter((fee) =>
                selection.fees.has(`${fee.project.id}|${fee.month}`),
            )
            .map((fee) => ({ project_id: fee.project.id, month: fee.month })),
        fixed: work.fixed
            .filter((item) => selection.fixed[item.project.id]?.on)
            .map((item) => ({
                project_id: item.project.id,
                amount: selection.fixed[item.project.id].amount.replace(
                    ',',
                    '.',
                ),
                description: selection.fixed[item.project.id].description,
            })),
    };
}

function count(selection: Selection): number {
    return (
        selection.hours.size +
        selection.banks.size +
        selection.overage.size +
        selection.fees.size +
        Object.values(selection.fixed).filter((item) => item.on).length
    );
}

function Section({
    title,
    children,
    dataTest,
}: {
    title: string;
    children: ReactNode;
    dataTest: string;
}) {
    const id = useId();

    return (
        <section
            aria-labelledby={id}
            className="grid gap-2"
            data-test={dataTest}
        >
            <h3
                id={id}
                className="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                {title}
            </h3>
            {children}
        </section>
    );
}

function ChoiceRow({
    id,
    checked,
    onChange,
    title,
    detail,
    amount,
    dataTest,
    children,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    title: string;
    detail?: ReactNode;
    amount: string;
    dataTest?: string;
    children?: ReactNode;
}) {
    return (
        <li
            className={cn(
                'grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-3 gap-y-1 rounded-md border px-3 py-2',
                checked ? 'border-primary/40 bg-info-soft/50' : 'bg-card',
            )}
            data-test={dataTest}
        >
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
                className="mt-0.5"
            />
            <Label htmlFor={id} className="grid min-w-0 gap-0.5 font-normal">
                <span className="text-sm">{title}</span>
                {detail ? (
                    <span className="text-xs text-muted-foreground">
                        {detail}
                    </span>
                ) : null}
            </Label>
            <span className="tabular text-sm whitespace-nowrap">
                {formatCurrency(amount)}
            </span>
            {children ? (
                <div className="col-span-3 pl-7">{children}</div>
            ) : null}
        </li>
    );
}

/**
 * «Crear borrador» desde el trabajo (PLAN-EMISION §3.8 y §6.2; E3): un panel lateral con lo que el
 * cliente tiene por facturar (horas por proyecto, agrupadas como se elija, bolsas vendidas, excesos,
 * fees y precios cerrados), casillas para elegir y el total sin IVA. «Crear borrador» lleva al editor
 * con las líneas hechas; las horas se bloquean al emitir.
 */
export function WorkDraftSheet({
    client,
    from,
    to,
    projectId,
    bankId,
    trigger,
    defaultOpen = false,
}: {
    client: { id: number; name: string };
    from?: string;
    to?: string;
    projectId?: number;
    bankId?: number;
    trigger: ReactNode;
    defaultOpen?: boolean;
}) {
    const id = useId();
    const [open, setOpen] = useState(defaultOpen);
    const [grouping, setGrouping] = useState<WorkGrouping>('task');
    const [work, setWork] = useState<UnbilledWork | null>(null);
    const [selection, setSelection] = useState<Selection | null>(null);
    const [failed, setFailed] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [reload, setReload] = useState(0);

    useEffect(() => {
        if (!open) {
            return;
        }
        const controller = new AbortController();
        setFailed(false);
        setWork(null);
        fetchUnbilledWork(
            client.id,
            {
                desde: from,
                hasta: to,
                agrupar: grouping,
                proyecto: projectId,
                bolsa: bankId,
            },
            controller.signal,
        )
            .then((data) => {
                setWork(data);
                setSelection(defaultSelection(data));
            })
            .catch((reason: unknown) => {
                if (
                    !(
                        reason instanceof DOMException &&
                        reason.name === 'AbortError'
                    )
                ) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [open, client.id, from, to, grouping, projectId, bankId, reload]);

    const total = useMemo(
        () => (work && selection ? selectionTotal(work, selection) : '0.00'),
        [work, selection],
    );
    const empty =
        work !== null &&
        work.hours.every((project) => project.lines.length === 0) &&
        work.banks.length === 0 &&
        work.overage.length === 0 &&
        work.fees.length === 0 &&
        work.fixed.length === 0;

    const toggle = <K extends 'hours' | 'banks' | 'overage' | 'fees'>(
        key: K,
        value: Selection[K] extends Set<infer V> ? V : never,
        on: boolean,
    ) =>
        setSelection((current) => {
            if (current === null) {
                return current;
            }
            const next = new Set(current[key] as Set<typeof value>);
            if (on) {
                next.add(value);
            } else {
                next.delete(value);
            }
            const updated = { ...current, [key]: next } as Selection;
            // Al marcar una bolsa renovada, se ofrece el exceso de la anterior (D-433).
            if (key === 'banks' && on && work) {
                const overage = new Set(updated.overage);
                work.overage
                    .filter((item) => item.next_bank?.id === value)
                    .forEach((item) => overage.add(item.bank.id));
                updated.overage = overage;
            }

            return updated;
        });

    const submit = () => {
        if (!work || !selection) {
            return;
        }
        setProcessing(true);
        setError(null);
        router.post(
            `/facturacion/por-facturar/clientes/${client.id}/borrador`,
            selectionPayload(work, selection, grouping),
            {
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            t('invoicing.work.errors.generic'),
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>{trigger}</SheetTrigger>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto sm:max-w-xl"
                data-test="work-draft-sheet"
            >
                <SheetHeader className="gap-1 pr-12">
                    <SheetTitle className="text-lg font-normal">
                        {t('invoicing.work.title', { client: client.name })}
                    </SheetTitle>
                    <SheetDescription>
                        {work
                            ? t('invoicing.work.description', {
                                  from: formatDate(work.from),
                                  to: formatDate(work.to),
                              })
                            : t('invoicing.work.loading')}
                    </SheetDescription>
                </SheetHeader>

                <div className="grid flex-1 content-start gap-5 px-4 pb-4">
                    {failed ? (
                        <div
                            role="alert"
                            className="flex items-center justify-between gap-3 rounded-md bg-danger-soft px-3 py-2 text-sm"
                        >
                            <span className="flex items-center gap-1.5">
                                <CircleAlert
                                    aria-hidden="true"
                                    className="size-4 text-danger"
                                />
                                {t('invoicing.work.failed')}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setReload((value) => value + 1)}
                            >
                                <RefreshCw aria-hidden="true" />
                                {t('invoicing.work.retry')}
                            </Button>
                        </div>
                    ) : work === null || selection === null ? (
                        <p
                            className="flex items-center gap-2 text-sm text-muted-foreground"
                            role="status"
                        >
                            <Spinner />
                            {t('invoicing.work.loading')}
                        </p>
                    ) : empty ? (
                        <EmptyState
                            icon={CircleCheck}
                            title={t('invoicing.work.empty')}
                            description={t('invoicing.work.empty_description')}
                        />
                    ) : (
                        <>
                            {work.hours.length > 0 ? (
                                <Section
                                    title={t('invoicing.work.hours')}
                                    dataTest="work-hours"
                                >
                                    <div className="grid gap-1.5">
                                        <Label
                                            htmlFor={`${id}-grouping`}
                                            className="text-sm"
                                        >
                                            {t('invoicing.work.grouping')}
                                        </Label>
                                        <Select
                                            value={grouping}
                                            onValueChange={(value) =>
                                                setGrouping(
                                                    value as WorkGrouping,
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id={`${id}-grouping`}
                                                className="w-full sm:w-64"
                                                data-test="work-grouping"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {GROUPINGS.map((option) => (
                                                    <SelectItem
                                                        key={option}
                                                        value={option}
                                                    >
                                                        {t(
                                                            `invoicing.work.grouping_${option}`,
                                                        )}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    {work.hours.map((project) => (
                                        <div
                                            key={project.project.id}
                                            className="grid gap-1.5"
                                        >
                                            <p className="flex items-baseline justify-between gap-3 text-sm">
                                                <span className="min-w-0 truncate">
                                                    <span className="text-muted-foreground">
                                                        {project.project.code}
                                                    </span>{' '}
                                                    {project.project.name}
                                                </span>
                                                <span className="tabular shrink-0 text-muted-foreground">
                                                    {formatMinutes(
                                                        project.minutes,
                                                    )}
                                                </span>
                                            </p>
                                            {project.lines.length > 0 ? (
                                                <ul className="grid gap-1.5">
                                                    {project.lines.map(
                                                        (line) => {
                                                            const key = `${project.project.id}|${line.key}`;

                                                            return (
                                                                <ChoiceRow
                                                                    key={key}
                                                                    id={`${id}-h-${key}`}
                                                                    checked={selection.hours.has(
                                                                        key,
                                                                    )}
                                                                    onChange={(
                                                                        on,
                                                                    ) =>
                                                                        toggle(
                                                                            'hours',
                                                                            key,
                                                                            on,
                                                                        )
                                                                    }
                                                                    title={
                                                                        line.label
                                                                    }
                                                                    detail={
                                                                        line.rate ===
                                                                        null
                                                                            ? t(
                                                                                  'invoicing.work.hours_no_rate',
                                                                                  {
                                                                                      hours: formatMinutes(
                                                                                          line.minutes,
                                                                                      ),
                                                                                  },
                                                                              )
                                                                            : t(
                                                                                  'invoicing.work.hours_detail',
                                                                                  {
                                                                                      hours: formatMinutes(
                                                                                          line.minutes,
                                                                                      ),
                                                                                      rate: formatCurrency(
                                                                                          line.rate,
                                                                                      ),
                                                                                  },
                                                                              )
                                                                    }
                                                                    amount={
                                                                        line.amount
                                                                    }
                                                                    dataTest="work-hours-line"
                                                                />
                                                            );
                                                        },
                                                    )}
                                                </ul>
                                            ) : null}
                                            {project.pending_minutes > 0 ? (
                                                <p className="flex items-center gap-1.5 rounded-md bg-warning-soft px-3 py-1.5 text-xs">
                                                    <Clock
                                                        aria-hidden="true"
                                                        className="size-3.5 text-warning"
                                                    />
                                                    {t(
                                                        'invoicing.work.pending_approval',
                                                        {
                                                            hours: formatMinutes(
                                                                project.pending_minutes,
                                                            ),
                                                        },
                                                    )}
                                                </p>
                                            ) : null}
                                            {project.drafts.map((draft) => (
                                                <p
                                                    key={draft.id}
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    {t(
                                                        'invoicing.work.in_draft',
                                                        {
                                                            hours: formatMinutes(
                                                                draft.minutes,
                                                            ),
                                                        },
                                                    )}{' '}
                                                    <a
                                                        href={`/facturacion/documentos/${draft.id}/editar`}
                                                        className="text-primary-text hover:underline"
                                                    >
                                                        {t(
                                                            'invoicing.work.open_draft',
                                                        )}
                                                    </a>
                                                </p>
                                            ))}
                                        </div>
                                    ))}
                                </Section>
                            ) : null}

                            {work.banks.length > 0 ? (
                                <Section
                                    title={t('invoicing.work.banks')}
                                    dataTest="work-banks"
                                >
                                    <ul className="grid gap-1.5">
                                        {work.banks.map((bank) => (
                                            <ChoiceRow
                                                key={bank.bank.id}
                                                id={`${id}-b-${bank.bank.id}`}
                                                checked={selection.banks.has(
                                                    bank.bank.id,
                                                )}
                                                onChange={(on) =>
                                                    toggle(
                                                        'banks',
                                                        bank.bank.id,
                                                        on,
                                                    )
                                                }
                                                title={`${bank.project.code} · ${bank.bank.name}`}
                                                detail={bank.line.description}
                                                amount={bank.price}
                                            />
                                        ))}
                                    </ul>
                                </Section>
                            ) : null}

                            {work.overage.length > 0 ? (
                                <Section
                                    title={t('invoicing.work.overage')}
                                    dataTest="work-overage"
                                >
                                    <ul className="grid gap-1.5">
                                        {work.overage.map((item) => (
                                            <ChoiceRow
                                                key={item.bank.id}
                                                id={`${id}-o-${item.bank.id}`}
                                                checked={selection.overage.has(
                                                    item.bank.id,
                                                )}
                                                onChange={(on) =>
                                                    toggle(
                                                        'overage',
                                                        item.bank.id,
                                                        on,
                                                    )
                                                }
                                                title={`${item.project.code} · ${item.bank.name}`}
                                                detail={
                                                    item.next_bank
                                                        ? t(
                                                              'invoicing.work.overage_with_next',
                                                              {
                                                                  hours: formatMinutes(
                                                                      item.minutes,
                                                                  ),
                                                                  bank: item
                                                                      .next_bank
                                                                      .name,
                                                              },
                                                          )
                                                        : t(
                                                              'invoicing.work.overage_detail',
                                                              {
                                                                  hours: formatMinutes(
                                                                      item.minutes,
                                                                  ),
                                                              },
                                                          )
                                                }
                                                amount={item.amount}
                                            />
                                        ))}
                                    </ul>
                                </Section>
                            ) : null}

                            {work.fees.length > 0 ? (
                                <Section
                                    title={t('invoicing.work.fees')}
                                    dataTest="work-fees"
                                >
                                    <ul className="grid gap-1.5">
                                        {work.fees.map((fee) => {
                                            const key = `${fee.project.id}|${fee.month}`;

                                            return (
                                                <ChoiceRow
                                                    key={key}
                                                    id={`${id}-f-${key}`}
                                                    checked={selection.fees.has(
                                                        key,
                                                    )}
                                                    onChange={(on) =>
                                                        toggle('fees', key, on)
                                                    }
                                                    title={fee.description}
                                                    detail={fee.project.code}
                                                    amount={fee.amount}
                                                />
                                            );
                                        })}
                                    </ul>
                                </Section>
                            ) : null}

                            {work.fixed.length > 0 ? (
                                <Section
                                    title={t('invoicing.work.fixed')}
                                    dataTest="work-fixed"
                                >
                                    <ul className="grid gap-1.5">
                                        {work.fixed.map((item) => {
                                            const chosen =
                                                selection.fixed[
                                                    item.project.id
                                                ];
                                            const update = (
                                                patch: Partial<typeof chosen>,
                                            ) =>
                                                setSelection((current) =>
                                                    current === null
                                                        ? current
                                                        : {
                                                              ...current,
                                                              fixed: {
                                                                  ...current.fixed,
                                                                  [item.project
                                                                      .id]: {
                                                                      ...current
                                                                          .fixed[
                                                                          item
                                                                              .project
                                                                              .id
                                                                      ],
                                                                      ...patch,
                                                                  },
                                                              },
                                                          },
                                                );

                                            return (
                                                <ChoiceRow
                                                    key={item.project.id}
                                                    id={`${id}-x-${item.project.id}`}
                                                    checked={chosen.on}
                                                    onChange={(on) =>
                                                        update({ on })
                                                    }
                                                    title={`${item.project.code} · ${item.project.name}`}
                                                    detail={t(
                                                        'invoicing.work.fixed_detail',
                                                        {
                                                            price: formatCurrency(
                                                                item.price,
                                                            ),
                                                            invoiced:
                                                                formatCurrency(
                                                                    item.invoiced,
                                                                ),
                                                        },
                                                    )}
                                                    amount={item.pending}
                                                >
                                                    {chosen.on ? (
                                                        <div className="grid gap-2 sm:grid-cols-[8rem_minmax(0,1fr)]">
                                                            <div className="grid gap-1">
                                                                <Label
                                                                    htmlFor={`${id}-xa-${item.project.id}`}
                                                                    className="text-xs"
                                                                >
                                                                    {t(
                                                                        'invoicing.work.fixed_amount',
                                                                    )}
                                                                </Label>
                                                                <Input
                                                                    id={`${id}-xa-${item.project.id}`}
                                                                    inputMode="decimal"
                                                                    value={
                                                                        chosen.amount
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        update({
                                                                            amount: event
                                                                                .target
                                                                                .value,
                                                                        })
                                                                    }
                                                                    className="tabular text-right"
                                                                />
                                                            </div>
                                                            <div className="grid gap-1">
                                                                <Label
                                                                    htmlFor={`${id}-xd-${item.project.id}`}
                                                                    className="text-xs"
                                                                >
                                                                    {t(
                                                                        'invoicing.work.fixed_description',
                                                                    )}
                                                                </Label>
                                                                <Input
                                                                    id={`${id}-xd-${item.project.id}`}
                                                                    value={
                                                                        chosen.description
                                                                    }
                                                                    maxLength={
                                                                        500
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        update({
                                                                            description:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        })
                                                                    }
                                                                />
                                                            </div>
                                                        </div>
                                                    ) : null}
                                                </ChoiceRow>
                                            );
                                        })}
                                    </ul>
                                </Section>
                            ) : null}
                        </>
                    )}
                </div>

                {work && selection && !empty ? (
                    <div className="sticky bottom-0 grid gap-2 border-t bg-background px-4 py-3">
                        {error ? <InputError message={error} /> : null}
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p
                                className="text-sm"
                                aria-live="polite"
                                data-test="work-total"
                            >
                                <span className="text-muted-foreground">
                                    {t('invoicing.work.total')}
                                </span>{' '}
                                <span className="tabular text-lg">
                                    {formatCurrency(total)}
                                </span>
                            </p>
                            <Button
                                onClick={submit}
                                disabled={processing || count(selection) === 0}
                                data-test="work-create"
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <FilePlus2 aria-hidden="true" />
                                )}
                                {t('invoicing.work.create')}
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t('invoicing.work.hint')}
                        </p>
                        {work.test_series ? (
                            <p
                                className="rounded-md bg-info-soft px-3 py-1.5 text-xs"
                                data-test="work-test-series"
                            >
                                {t('invoicing.work.test_series')}
                            </p>
                        ) : null}
                    </div>
                ) : null}
            </SheetContent>
        </Sheet>
    );
}
