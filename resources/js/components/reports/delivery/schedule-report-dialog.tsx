import { useForm } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import {
    reportVersionOf,
    withVersion,
} from '@/components/reports/report-request';
import { store, update } from '@/routes/reports/schedules';
import type {
    RelativePeriod,
    ReportScheduleDetail,
    ReportVersion,
    ScheduleFrequency,
} from '@/types/report-deliveries';
import type { ReportRequestData } from '@/types/reports';
import type {
    DeliveryData,
    DeliveryErrors,
    DeliveryPayload,
} from './delivery-fields';
import {
    FormatsField,
    MAX_RECIPIENTS,
    MessageFields,
    RecipientsField,
    VersionField,
    withDraft,
} from './delivery-fields';
import {
    describeSchedule,
    LAST_DAY,
    MONTH_DAYS,
    relativeLabel,
    reportPeriodOf,
    WEEKDAYS,
    weekdayName,
} from './schedule-preview';
import { useDeliveryPeople } from './use-delivery-people';

export type ScheduleData = DeliveryData & {
    relative_period: RelativePeriod;
    frequency: ScheduleFrequency;
    run_date: string | null;
    weekday: number | null;
    month_day: number | null;
    time: string;
};

const FREQUENCIES: readonly ScheduleFrequency[] = ['once', 'weekly', 'monthly'];

const RELATIVES: readonly RelativePeriod[] = ['previous', 'current', 'fixed'];

/** Informes sin periodo (la bolsa de horas): siempre «fijo». */
const WITHOUT_PERIOD = ['hour_bank'];

/** Mañana en Madrid, "YYYY-MM-DD" (la fecha por defecto de «una vez»). */
export function tomorrowInMadrid(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Madrid',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(now.getTime() + 86_400_000));
}

export function initialScheduleData(
    request: ReportRequestData,
    title: string,
    schedule?: ReportScheduleDetail,
): ScheduleData {
    const fixedOnly = WITHOUT_PERIOD.includes(request.kind);

    if (schedule) {
        return {
            title: schedule.title,
            formats: schedule.formats,
            recipient_user_ids: schedule.recipient_user_ids,
            recipient_emails: schedule.recipient_emails,
            subject: schedule.subject ?? '',
            message: schedule.message ?? '',
            relative_period: schedule.relative_period,
            frequency: schedule.frequency,
            run_date: schedule.run_date ?? tomorrowInMadrid(),
            weekday: schedule.weekday ?? 1,
            month_day: schedule.month_day ?? 1,
            time: schedule.time,
        };
    }

    return {
        title,
        formats: ['pdf'],
        recipient_user_ids: [],
        recipient_emails: [],
        subject: '',
        message: '',
        relative_period: fixedOnly ? 'fixed' : 'previous',
        frequency: 'monthly',
        run_date: tomorrowInMadrid(),
        weekday: 1,
        month_day: 1,
        time: '08:00',
    };
}

/** Lo que se envía: el informe y solo los campos de la frecuencia elegida. */
export function schedulePayload(
    data: ScheduleData,
    request: ReportRequestData,
): DeliveryPayload<ScheduleData> {
    return {
        ...data,
        request,
        run_date: data.frequency === 'once' ? data.run_date : null,
        weekday: data.frequency === 'weekly' ? data.weekday : null,
        month_day: data.frequency === 'monthly' ? data.month_day : null,
    };
}

/**
 * «Programar envío…» (D-141): el informe con sus filtros, una vez (fecha y hora), cada semana (día
 * y hora) o cada mes (día 1-28 o el último, y hora), en hora de Madrid, con el periodo relativo
 * (el anterior, el en curso o fijo) y la frase en lenguaje natural de lo que va a pasar. Con
 * `schedule`, edita uno ya programado (desde /informes/envios). El formulario vive dentro del
 * contenido del diálogo: cada vez que se abre empieza de cero. Con `versions` (informe de
 * proyecto, D-240), se elige la versión, que se guarda con el informe (su `version=`).
 */
export function ScheduleReportDialog({
    open,
    onOpenChange,
    request,
    title,
    schedule,
    versions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    request: ReportRequestData;
    title: string;
    schedule?: ReportScheduleDetail;
    versions?: readonly ReportVersion[];
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
                data-test="schedule-report-dialog"
            >
                <ScheduleReportForm
                    request={request}
                    title={title}
                    schedule={schedule}
                    versions={versions}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function ScheduleReportForm({
    request,
    title,
    schedule,
    versions,
    onDone,
}: {
    request: ReportRequestData;
    title: string;
    schedule?: ReportScheduleDetail;
    versions?: readonly ReportVersion[];
    onDone: () => void;
}) {
    const id = useId();
    const form = useForm<ScheduleData>(
        initialScheduleData(request, title, schedule),
    );
    const [draft, setDraft] = useState('');
    const [draftError, setDraftError] = useState<string | null>(null);
    const { people, failed } = useDeliveryPeople(true);
    const errors = form.errors as DeliveryErrors;
    const data = form.data;
    const report = schedule?.request ?? request;
    const [version, setVersion] = useState<ReportVersion>(
        reportVersionOf(report),
    );
    const choosesVersion = (versions?.length ?? 0) > 1;
    const period = reportPeriodOf(report.query);
    const fixedOnly = WITHOUT_PERIOD.includes(report.kind);

    const set = <K extends keyof ScheduleData>(
        key: K,
        value: ScheduleData[K],
    ) => form.setData((current) => ({ ...current, [key]: value }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const emails = withDraft(data.recipient_emails, draft);

        if (emails === null) {
            setDraftError(t('deliveries.recipients.invalid', { email: draft }));

            return;
        }

        setDraftError(null);
        form.transform((current) =>
            schedulePayload(
                { ...current, recipient_emails: emails },
                choosesVersion ? withVersion(report, version) : report,
            ),
        );

        const visit = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onDone,
        };

        if (schedule) {
            form.put(update.url(schedule.id), visit);
        } else {
            form.post(store.url(), visit);
        }
    };

    const total = data.recipient_user_ids.length + data.recipient_emails.length;
    const generalError = errors.request ?? errors.title;

    return (
        <form noValidate onSubmit={submit} className="grid gap-6">
            <DialogHeader>
                <DialogTitle>
                    {schedule
                        ? t('deliveries.schedule.edit_title')
                        : t('deliveries.schedule.title')}
                </DialogTitle>
                <DialogDescription>
                    {t('deliveries.schedule.description', {
                        title: data.title,
                    })}
                </DialogDescription>
            </DialogHeader>

            <InputError message={generalError} />

            {choosesVersion && versions ? (
                <VersionField
                    id={`${id}-version`}
                    versions={versions}
                    value={version}
                    onChange={setVersion}
                />
            ) : null}

            <fieldset className="grid gap-4">
                <legend className="mb-2 text-sm font-medium">
                    {t('deliveries.schedule.when')}
                </legend>
                <RadioGroup
                    value={data.frequency}
                    onValueChange={(value) =>
                        set('frequency', value as ScheduleFrequency)
                    }
                    className="flex flex-wrap gap-4"
                    aria-label={t('deliveries.schedule.frequency')}
                >
                    {FREQUENCIES.map((frequency) => (
                        <div
                            key={frequency}
                            className="flex items-center gap-2"
                        >
                            <RadioGroupItem
                                id={`${id}-frequency-${frequency}`}
                                value={frequency}
                            />
                            <Label
                                htmlFor={`${id}-frequency-${frequency}`}
                                className="font-normal"
                            >
                                {t(`deliveries.frequency.${frequency}`)}
                            </Label>
                        </div>
                    ))}
                </RadioGroup>
                <InputError message={errors.frequency} />

                <div className="grid gap-4 sm:grid-cols-2">
                    {data.frequency === 'once' ? (
                        <Field
                            id={`${id}-date`}
                            label={t('deliveries.schedule.date')}
                            error={errors.run_date}
                        >
                            <DatePicker
                                id={`${id}-date`}
                                value={data.run_date}
                                onChange={(value) => set('run_date', value)}
                                invalid={Boolean(errors.run_date)}
                            />
                        </Field>
                    ) : null}
                    {data.frequency === 'weekly' ? (
                        <Field
                            id={`${id}-weekday`}
                            label={t('deliveries.schedule.weekday')}
                            error={errors.weekday}
                        >
                            <NativeSelect
                                id={`${id}-weekday`}
                                value={String(data.weekday ?? 1)}
                                aria-invalid={errors.weekday ? true : undefined}
                                aria-describedby={describedBy(`${id}-weekday`, {
                                    error: errors.weekday,
                                })}
                                onChange={(event) =>
                                    set('weekday', Number(event.target.value))
                                }
                            >
                                {WEEKDAYS.map((weekday) => (
                                    <option key={weekday} value={weekday}>
                                        {weekdayName(weekday)}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    ) : null}
                    {data.frequency === 'monthly' ? (
                        <Field
                            id={`${id}-month-day`}
                            label={t('deliveries.schedule.month_day')}
                            error={errors.month_day}
                        >
                            <NativeSelect
                                id={`${id}-month-day`}
                                value={String(data.month_day ?? 1)}
                                aria-invalid={
                                    errors.month_day ? true : undefined
                                }
                                aria-describedby={describedBy(
                                    `${id}-month-day`,
                                    { error: errors.month_day },
                                )}
                                onChange={(event) =>
                                    set('month_day', Number(event.target.value))
                                }
                            >
                                {MONTH_DAYS.map((day) => (
                                    <option key={day} value={day}>
                                        {t(
                                            'deliveries.schedule.month_day_option',
                                            { day },
                                        )}
                                    </option>
                                ))}
                                <option value={LAST_DAY}>
                                    {t('deliveries.schedule.last_day')}
                                </option>
                            </NativeSelect>
                        </Field>
                    ) : null}
                    <Field
                        id={`${id}-time`}
                        label={t('deliveries.schedule.time')}
                        help={t('deliveries.schedule.time_help')}
                        error={errors.time}
                    >
                        <Input
                            id={`${id}-time`}
                            type="time"
                            step={60}
                            required
                            value={data.time}
                            aria-invalid={errors.time ? true : undefined}
                            aria-describedby={describedBy(`${id}-time`, {
                                help: true,
                                error: errors.time,
                            })}
                            onChange={(event) =>
                                set('time', event.target.value)
                            }
                        />
                    </Field>
                </div>

                {fixedOnly ? null : (
                    <Field
                        id={`${id}-relative`}
                        label={t('deliveries.schedule.period')}
                        help={t('deliveries.schedule.period_help')}
                        error={errors.relative_period}
                    >
                        <NativeSelect
                            id={`${id}-relative`}
                            value={data.relative_period}
                            aria-invalid={
                                errors.relative_period ? true : undefined
                            }
                            aria-describedby={describedBy(`${id}-relative`, {
                                help: true,
                                error: errors.relative_period,
                            })}
                            onChange={(event) =>
                                set(
                                    'relative_period',
                                    event.target.value as RelativePeriod,
                                )
                            }
                        >
                            {RELATIVES.map((relative) => (
                                <option key={relative} value={relative}>
                                    {relativeLabel(relative, period)}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                )}

                <p
                    className="flex items-start gap-2 rounded-md border bg-muted/60 p-3 text-sm"
                    aria-live="polite"
                    data-test="schedule-preview"
                >
                    <CalendarClock
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <span>
                        <span className="sr-only">
                            {t('deliveries.schedule.preview_label')}:{' '}
                        </span>
                        {describeSchedule(data, data.relative_period, period)}
                    </span>
                </p>
            </fieldset>

            <FormatsField
                id={`${id}-formats`}
                value={data.formats}
                onChange={(formats) => set('formats', formats)}
                error={errors.formats ?? errors['formats.0']}
            />

            <RecipientsField
                id={`${id}-recipients`}
                people={people}
                peopleFailed={failed}
                userIds={data.recipient_user_ids}
                emails={data.recipient_emails}
                draft={draft}
                onUserIds={(ids) => set('recipient_user_ids', ids)}
                onEmails={(emails) => set('recipient_emails', emails)}
                onDraft={(value) => {
                    setDraftError(null);
                    setDraft(value);
                }}
                errors={
                    draftError
                        ? { ...errors, recipient_emails: draftError }
                        : errors
                }
            />

            <MessageFields
                id={`${id}-message`}
                title={data.title}
                subject={data.subject}
                message={data.message}
                onSubject={(subject) => set('subject', subject)}
                onMessage={(message) => set('message', message)}
                errors={errors}
            />

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
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        data.formats.length === 0 ||
                        total > MAX_RECIPIENTS
                    }
                >
                    {form.processing ? (
                        <Spinner />
                    ) : (
                        <CalendarClock aria-hidden="true" />
                    )}
                    {schedule
                        ? t('deliveries.schedule.save')
                        : t('deliveries.schedule.submit')}
                </Button>
            </DialogFooter>
        </form>
    );
}
