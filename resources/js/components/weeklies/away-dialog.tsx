import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CalendarOff, Palmtree, UserCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { Field } from '@/components/admin/field';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { todayInMadrid } from '@/lib/week';
import { index as absencesIndex } from '@/routes/absences';
import { index as teamAbsences } from '@/routes/absences/team';
import {
    destroy as clearAway,
    update as updateAway,
} from '@/routes/weeklies/away';
import { useResetOnOpen } from '@/hooks/use-reset-on-open';
import type { WeeklyAwayReason, WeeklyAwayStatus } from '@/types/weeklies';

type AwayForm = {
    reason: WeeklyAwayReason;
    until: string | null;
    request_absence: boolean;
};

const REASONS: WeeklyAwayReason[] = ['vacation', 'absent'];

/** «De vacaciones hasta el 12/10», «Ausente o de baja» (sin vuelta). */
export function awayLabel(away: WeeklyAwayStatus): string {
    const reason = t(`weeklies.away.reason.${away.reason}`);

    return away.until
        ? t('weeklies.away.until_label', {
              reason,
              date: formatDate(away.until),
          })
        : reason;
}

/**
 * «Estoy fuera» (10.9b, D-228; el estado VACATION/ABSENT de WeeklySync): con efecto inmediato,
 * exime de las weeklies cuyo plazo cae antes de la vuelta y quita los recordatorios mientras dura.
 * Lo pone la propia persona (desde su menú o «Mi weekly») o quien gestiona la Weekly a otra persona
 * (desde el resumen). La propia persona puede solicitar a la vez la ausencia en Ausencias.
 */
export function AwayDialog({
    person,
    current,
    self,
    trigger,
    open: controlledOpen,
    onOpenChange,
}: {
    person: { id: number; name: string };
    current: WeeklyAwayStatus | null | undefined;
    self: boolean;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const id = useId();
    const can = usePage().props.auth?.can;
    const [innerOpen, setInnerOpen] = useState(false);
    const open = controlledOpen ?? innerOpen;
    const [clearing, setClearing] = useState(false);
    const today = todayInMadrid();
    const initial = (): AwayForm => ({
        reason: current?.reason ?? 'vacation',
        until: current?.until ?? null,
        request_absence: false,
    });
    const form = useForm<AwayForm>(initial());

    // También cuando lo abre el menú o el aviso (controlado): parte del estado de ahora.
    useResetOnOpen(open, () => {
        form.setData(initial());
        form.clearErrors();
    });

    const setOpen = (next: boolean) => {
        setInnerOpen(next);
        onOpenChange?.(next);
    };

    const canRequestAbsence = self && can?.viewAbsences === true;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {trigger ? <DialogTrigger asChild>{trigger}</DialogTrigger> : null}
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-5"
                    noValidate
                    data-test="weekly-away-dialog"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            request_absence:
                                canRequestAbsence && data.request_absence,
                        }));
                        form.put(updateAway.url(person.id), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {self
                                ? t('weeklies.away.title_self')
                                : t('weeklies.away.title_other', {
                                      name: person.name,
                                  })}
                        </DialogTitle>
                        <DialogDescription>
                            {self
                                ? t('weeklies.away.description_self')
                                : t('weeklies.away.description_other')}
                        </DialogDescription>
                    </DialogHeader>

                    {current ? (
                        <p
                            className="bg-warning-soft p-3 text-sm"
                            data-test="weekly-away-current"
                        >
                            {t('weeklies.away.current', {
                                status: awayLabel(current),
                            })}
                        </p>
                    ) : null}

                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">
                            {t('weeklies.away.reason_label')}
                        </legend>
                        <RadioGroup
                            value={form.data.reason}
                            onValueChange={(value) =>
                                form.setData(
                                    'reason',
                                    value as WeeklyAwayReason,
                                )
                            }
                            className="grid gap-2"
                        >
                            {REASONS.map((reason) => (
                                <label
                                    key={reason}
                                    className="flex items-center gap-2 border p-3 text-sm"
                                >
                                    <RadioGroupItem value={reason} />
                                    {reason === 'vacation' ? (
                                        <Palmtree
                                            aria-hidden="true"
                                            className="size-4 text-warning"
                                        />
                                    ) : (
                                        <CalendarOff
                                            aria-hidden="true"
                                            className="size-4 text-danger"
                                        />
                                    )}
                                    {t(`weeklies.away.reason.${reason}`)}
                                </label>
                            ))}
                        </RadioGroup>
                        <InputError message={form.errors.reason} />
                    </fieldset>

                    <Field
                        id={`${id}-until`}
                        label={t('weeklies.away.until')}
                        optional={t('weeklies.common.optional')}
                        error={form.errors.until}
                        help={t('weeklies.away.until_hint')}
                    >
                        <DatePicker
                            id={`${id}-until`}
                            value={form.data.until}
                            onChange={(value) =>
                                // Sin fecha no se puede pedir la ausencia: se desmarca.
                                form.setData((data) => ({
                                    ...data,
                                    until: value,
                                    request_absence: value
                                        ? data.request_absence
                                        : false,
                                }))
                            }
                            min={today}
                            invalid={Boolean(form.errors.until)}
                        />
                    </Field>

                    <p className="text-xs text-muted-foreground">
                        {t('weeklies.away.effect')}
                    </p>

                    {canRequestAbsence ? (
                        <div className="grid gap-1">
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.request_absence}
                                    disabled={!form.data.until}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'request_absence',
                                            checked === true,
                                        )
                                    }
                                    className="mt-0.5"
                                    data-test="weekly-away-request-absence"
                                />
                                <span className="grid gap-0.5">
                                    <span>
                                        {t('weeklies.away.request_absence')}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {form.data.until
                                            ? t(
                                                  'weeklies.away.request_absence_help',
                                              )
                                            : t(
                                                  'weeklies.away.request_absence_needs_until',
                                              )}
                                    </span>
                                </span>
                            </label>
                            <InputError message={form.errors.request_absence} />
                            <Link
                                href={absencesIndex.url({
                                    query: { solicitar: 1 },
                                })}
                                className="text-xs underline underline-offset-2"
                            >
                                {t('weeklies.away.request_absence_link')}
                            </Link>
                        </div>
                    ) : null}

                    {!self && can?.viewTeamAbsences ? (
                        <Link
                            href={teamAbsences.url()}
                            className="text-xs underline underline-offset-2"
                        >
                            {t('weeklies.away.team_absences_link')}
                        </Link>
                    ) : null}

                    <DialogFooter className="gap-2">
                        {current ? (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing || clearing}
                                className="sm:mr-auto"
                                data-test="weekly-away-clear"
                                onClick={() =>
                                    router.delete(clearAway.url(person.id), {
                                        preserveScroll: true,
                                        onStart: () => setClearing(true),
                                        onFinish: () => setClearing(false),
                                        onSuccess: () => setOpen(false),
                                    })
                                }
                            >
                                {clearing ? (
                                    <Spinner />
                                ) : (
                                    <UserCheck aria-hidden="true" />
                                )}
                                {self
                                    ? t('weeklies.away.clear_self')
                                    : t('weeklies.away.clear_other')}
                            </Button>
                        ) : null}
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={form.processing || clearing}
                            data-test="weekly-away-save"
                        >
                            {form.processing && <Spinner />}
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
