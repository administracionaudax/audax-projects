import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Save, Wallet } from 'lucide-react';
import { useId, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { PeopleFrame } from '@/components/people/people-ui';
import {
    BalanceMovementList,
    CapBar,
    PendingCompensationList,
} from '@/components/people/register-ui';
import { Button } from '@/components/ui/button';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { parseDuration } from '@/lib/duration';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { longDayLabel, monthLabel, shiftMonth, tCount } from '@/lib/people';
import {
    balanceKindLabel,
    destinationLabel,
    flexFor,
    hourTypeLabel,
    restMinutes,
} from '@/lib/people-register';
import { cn } from '@/lib/utils';
import { store as storeBalance } from '@/routes/people/balance';
import { index, store } from '@/routes/people/overtime';
import type {
    BalanceKind,
    OvertimeDestination,
    OvertimePageProps,
    PendingOvertime,
} from '@/types/people-register';

const MANUAL_KINDS: BalanceKind[] = [
    'rest_taken',
    'paid',
    'adjustment',
    'opening_balance',
];

/**
 * «Horas extra» (`/personas/horas-extra`, PLAN-FASE-11 §7.2; D-349 y D-350; W-047, W-052 y W-053):
 * el exceso por clasificar (hora extra con su destino, o flexibilidad), lo clasificado del mes y,
 * por persona, sus horas extra del año frente al tope de 80 h y su saldo de horas. Nadie decide lo
 * suyo.
 */
export default function OvertimePage(props: OvertimePageProps) {
    const url = (query: Record<string, string>) => index.url({ query });

    return (
        <>
            <Head title={t('people.overtime.title')} />
            <PeopleFrame
                section="overtime"
                title={t('people.overtime.title')}
                description={t('people.overtime.description', {
                    rest: props.rest_minutes_per_hour,
                })}
            >
                <nav
                    aria-label={t('people.workday.month_nav')}
                    className="flex items-center gap-1"
                >
                    <Button
                        asChild
                        variant="outline"
                        size="icon"
                        aria-label={t('people.workday.previous_month')}
                    >
                        <Link
                            href={url({ mes: shiftMonth(props.month, -1) })}
                            preserveScroll
                        >
                            <ChevronLeft aria-hidden="true" />
                        </Link>
                    </Button>
                    <span className="min-w-44 text-center text-lg first-letter:uppercase">
                        {monthLabel(props.month)}
                    </span>
                    {props.month < props.current_month ? (
                        <Button
                            asChild
                            variant="outline"
                            size="icon"
                            aria-label={t('people.workday.next_month')}
                        >
                            <Link
                                href={url({ mes: shiftMonth(props.month, 1) })}
                                preserveScroll
                            >
                                <ChevronRight aria-hidden="true" />
                            </Link>
                        </Button>
                    ) : (
                        <Button
                            variant="outline"
                            size="icon"
                            disabled
                            aria-label={t('people.workday.next_month')}
                        >
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    )}
                </nav>

                <section
                    aria-labelledby="pending-overtime"
                    className="grid gap-3"
                >
                    <div className="space-y-1">
                        <h2 id="pending-overtime" className="text-lg">
                            {t('people.overtime.pending_title', {
                                count: props.pending.length,
                            })}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('people.overtime.pending_hint')}
                        </p>
                    </div>
                    {props.pending.length === 0 ? (
                        <EmptyState
                            icon={Wallet}
                            title={t('people.overtime.nothing_pending')}
                            description={t(
                                'people.overtime.nothing_pending_hint',
                            )}
                        />
                    ) : (
                        <ul className="grid gap-3">
                            {props.pending.map((item) => (
                                <li key={`${item.user.id}-${item.date}`}>
                                    <DecisionForm
                                        item={item}
                                        restPerHour={
                                            props.rest_minutes_per_hour
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section
                    aria-labelledby="decided-overtime"
                    className="grid gap-3"
                >
                    <h2 id="decided-overtime" className="text-lg">
                        {t('people.overtime.decided_title')}
                    </h2>
                    {props.decided.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('people.overtime.nothing_decided')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-md border">
                            <table
                                className="w-full text-sm"
                                data-test="decided-table"
                            >
                                <caption className="sr-only">
                                    {t('people.overtime.decided_title')}
                                </caption>
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('people.overtime.col_person')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('people.overtime.col_day')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-right font-medium"
                                        >
                                            {t('people.overtime.col_excess')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-right font-medium"
                                        >
                                            {t('people.overtime.col_overtime')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t(
                                                'people.overtime.col_destination',
                                            )}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('people.overtime.col_decided')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {props.decided.map((decision) => (
                                        <tr
                                            key={decision.id}
                                            className="border-b last:border-0"
                                        >
                                            <th
                                                scope="row"
                                                className="px-3 py-2 text-left font-normal"
                                            >
                                                {decision.user?.name}
                                            </th>
                                            <td className="tabular px-3 py-2">
                                                {formatDate(decision.date)}
                                            </td>
                                            <td className="tabular px-3 py-2 text-right">
                                                {formatMinutes(
                                                    decision.excess_minutes,
                                                )}
                                            </td>
                                            <td className="tabular px-3 py-2 text-right">
                                                {formatMinutes(
                                                    decision.overtime_minutes,
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                {decision.overtime_minutes > 0
                                                    ? `${hourTypeLabel(decision.hour_type)} · ${destinationLabel(decision.destination)}`
                                                    : destinationLabel(null)}
                                            </td>
                                            <td className="px-3 py-2 text-xs text-muted-foreground">
                                                {decision.decided_by}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section
                    aria-labelledby="people-overtime"
                    className="grid gap-3"
                >
                    <div className="space-y-1">
                        <h2 id="people-overtime" className="text-lg">
                            {t('people.overtime.people_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('people.overtime.people_hint')}
                        </p>
                    </div>
                    <ul
                        className="grid gap-3 md:grid-cols-2"
                        data-test="overtime-people"
                    >
                        {props.people.map((person) => (
                            <li
                                key={person.user.id}
                                className={cn(
                                    'grid gap-3 rounded-md border p-3',
                                    props.person?.user.id === person.user.id &&
                                        'border-primary',
                                )}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <span>
                                        {person.user.name}
                                        {person.part_time ? (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                {t('people.overtime.part_time')}
                                            </span>
                                        ) : null}
                                    </span>
                                    <Link
                                        href={url({
                                            mes: props.month,
                                            persona: String(person.user.id),
                                        })}
                                        preserveScroll
                                        className="text-sm text-primary-text underline-offset-4 hover:underline"
                                        data-test="overtime-person-link"
                                    >
                                        {t('people.overtime.balance_link', {
                                            minutes: formatMinutes(
                                                person.balance_minutes,
                                            ),
                                        })}
                                    </Link>
                                </div>
                                <CapBar summary={person.year} />
                                {person.expiring > 0 ? (
                                    <p className="rounded-md bg-warning-soft px-2 py-1 text-xs">
                                        {tCount(
                                            'people.overtime.expiring',
                                            person.expiring,
                                        )}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </section>

                {props.person ? (
                    <section
                        aria-labelledby="person-balance"
                        className="grid gap-4 rounded-md border p-4"
                        data-test="person-balance"
                    >
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 id="person-balance" className="text-lg">
                                {t('people.overtime.balance_of', {
                                    name: props.person.user.name,
                                })}
                            </h2>
                            <p className="tabular text-2xl">
                                {formatMinutes(props.person.balance_minutes)}
                            </p>
                        </div>
                        <PendingCompensationList
                            pending={props.person.pending}
                        />
                        <BalanceForm
                            userId={props.person.user.id}
                            managesAll={props.manages_all}
                        />
                        <BalanceMovementList
                            movements={props.person.movements}
                        />
                    </section>
                ) : null}
            </PeopleFrame>
        </>
    );
}

function DecisionForm({
    item,
    restPerHour,
}: {
    item: PendingOvertime;
    restPerHour: number;
}) {
    const id = useId();
    const [text, setText] = useState(formatMinutes(item.excess_minutes));
    const [destination, setDestination] = useState<OvertimeDestination>(
        item.part_time ? 'pay' : 'compensate',
    );
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const overtime =
        text.trim() === '0' || text.trim() === '0:00'
            ? 0
            : parseDuration(text, item.excess_minutes);
    const flex =
        overtime === null ? null : flexFor(item.excess_minutes, overtime);
    const disabled = item.month_confirmed;

    return (
        <form
            className="grid gap-3 rounded-md border p-3"
            aria-labelledby={`${id}-title`}
            data-test="overtime-item"
            data-date={item.date}
            onSubmit={(event) => {
                event.preventDefault();
                if (overtime === null) {
                    return;
                }
                router.post(
                    store.url(),
                    {
                        user_id: item.user.id,
                        date: item.date,
                        overtime_minutes: overtime,
                        destination: overtime > 0 ? destination : null,
                        note: note.trim() === '' ? null : note,
                    },
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                        onError: (bag) => setErrors(bag),
                    },
                );
            }}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 id={`${id}-title`} className="text-sm font-medium">
                        {item.user.name} ·{' '}
                        <span className="first-letter:uppercase">
                            {longDayLabel(item.date)}
                        </span>
                    </h3>
                    <p className="tabular text-xs text-muted-foreground">
                        {t('people.overtime.item_figures', {
                            worked: formatMinutes(item.worked_minutes),
                            expected: formatMinutes(item.expected_minutes),
                            excess: formatMinutes(item.excess_minutes),
                        })}
                    </p>
                </div>
                {item.stale ? (
                    <span
                        className="rounded-md bg-warning-soft px-1.5 py-0.5 text-xs"
                        data-test="overtime-stale"
                    >
                        {t('people.overtime.stale', {
                            minutes: formatMinutes(
                                item.previous?.overtime_minutes ?? 0,
                            ),
                        })}
                    </span>
                ) : null}
            </div>

            {disabled ? (
                <p className="text-xs text-muted-foreground">
                    {t('people.overtime.month_confirmed')}
                </p>
            ) : (
                <div className="grid gap-3 lg:grid-cols-[auto_1fr_1fr_auto] lg:items-end">
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${id}-minutes`}>
                            {item.part_time
                                ? t('people.overtime.complementary_label')
                                : t('people.overtime.overtime_label')}
                        </Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id={`${id}-minutes`}
                                value={text}
                                onChange={(event) =>
                                    setText(event.target.value)
                                }
                                inputMode="numeric"
                                className="tabular w-24"
                                aria-invalid={
                                    overtime === null || errors.overtime_minutes
                                        ? true
                                        : undefined
                                }
                                aria-describedby={`${id}-flex`}
                                data-test="overtime-minutes"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setText('0')}
                                data-test="overtime-all-flex"
                            >
                                {t('people.overtime.all_flex')}
                            </Button>
                        </div>
                        <p
                            id={`${id}-flex`}
                            className="text-xs text-muted-foreground"
                        >
                            {flex === null
                                ? t('people.overtime.invalid_minutes', {
                                      max: formatMinutes(item.excess_minutes),
                                  })
                                : t('people.overtime.flex_rest', {
                                      minutes: formatMinutes(flex),
                                  })}
                        </p>
                        <InputError
                            message={errors.overtime_minutes ?? errors.date}
                        />
                    </div>
                    <fieldset
                        className="grid gap-1.5"
                        disabled={overtime === 0}
                    >
                        <legend className="mb-1.5 text-sm">
                            {t('people.overtime.destination_label')}
                        </legend>
                        <RadioGroup
                            value={destination}
                            onValueChange={(value) =>
                                setDestination(value as OvertimeDestination)
                            }
                            className="grid gap-1.5"
                        >
                            {(['compensate', 'pay'] as const).map((value) => (
                                <div
                                    key={value}
                                    className="flex items-center gap-2"
                                >
                                    <RadioGroupItem
                                        id={`${id}-${value}`}
                                        value={value}
                                        disabled={
                                            item.part_time &&
                                            value === 'compensate'
                                        }
                                        data-test={`overtime-destination-${value}`}
                                    />
                                    <Label
                                        htmlFor={`${id}-${value}`}
                                        className="font-normal"
                                    >
                                        {value === 'compensate' && overtime
                                            ? t(
                                                  'people.overtime.compensate_with',
                                                  {
                                                      rest: formatMinutes(
                                                          restMinutes(
                                                              overtime,
                                                              restPerHour,
                                                          ),
                                                      ),
                                                  },
                                              )
                                            : destinationLabel(value)}
                                    </Label>
                                </div>
                            ))}
                        </RadioGroup>
                        <InputError message={errors.destination} />
                    </fieldset>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${id}-note`}>
                            {t('people.overtime.note_label')}
                        </Label>
                        <Input
                            id={`${id}-note`}
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            maxLength={500}
                            data-test="overtime-note"
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={
                            processing || overtime === null || flex === null
                        }
                        data-test="overtime-save"
                    >
                        {processing ? <Spinner /> : <Save aria-hidden="true" />}
                        {t('people.overtime.save')}
                    </Button>
                </div>
            )}
        </form>
    );
}

function BalanceForm({
    userId,
    managesAll,
}: {
    userId: number;
    managesAll: boolean;
}) {
    const id = useId();
    const kinds = MANUAL_KINDS.filter(
        (kind) => managesAll || kind === 'rest_taken' || kind === 'paid',
    );
    const [kind, setKind] = useState<BalanceKind>('rest_taken');
    const [text, setText] = useState('');
    const [negative, setNegative] = useState(false);
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const minutes = parseDuration(text, 100000);
    const signed =
        kind === 'adjustment' || kind === 'opening_balance'
            ? negative
                ? -1
                : 1
            : -1;

    return (
        <form
            className="grid gap-3 rounded-md bg-muted/40 p-3"
            aria-labelledby={`${id}-title`}
            data-test="balance-form"
            onSubmit={(event) => {
                event.preventDefault();
                if (minutes === null) {
                    return;
                }
                router.post(
                    storeBalance.url(),
                    {
                        user_id: userId,
                        kind,
                        minutes: signed * minutes,
                        date,
                        reason,
                    },
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                        onError: (bag) => setErrors(bag),
                        onSuccess: () => {
                            setText('');
                            setReason('');
                            setErrors({});
                        },
                    },
                );
            }}
        >
            <h3 id={`${id}-title`} className="text-sm font-medium">
                {t('people.balance.add_title')}
            </h3>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-kind`}>
                        {t('people.balance.kind_label')}
                    </Label>
                    <Select
                        value={kind}
                        onValueChange={(value) => setKind(value as BalanceKind)}
                    >
                        <SelectTrigger id={`${id}-kind`}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {kinds.map((value) => (
                                <SelectItem key={value} value={value}>
                                    {balanceKindLabel(value)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-minutes`}>
                        {t('people.balance.minutes_label')}
                    </Label>
                    <Input
                        id={`${id}-minutes`}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        placeholder="1:30"
                        className="tabular"
                        aria-invalid={errors.minutes ? true : undefined}
                        data-test="balance-minutes"
                    />
                    {kind === 'adjustment' || kind === 'opening_balance' ? (
                        <label className="flex items-center gap-2 text-xs">
                            <input
                                type="checkbox"
                                checked={negative}
                                onChange={(event) =>
                                    setNegative(event.target.checked)
                                }
                            />
                            {t('people.balance.negative')}
                        </label>
                    ) : null}
                    <InputError message={errors.minutes} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-date`}>
                        {t('people.balance.date_label')}
                    </Label>
                    <Input
                        id={`${id}-date`}
                        type="date"
                        value={date}
                        onChange={(event) => setDate(event.target.value)}
                    />
                    <InputError message={errors.date} />
                </div>
                <div className="grid gap-1.5 sm:col-span-2 lg:col-span-1">
                    <Label htmlFor={`${id}-reason`}>
                        {t('people.balance.reason_label')}
                    </Label>
                    <Textarea
                        id={`${id}-reason`}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        maxLength={500}
                        rows={1}
                        aria-invalid={errors.reason ? true : undefined}
                        data-test="balance-reason"
                    />
                    <InputError message={errors.reason} />
                </div>
            </div>
            <Button
                type="submit"
                className="justify-self-start"
                disabled={
                    processing || minutes === null || reason.trim().length < 5
                }
                data-test="balance-save"
            >
                {processing ? <Spinner /> : null}
                {t('people.balance.save')}
            </Button>
        </form>
    );
}
