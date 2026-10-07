import { Head, useForm } from '@inertiajs/react';
import { CircleOff, Pencil, Plus, Scale, ShieldCheck } from 'lucide-react';
import { useId, useState } from 'react';
import {
    AbsenceTypeLabel,
    absenceTypeLabel,
} from '@/components/absences/absence-meta';
import { AbsencesFrame } from '@/components/absences/absences-frame';
import type { AbsenceType } from '@/components/absences/types';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { formatLeaveAmount, leaveAmountInput } from '@/lib/leave';
import { index as mineIndex } from '@/routes/absences';
import { index as typesIndex, store, update } from '@/routes/absences/types';
import type {
    LeaveTypeAdminRow,
    LeaveTypesPageProps,
    LeaveUnit,
} from '@/types/leave';

type TypeForm = {
    name: string;
    category: AbsenceType;
    unit: LeaveUnit;
    description: string;
    legal_basis: string;
    default_amount: string;
    travel_extra: string;
    annual_allowance: string;
    allowance_in_days: boolean;
    paid: boolean;
    requires_document: boolean;
    notice_days: string;
    health_data: boolean;
    carry_over_until: string;
    allow_without_balance: boolean;
    second_approval: boolean;
    respects_blocked_days: boolean;
    advisor_pending: boolean;
    advisor_note: string;
    active: boolean;
};

const FLAGS = [
    'paid',
    'requires_document',
    'health_data',
    'allow_without_balance',
    'respects_blocked_days',
    'second_approval',
    'advisor_pending',
    'active',
] as const;

function initial(type?: LeaveTypeAdminRow): TypeForm {
    const unit = type?.unit ?? 'working_days';

    return {
        name: type?.name ?? '',
        category: type?.category ?? 'leave',
        unit,
        description: type?.description ?? '',
        legal_basis: type?.legal_basis ?? '',
        default_amount: leaveAmountInput(type?.default_amount ?? null, unit),
        travel_extra: leaveAmountInput(type?.travel_extra ?? null, unit),
        annual_allowance: leaveAmountInput(
            type?.annual_allowance ?? null,
            type?.allowance_in_days ? 'working_days' : unit,
        ),
        allowance_in_days: type?.allowance_in_days ?? false,
        paid: type?.paid ?? true,
        requires_document: type?.requires_document ?? false,
        notice_days: type?.notice_days ? String(type.notice_days) : '',
        health_data: type?.health_data ?? false,
        carry_over_until: type?.carry_over_until ?? '',
        allow_without_balance: type?.allow_without_balance ?? true,
        second_approval: type?.second_approval ?? false,
        respects_blocked_days: type?.respects_blocked_days ?? false,
        advisor_pending: type?.advisor_pending ?? false,
        advisor_note: type?.advisor_note ?? '',
        active: type?.active ?? true,
    };
}

/** Crear o editar un tipo del catálogo (RR. HH.). */
function TypeDialog({
    type,
    categories,
    units,
}: {
    type?: LeaveTypeAdminRow;
    categories: AbsenceType[];
    units: LeaveUnit[];
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<TypeForm>(initial(type));
    const errors = form.errors as Record<string, string | undefined>;
    const hours = form.data.unit === 'hours';
    const amountLabel = hours
        ? 'leave.types.form.amount_hours'
        : 'leave.types.form.amount_days';

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.setData(initial(type));
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                {type ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={t('leave.types.edit_label', {
                            name: type.name,
                        })}
                    >
                        <Pencil aria-hidden="true" />
                        {t('leave.types.edit')}
                    </Button>
                ) : (
                    <Button type="button">
                        <Plus aria-hidden="true" />
                        {t('leave.types.create')}
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    noValidate
                    className="grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        };
                        if (type) {
                            form.put(update.url(type.id), options);
                        } else {
                            form.post(store.url(), options);
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {type
                                ? t('leave.types.edit_title', {
                                      name: type.name,
                                  })
                                : t('leave.types.create_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('leave.types.form_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${id}-name`}
                            label={t('leave.types.form.name')}
                            error={errors.name}
                            className="sm:col-span-2"
                        >
                            <Input
                                id={`${id}-name`}
                                value={form.data.name}
                                maxLength={120}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby={describedBy(`${id}-name`, {
                                    error: errors.name,
                                })}
                            />
                        </Field>
                        <Field
                            id={`${id}-category`}
                            label={t('leave.types.form.category')}
                            error={errors.category}
                        >
                            <NativeSelect
                                id={`${id}-category`}
                                value={form.data.category}
                                disabled={type?.legacy}
                                onChange={(event) =>
                                    form.setData(
                                        'category',
                                        event.target.value as AbsenceType,
                                    )
                                }
                            >
                                {categories.map((category) => (
                                    <option key={category} value={category}>
                                        {absenceTypeLabel(category)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id={`${id}-unit`}
                            label={t('leave.types.form.unit')}
                            error={errors.unit}
                        >
                            <NativeSelect
                                id={`${id}-unit`}
                                value={form.data.unit}
                                onChange={(event) =>
                                    form.setData(
                                        'unit',
                                        event.target.value as LeaveUnit,
                                    )
                                }
                            >
                                {units.map((unit) => (
                                    <option key={unit} value={unit}>
                                        {t(
                                            `leave.units_label.${unit}` as TranslationKey,
                                        )}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id={`${id}-default`}
                            label={t(amountLabel)}
                            optional={t('absences.form.notes_optional')}
                            error={errors.default_amount}
                        >
                            <Input
                                id={`${id}-default`}
                                inputMode="decimal"
                                value={form.data.default_amount}
                                onChange={(event) =>
                                    form.setData(
                                        'default_amount',
                                        event.target.value,
                                    )
                                }
                                placeholder={hours ? '8:00' : '15'}
                            />
                        </Field>
                        <Field
                            id={`${id}-travel`}
                            label={t('leave.types.form.travel_extra')}
                            optional={t('absences.form.notes_optional')}
                            error={errors.travel_extra}
                        >
                            <Input
                                id={`${id}-travel`}
                                inputMode="decimal"
                                value={form.data.travel_extra}
                                onChange={(event) =>
                                    form.setData(
                                        'travel_extra',
                                        event.target.value,
                                    )
                                }
                                placeholder="2"
                            />
                        </Field>
                        <Field
                            id={`${id}-annual`}
                            label={
                                hours && !form.data.allowance_in_days
                                    ? t('leave.types.form.annual_hours')
                                    : t('leave.types.form.annual_days')
                            }
                            optional={t('absences.form.notes_optional')}
                            help={t('leave.types.form.annual_help')}
                            error={errors.annual_allowance}
                        >
                            <Input
                                id={`${id}-annual`}
                                inputMode="decimal"
                                value={form.data.annual_allowance}
                                onChange={(event) =>
                                    form.setData(
                                        'annual_allowance',
                                        event.target.value,
                                    )
                                }
                                aria-describedby={describedBy(`${id}-annual`, {
                                    help: true,
                                    error: errors.annual_allowance,
                                })}
                            />
                        </Field>
                        <Field
                            id={`${id}-carry`}
                            label={t('leave.types.form.carry_over_until')}
                            optional={t('absences.form.notes_optional')}
                            help={t('leave.types.form.carry_help')}
                            error={errors.carry_over_until}
                        >
                            <Input
                                id={`${id}-carry`}
                                value={form.data.carry_over_until}
                                placeholder="03-31"
                                maxLength={5}
                                onChange={(event) =>
                                    form.setData(
                                        'carry_over_until',
                                        event.target.value,
                                    )
                                }
                                aria-describedby={describedBy(`${id}-carry`, {
                                    help: true,
                                    error: errors.carry_over_until,
                                })}
                            />
                        </Field>
                        <Field
                            id={`${id}-notice`}
                            label={t('leave.types.form.notice_days')}
                            optional={t('absences.form.notes_optional')}
                            error={errors.notice_days}
                        >
                            <Input
                                id={`${id}-notice`}
                                type="number"
                                min={0}
                                value={form.data.notice_days}
                                onChange={(event) =>
                                    form.setData(
                                        'notice_days',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        {hours ? (
                            <div className="flex items-center gap-2 self-end pb-2">
                                <Checkbox
                                    id={`${id}-in-days`}
                                    checked={form.data.allowance_in_days}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'allowance_in_days',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor={`${id}-in-days`}
                                    className="font-normal"
                                >
                                    {t('leave.types.form.allowance_in_days')}
                                </Label>
                            </div>
                        ) : null}
                    </div>
                    <fieldset className="grid gap-2 sm:grid-cols-2">
                        <legend className="mb-1 text-sm font-medium">
                            {t('leave.types.form.rules')}
                        </legend>
                        {FLAGS.map((flag) => (
                            <div key={flag} className="flex items-start gap-2">
                                <Checkbox
                                    id={`${id}-${flag}`}
                                    checked={form.data[flag]}
                                    disabled={
                                        flag === 'second_approval' &&
                                        form.data.category !== 'vacation'
                                    }
                                    onCheckedChange={(checked) =>
                                        form.setData(flag, checked === true)
                                    }
                                />
                                <Label
                                    htmlFor={`${id}-${flag}`}
                                    className="leading-snug font-normal"
                                >
                                    {t(
                                        `leave.types.flags.${flag}` as TranslationKey,
                                    )}
                                </Label>
                            </div>
                        ))}
                        {errors.second_approval ? (
                            <p
                                className="text-sm text-danger sm:col-span-2"
                                role="alert"
                            >
                                {errors.second_approval}
                            </p>
                        ) : null}
                    </fieldset>
                    <Field
                        id={`${id}-description`}
                        label={t('leave.types.form.description')}
                        optional={t('absences.form.notes_optional')}
                    >
                        <Textarea
                            id={`${id}-description`}
                            rows={2}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id={`${id}-legal`}
                        label={t('leave.types.form.legal_basis')}
                        optional={t('absences.form.notes_optional')}
                    >
                        <Textarea
                            id={`${id}-legal`}
                            rows={2}
                            value={form.data.legal_basis}
                            onChange={(event) =>
                                form.setData('legal_basis', event.target.value)
                            }
                        />
                    </Field>
                    {form.data.advisor_pending ? (
                        <Field
                            id={`${id}-advisor`}
                            label={t('leave.types.form.advisor_note')}
                            optional={t('absences.form.notes_optional')}
                        >
                            <Textarea
                                id={`${id}-advisor`}
                                rows={2}
                                value={form.data.advisor_note}
                                onChange={(event) =>
                                    form.setData(
                                        'advisor_note',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    ) : null}
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
                            {t('leave.types.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * «Tipos de ausencia» (`/ausencias/tipos`, Fase 11, R3; W-057 a W-059; D-360 y D-361): el catálogo
 * con su unidad, cantidad, si se paga, si pide justificante o preaviso, si es de salud, el saldo
 * anual, su base legal y lo que está pendiente de asesor. Solo RR. HH.
 */
export default function LeaveTypes({
    types,
    categories,
    units,
}: LeaveTypesPageProps) {
    return (
        <>
            <Head title={t('leave.types.title')} />
            <AbsencesFrame
                section="types"
                canTeam
                title={t('leave.types.heading')}
                description={t('leave.types.description')}
                actions={<TypeDialog categories={categories} units={units} />}
            >
                <ul className="grid gap-2" data-test="leave-types">
                    {types.map((type) => {
                        const facts = [
                            t(
                                `leave.units_label.${type.unit}` as TranslationKey,
                            ),
                            type.default_amount !== null
                                ? t('leave.types.amount', {
                                      amount: formatLeaveAmount(
                                          type.default_amount,
                                          type.unit,
                                      ),
                                  })
                                : null,
                            type.annual_allowance !== null
                                ? t('leave.types.annual', {
                                      amount: formatLeaveAmount(
                                          type.annual_allowance,
                                          type.allowance_in_days
                                              ? 'working_days'
                                              : type.unit,
                                      ),
                                  })
                                : null,
                            t(
                                type.paid
                                    ? 'leave.info.paid'
                                    : 'leave.info.unpaid',
                            ),
                            type.requires_document
                                ? t('leave.info.document')
                                : null,
                            type.notice_days
                                ? t('leave.info.notice', {
                                      days: type.notice_days,
                                  })
                                : null,
                            type.second_approval
                                ? t('leave.types.second_approval')
                                : null,
                        ].filter((fact): fact is string => fact !== null);

                        return (
                            <li
                                key={type.id}
                                className="flex flex-col gap-3 rounded-md border p-3 text-sm sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div className="grid min-w-0 gap-1">
                                    <p className="flex flex-wrap items-center gap-2">
                                        <AbsenceTypeLabel
                                            type={type.category}
                                            label={type.name}
                                            className="inline-flex items-center gap-1.5 font-medium"
                                        />
                                        {!type.active ? (
                                            <StatusBadge
                                                tone="neutral"
                                                icon={CircleOff}
                                            >
                                                {t('leave.types.inactive')}
                                            </StatusBadge>
                                        ) : null}
                                        {type.advisor_pending ? (
                                            <StatusBadge
                                                tone="warning"
                                                icon={Scale}
                                            >
                                                {t(
                                                    'leave.types.advisor_pending',
                                                )}
                                            </StatusBadge>
                                        ) : null}
                                        {type.health_data ? (
                                            <StatusBadge
                                                tone="info"
                                                icon={ShieldCheck}
                                            >
                                                {t('leave.types.health')}
                                            </StatusBadge>
                                        ) : null}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {facts.join(' · ')}
                                    </p>
                                    {type.legal_basis ? (
                                        <p className="text-xs text-muted-foreground">
                                            {type.legal_basis}
                                        </p>
                                    ) : null}
                                    {type.advisor_pending &&
                                    type.advisor_note ? (
                                        <p className="text-xs">
                                            {t('leave.types.advisor_note', {
                                                note: type.advisor_note,
                                            })}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="sm:shrink-0">
                                    <TypeDialog
                                        type={type}
                                        categories={categories}
                                        units={units}
                                    />
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </AbsencesFrame>
        </>
    );
}

LeaveTypes.layout = {
    breadcrumbs: [
        { title: t('absences.mine.title'), href: mineIndex() },
        { title: t('leave.types.title'), href: typesIndex() },
    ],
};
