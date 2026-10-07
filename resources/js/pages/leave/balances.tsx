import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    History,
    Plus,
    RefreshCw,
    TriangleAlert,
    Users,
} from 'lucide-react';
import { useId, useState } from 'react';
import { AbsencesFrame } from '@/components/absences/absences-frame';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { EmptyState } from '@/components/empty-state';
import { HorizontalScroll } from '@/components/horizontal-scroll';
import { LeaveBalanceCards } from '@/components/leave/balance-cards';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { formatLeaveAmount } from '@/lib/leave';
import { cn } from '@/lib/utils';
import { index as mineIndex } from '@/routes/absences';
import {
    adjust,
    carryOver,
    index as balancesIndex,
    sync,
} from '@/routes/absences/balances';
import type {
    LeaveBalancesPageProps,
    LeaveTypeOption,
    LeaveUnit,
} from '@/types/leave';

type Query = {
    anio?: number;
    departamento?: number | null;
    persona?: number | null;
};

/** Un ajuste o un saldo inicial (RR. HH.), o un arrastre a otra caducidad. */
function MovementDialog({
    mode,
    person,
    types,
    year,
}: {
    mode: 'adjust' | 'carry';
    person: { id: number; name: string };
    types: LeaveTypeOption[];
    year: number;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm({
        user_id: person.id,
        leave_type_id: String(types[0]?.id ?? ''),
        kind: 'adjustment',
        amount: '',
        year,
        valid_from: '',
        expires_on: '',
        reason: '',
    });
    const type = types.find(
        (item) => String(item.id) === form.data.leave_type_id,
    );
    const unit: LeaveUnit = type?.unit ?? 'working_days';
    const errors = form.errors as Record<string, string | undefined>;
    const carry = mode === 'carry';

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant={carry ? 'outline' : 'default'}
                    size="sm"
                >
                    {carry ? (
                        <History aria-hidden="true" />
                    ) : (
                        <Plus aria-hidden="true" />
                    )}
                    {carry
                        ? t('leave.balances.carry')
                        : t('leave.balances.adjust')}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form
                    noValidate
                    className="grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(carry ? carryOver.url() : adjust.url(), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {carry
                                ? t('leave.balances.carry_title', {
                                      name: person.name,
                                  })
                                : t('leave.balances.adjust_title', {
                                      name: person.name,
                                  })}
                        </DialogTitle>
                        <DialogDescription>
                            {carry
                                ? t('leave.balances.carry_description')
                                : t('leave.balances.adjust_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${id}-type`}
                            label={t('leave.balances.type')}
                            error={errors.leave_type_id}
                        >
                            <NativeSelect
                                id={`${id}-type`}
                                value={form.data.leave_type_id}
                                onChange={(event) =>
                                    form.setData(
                                        'leave_type_id',
                                        event.target.value,
                                    )
                                }
                            >
                                {types.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        {carry ? null : (
                            <Field
                                id={`${id}-kind`}
                                label={t('leave.balances.kind')}
                                error={errors.kind}
                            >
                                <NativeSelect
                                    id={`${id}-kind`}
                                    value={form.data.kind}
                                    onChange={(event) =>
                                        form.setData('kind', event.target.value)
                                    }
                                >
                                    <option value="adjustment">
                                        {t('leave.kinds.adjustment')}
                                    </option>
                                    <option value="opening_balance">
                                        {t('leave.kinds.opening_balance')}
                                    </option>
                                </NativeSelect>
                            </Field>
                        )}
                        <Field
                            id={`${id}-amount`}
                            label={
                                unit === 'hours'
                                    ? t('leave.balances.amount_hours')
                                    : t('leave.balances.amount_days')
                            }
                            error={errors.amount}
                        >
                            <Input
                                id={`${id}-amount`}
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                                placeholder={
                                    unit === 'hours'
                                        ? '16:00'
                                        : carry
                                          ? '3'
                                          : '-1,5'
                                }
                                aria-invalid={errors.amount ? true : undefined}
                                aria-describedby={describedBy(`${id}-amount`, {
                                    error: errors.amount,
                                })}
                            />
                        </Field>
                        <Field
                            id={`${id}-year`}
                            label={t('leave.balances.year')}
                            error={errors.year}
                        >
                            <Input
                                id={`${id}-year`}
                                type="number"
                                value={form.data.year}
                                onChange={(event) =>
                                    form.setData(
                                        'year',
                                        Number(event.target.value),
                                    )
                                }
                            />
                        </Field>
                        {carry ? null : (
                            <Field
                                id={`${id}-from`}
                                label={t('leave.balances.valid_from')}
                                optional={t('absences.form.notes_optional')}
                                error={errors.valid_from}
                            >
                                <Input
                                    id={`${id}-from`}
                                    type="date"
                                    value={form.data.valid_from}
                                    onChange={(event) =>
                                        form.setData(
                                            'valid_from',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        )}
                        <Field
                            id={`${id}-expires`}
                            label={t('leave.balances.expires_on')}
                            optional={
                                carry
                                    ? undefined
                                    : t('absences.form.notes_optional')
                            }
                            error={errors.expires_on}
                        >
                            <Input
                                id={`${id}-expires`}
                                type="date"
                                value={form.data.expires_on}
                                onChange={(event) =>
                                    form.setData(
                                        'expires_on',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={
                                    errors.expires_on ? true : undefined
                                }
                                aria-describedby={describedBy(`${id}-expires`, {
                                    error: errors.expires_on,
                                })}
                            />
                        </Field>
                    </div>
                    <Field
                        id={`${id}-reason`}
                        label={t('leave.balances.reason')}
                        error={errors.reason}
                    >
                        <Textarea
                            id={`${id}-reason`}
                            rows={3}
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            placeholder={
                                carry
                                    ? t('leave.balances.carry_placeholder')
                                    : t('leave.balances.reason_placeholder')
                            }
                            aria-invalid={errors.reason ? true : undefined}
                            aria-describedby={describedBy(`${id}-reason`, {
                                error: errors.reason,
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
                            {t('leave.balances.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * «Saldos» (`/ausencias/saldos`, Fase 11, R3; W-060 a W-066 y W-096; D-362 y D-363): los saldos del
 * año de cada persona del ámbito de quien mira, el detalle de una (lo que queda de cada asignación
 * con su caducidad y el libro de movimientos) y, solo para RR. HH., los ajustes, el saldo inicial,
 * los arrastres y recalcular las asignaciones.
 */
export default function LeaveBalances(props: LeaveBalancesPageProps) {
    const filterId = useId();
    const [syncing, setSyncing] = useState(false);
    const { year, types, people, detail, can } = props;

    const url = (query: Query) => {
        const params: Record<string, number> = {};
        const nextYear = query.anio ?? year;
        const department =
            query.departamento === undefined
                ? props.filters.department
                : query.departamento;
        const person =
            query.persona === undefined ? props.filters.person : query.persona;
        if (nextYear !== props.current_year) {
            params.anio = nextYear;
        }
        if (department !== null) {
            params.departamento = department;
        }
        if (person !== null) {
            params.persona = person;
        }

        return balancesIndex.url({ query: params });
    };
    const visit = (query: Query) =>
        router.get(
            url(query),
            {},
            { preserveScroll: true, preserveState: true },
        );
    const selected =
        people.find((person) => person.id === props.filters.person) ?? null;

    return (
        <>
            <Head title={t('leave.balances.title')} />
            <AbsencesFrame
                section="balances"
                canTeam
                title={t('leave.balances.heading')}
                description={t('leave.balances.description')}
                actions={
                    can.manage ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={syncing}
                            onClick={() =>
                                router.post(
                                    sync.url(),
                                    { year },
                                    {
                                        preserveScroll: true,
                                        onError: toastVisitErrors,
                                        onStart: () => setSyncing(true),
                                        onFinish: () => setSyncing(false),
                                    },
                                )
                            }
                        >
                            {syncing ? (
                                <Spinner />
                            ) : (
                                <RefreshCw aria-hidden="true" />
                            )}
                            {t('leave.balances.sync', { year })}
                        </Button>
                    ) : null
                }
            >
                <div className="flex flex-wrap items-end gap-4">
                    <div
                        className="flex items-center gap-1"
                        role="group"
                        aria-label={t('leave.balances.year')}
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label={t('leave.balances.previous_year')}
                            onClick={() => visit({ anio: year - 1 })}
                        >
                            <ChevronLeft aria-hidden="true" />
                        </Button>
                        <span
                            className="tabular min-w-16 text-center text-lg"
                            aria-live="polite"
                        >
                            {year}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label={t('leave.balances.next_year')}
                            disabled={year >= props.current_year + 1}
                            onClick={() => visit({ anio: year + 1 })}
                        >
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    </div>
                    {props.departments.length > 1 ? (
                        <div className="grid gap-2">
                            <Label htmlFor={filterId}>
                                {t('absences.team.department')}
                            </Label>
                            <NativeSelect
                                id={filterId}
                                value={props.filters.department ?? ''}
                                onChange={(event) =>
                                    visit({
                                        departamento:
                                            event.target.value === ''
                                                ? null
                                                : Number(event.target.value),
                                        persona: null,
                                    })
                                }
                            >
                                <option value="">
                                    {t('absences.team.all_departments')}
                                </option>
                                {props.departments.map((department) => (
                                    <option
                                        key={department.id}
                                        value={department.id}
                                    >
                                        {department.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    ) : null}
                </div>

                {props.starts_on ? (
                    <p className="text-sm text-muted-foreground">
                        {t('leave.balances.starts_on', {
                            date: formatDate(props.starts_on),
                        })}
                    </p>
                ) : null}

                {people.length === 0 || types.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={t('leave.balances.empty')}
                        description={t('leave.balances.empty_description')}
                    />
                ) : (
                    <HorizontalScroll
                        className="rounded-md border"
                        data-test="leave-balances-table"
                    >
                        <table className="w-full min-w-[40rem] text-sm">
                            <caption className="sr-only">
                                {t('leave.balances.caption', { year })}
                            </caption>
                            <thead className="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-normal"
                                    >
                                        {t('leave.balances.person')}
                                    </th>
                                    {types.map((type) => (
                                        <th
                                            key={type.id}
                                            scope="col"
                                            className="px-3 py-2 font-normal"
                                        >
                                            {type.name}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {people.map((person) => (
                                    <tr
                                        key={person.id}
                                        className={cn(
                                            'border-t',
                                            person.id ===
                                                props.filters.person &&
                                                'bg-accent',
                                        )}
                                    >
                                        <th
                                            scope="row"
                                            className="px-3 py-2 text-left font-normal"
                                        >
                                            <Link
                                                href={url({
                                                    persona: person.id,
                                                })}
                                                preserveScroll
                                                className="underline-offset-2 hover:underline"
                                                aria-current={
                                                    person.id ===
                                                    props.filters.person
                                                        ? 'true'
                                                        : undefined
                                                }
                                            >
                                                {person.name}
                                            </Link>
                                            {person.department ? (
                                                <span className="block text-xs text-muted-foreground">
                                                    {person.department.name}
                                                </span>
                                            ) : null}
                                        </th>
                                        {types.map((type) => {
                                            const balance =
                                                person.balances.find(
                                                    (item) =>
                                                        item.type.id ===
                                                        type.id,
                                                );

                                            if (!balance) {
                                                return (
                                                    <td
                                                        key={type.id}
                                                        className="px-3 py-2"
                                                    />
                                                );
                                            }

                                            return (
                                                <td
                                                    key={type.id}
                                                    className="tabular px-3 py-2"
                                                >
                                                    <span className="inline-flex items-center gap-1">
                                                        {balance.available <
                                                        0 ? (
                                                            <TriangleAlert
                                                                aria-hidden="true"
                                                                className="size-3.5 text-danger"
                                                            />
                                                        ) : null}
                                                        {t(
                                                            'leave.balances.cell',
                                                            {
                                                                available:
                                                                    formatLeaveAmount(
                                                                        balance.available,
                                                                        type.unit,
                                                                    ),
                                                                total: formatLeaveAmount(
                                                                    balance.total,
                                                                    type.unit,
                                                                ),
                                                            },
                                                        )}
                                                    </span>
                                                    {balance.pending > 0 ? (
                                                        <span className="block text-xs text-muted-foreground">
                                                            {t(
                                                                'leave.balance.pending',
                                                                {
                                                                    amount: formatLeaveAmount(
                                                                        balance.pending,
                                                                        type.unit,
                                                                    ),
                                                                },
                                                            )}
                                                        </span>
                                                    ) : null}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </HorizontalScroll>
                )}

                {detail && selected ? (
                    <section
                        aria-labelledby="leave-detail-heading"
                        className="grid gap-4"
                        data-test="leave-balance-detail"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 id="leave-detail-heading" className="text-lg">
                                {t('leave.balances.detail_title', {
                                    name: detail.person.name,
                                    year,
                                })}
                            </h2>
                            {can.manage ? (
                                <div className="flex flex-wrap gap-2">
                                    <MovementDialog
                                        mode="adjust"
                                        person={detail.person}
                                        types={types}
                                        year={year}
                                    />
                                    <MovementDialog
                                        mode="carry"
                                        person={detail.person}
                                        types={types}
                                        year={year}
                                    />
                                </div>
                            ) : null}
                        </div>
                        <LeaveBalanceCards
                            title={t('leave.balances.summary')}
                            headingLevel={3}
                            balances={selected.balances}
                        />
                        <div className="grid gap-2">
                            <h3 className="text-base">
                                {t('leave.balances.lots')}
                            </h3>
                            <HorizontalScroll className="rounded-md border">
                                <table className="w-full min-w-[44rem] text-sm">
                                    <thead className="bg-muted/50 text-left text-muted-foreground">
                                        <tr>
                                            {[
                                                'type',
                                                'year',
                                                'what',
                                                'amount',
                                                'used',
                                                'reserved',
                                                'remaining',
                                                'expires',
                                            ].map((column) => (
                                                <th
                                                    key={column}
                                                    scope="col"
                                                    className="px-3 py-2 font-normal"
                                                >
                                                    {t(
                                                        `leave.balances.cols.${column}` as TranslationKey,
                                                    )}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {detail.lots.flatMap((group) => {
                                            const type = types.find(
                                                (item) =>
                                                    item.id === group.type_id,
                                            );
                                            const unit =
                                                type?.unit ?? 'working_days';

                                            return group.lots.map((lot) => (
                                                <tr
                                                    key={lot.id}
                                                    className="tabular border-t"
                                                >
                                                    <td className="px-3 py-2">
                                                        {type?.name}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {lot.year}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {t(
                                                            `leave.kinds.${lot.kind}`,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {formatLeaveAmount(
                                                            lot.amount,
                                                            unit,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {formatLeaveAmount(
                                                            lot.used,
                                                            unit,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {formatLeaveAmount(
                                                            lot.reserved,
                                                            unit,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {formatLeaveAmount(
                                                            lot.remaining,
                                                            unit,
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {lot.expires_on
                                                            ? formatDate(
                                                                  lot.expires_on,
                                                              )
                                                            : t(
                                                                  'leave.balances.no_expiry',
                                                              )}
                                                    </td>
                                                </tr>
                                            ));
                                        })}
                                    </tbody>
                                </table>
                            </HorizontalScroll>
                        </div>
                        <div className="grid gap-2">
                            <h3 className="text-base">
                                {t('leave.balances.movements')}
                            </h3>
                            {detail.movements.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('leave.balances.no_movements')}
                                </p>
                            ) : (
                                <ul
                                    className="grid gap-2"
                                    data-test="leave-movements"
                                >
                                    {detail.movements.map((movement) => (
                                        <li
                                            key={movement.id}
                                            className="grid gap-0.5 rounded-md border p-3 text-sm"
                                        >
                                            <p className="flex flex-wrap gap-x-2">
                                                <span>
                                                    {t(
                                                        `leave.kinds.${movement.kind}`,
                                                    )}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {movement.type.name} ·{' '}
                                                    {movement.year}
                                                </span>
                                                <span
                                                    className={cn(
                                                        'tabular',
                                                        movement.amount < 0 &&
                                                            'text-foreground',
                                                    )}
                                                >
                                                    {movement.amount > 0
                                                        ? '+'
                                                        : ''}
                                                    {formatLeaveAmount(
                                                        movement.amount,
                                                        movement.type.unit,
                                                    )}
                                                </span>
                                            </p>
                                            <p className="text-muted-foreground">
                                                {movement.reason}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'leave.balances.movement_meta',
                                                    {
                                                        by:
                                                            movement.author ??
                                                            t(
                                                                'leave.balances.system',
                                                            ),
                                                        at: movement.created_at
                                                            ? formatDateTime(
                                                                  movement.created_at,
                                                              )
                                                            : '',
                                                    },
                                                )}
                                                {movement.expires_on
                                                    ? ` · ${t('leave.balances.expires', { date: formatDate(movement.expires_on) })}`
                                                    : ''}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </section>
                ) : null}
            </AbsencesFrame>
        </>
    );
}

LeaveBalances.layout = {
    breadcrumbs: [
        { title: t('absences.mine.title'), href: mineIndex() },
        { title: t('leave.balances.title'), href: balancesIndex() },
    ],
};
