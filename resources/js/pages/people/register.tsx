import { Head } from '@inertiajs/react';
import { FileDown, FileSpreadsheet, FileText } from 'lucide-react';
import { useId, useState } from 'react';
import { PeopleFrame } from '@/components/people/people-ui';
import {
    BalanceMovementList,
    CapBar,
    CloseAnswerCard,
    CloseStatusBadge,
    HashText,
    PendingCompensationList,
} from '@/components/people/register-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatDateTime, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { formatDifference, monthLabel } from '@/lib/people';
import { destinationLabel, hourTypeLabel } from '@/lib/people-register';
import { cn } from '@/lib/utils';
import { download } from '@/routes/people/register';
import { pdf } from '@/routes/people/closes';
import type { MonthClose, RegisterPageProps } from '@/types/people-register';

/**
 * «Mi registro» (`/personas/registro`, PLAN-FASE-11 §6.1.6 y §11.3; D-346): el resumen del mes por
 * confirmar, la descarga del registro de cualquier periodo (PDF, Excel o CSV, cada fichero con su
 * huella), los cierres de cada mes con su PDF, las horas extra del año frente al tope y el saldo de
 * horas con sus plazos.
 */
export default function RegisterPage(props: RegisterPageProps) {
    const awaiting = props.closes.filter((close) =>
        ['pending', 'disagreed'].includes(close.status),
    );
    const history = props.closes.filter(
        (close) => !awaiting.some((item) => item.id === close.id),
    );

    return (
        <>
            <Head title={t('people.register.title')} />
            <PeopleFrame
                section="register"
                title={t('people.register.title')}
                description={t('people.register.description')}
            >
                {awaiting.map((close) => (
                    <CloseAnswerCard key={close.id} close={close} />
                ))}

                <DownloadSection
                    period={props.period}
                    today={props.today}
                    maxDays={props.max_days}
                />

                <section
                    aria-labelledby="closes-heading"
                    className="grid gap-3"
                >
                    <div className="space-y-1">
                        <h2 id="closes-heading" className="text-lg">
                            {t('people.register.closes_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('people.register.closes_hint')}
                        </p>
                    </div>
                    {props.closes.length === 0 ? (
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="closes-empty"
                        >
                            {t('people.register.no_closes')}
                        </p>
                    ) : (
                        <CloseList closes={[...awaiting, ...history]} />
                    )}
                </section>

                <section
                    id="horas-extra"
                    aria-labelledby="overtime-heading"
                    className="grid gap-4"
                >
                    <div className="space-y-1">
                        <h2 id="overtime-heading" className="text-lg">
                            {t('people.register.overtime_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('people.register.overtime_hint')}
                        </p>
                    </div>
                    <CapBar summary={props.overtime} />
                    <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <Stat
                            label={t('people.register.compensate')}
                            value={formatMinutes(
                                props.overtime.compensate_minutes,
                            )}
                        />
                        <Stat
                            label={t('people.register.pay')}
                            value={formatMinutes(props.overtime.pay_minutes)}
                        />
                        <Stat
                            label={t('people.register.complementary')}
                            value={formatMinutes(
                                props.overtime.complementary_minutes,
                            )}
                        />
                        <Stat
                            label={t('people.register.remaining')}
                            value={formatMinutes(
                                props.overtime.remaining_minutes,
                            )}
                        />
                    </dl>
                    {props.decisions.length > 0 ? (
                        <ul
                            className="divide-y rounded-md border text-sm"
                            data-test="my-decisions"
                        >
                            {props.decisions.map((decision) => (
                                <li
                                    key={decision.id}
                                    className="grid gap-0.5 px-3 py-2 sm:grid-cols-[7rem_1fr_auto] sm:gap-3"
                                >
                                    <span className="tabular text-xs text-muted-foreground">
                                        {formatDate(decision.date)}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block">
                                            {decision.overtime_minutes > 0
                                                ? `${hourTypeLabel(decision.hour_type)} · ${destinationLabel(decision.destination)}`
                                                : destinationLabel(null)}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {t('people.register.decided_by', {
                                                name: decision.decided_by,
                                                date: formatDateTime(
                                                    decision.decided_at,
                                                ),
                                            })}
                                        </span>
                                    </span>
                                    <span className="tabular sm:text-right">
                                        {formatMinutes(
                                            decision.overtime_minutes,
                                        )}
                                        {decision.flex_minutes > 0 ? (
                                            <span className="block text-xs text-muted-foreground">
                                                {t('people.register.flex', {
                                                    minutes: formatMinutes(
                                                        decision.flex_minutes,
                                                    ),
                                                })}
                                            </span>
                                        ) : null}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('people.register.no_decisions')}
                        </p>
                    )}
                </section>

                <section
                    aria-labelledby="balance-heading"
                    className="grid gap-4"
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <div className="space-y-1">
                            <h2 id="balance-heading" className="text-lg">
                                {t('people.register.balance_title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('people.register.balance_hint')}
                            </p>
                        </div>
                        <p
                            className="tabular text-2xl"
                            data-test="balance-total"
                        >
                            {formatMinutes(props.balance.minutes)}
                        </p>
                    </div>
                    <PendingCompensationList pending={props.balance.pending} />
                    <BalanceMovementList movements={props.balance.movements} />
                </section>
            </PeopleFrame>
        </>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="tabular text-lg">{value}</dd>
        </div>
    );
}

function DownloadSection({
    period,
    today,
    maxDays,
}: {
    period: { from: string; to: string };
    today: string;
    maxDays: number;
}) {
    const id = useId();
    const [from, setFrom] = useState(period.from);
    const [to, setTo] = useState(period.to);
    const valid = from !== '' && to !== '' && from <= to && to <= today;
    const href = (format: 'pdf' | 'xlsx' | 'csv') =>
        download.url({ query: { desde: from, hasta: to, formato: format } });

    return (
        <section
            aria-labelledby={`${id}-title`}
            className="grid gap-3 rounded-md border p-4"
        >
            <div className="space-y-1">
                <h2 id={`${id}-title`} className="text-lg">
                    {t('people.register.download_title')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('people.register.download_hint', { days: maxDays })}
                </p>
            </div>
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-from`}>
                        {t('people.register.from')}
                    </Label>
                    <Input
                        id={`${id}-from`}
                        type="date"
                        value={from}
                        max={today}
                        onChange={(event) => setFrom(event.target.value)}
                        className="w-44"
                        data-test="register-from"
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-to`}>
                        {t('people.register.to')}
                    </Label>
                    <Input
                        id={`${id}-to`}
                        type="date"
                        value={to}
                        max={today}
                        onChange={(event) => setTo(event.target.value)}
                        className="w-44"
                        data-test="register-to"
                    />
                </div>
                <div className="flex flex-wrap gap-2">
                    {(
                        [
                            [
                                'pdf',
                                FileText,
                                t('people.register.download_pdf'),
                            ],
                            [
                                'xlsx',
                                FileSpreadsheet,
                                t('people.register.download_xlsx'),
                            ],
                            [
                                'csv',
                                FileDown,
                                t('people.register.download_csv'),
                            ],
                        ] as const
                    ).map(([format, Icon, label]) => (
                        <Button
                            key={format}
                            asChild={valid}
                            variant={format === 'pdf' ? 'default' : 'outline'}
                            disabled={!valid}
                        >
                            {valid ? (
                                <a
                                    href={href(format)}
                                    data-test={`register-download-${format}`}
                                >
                                    <Icon aria-hidden="true" />
                                    {label}
                                </a>
                            ) : (
                                <span>
                                    <Icon aria-hidden="true" />
                                    {label}
                                </span>
                            )}
                        </Button>
                    ))}
                </div>
            </div>
            {!valid ? (
                <p className="text-xs text-danger">
                    {t('people.register.invalid_period')}
                </p>
            ) : null}
        </section>
    );
}

function closeNote(close: MonthClose): string | null {
    switch (close.status) {
        case 'confirmed':
            return t('people.register.confirmed_on', {
                date: formatDateTime(close.confirmed_at),
            });
        case 'disagreed':
            return close.disagreement_note;
        case 'reopened':
            return t('people.register.reopened_note', {
                name: close.reopened_by ?? '',
                date: formatDate(close.reopened_at),
                reason: close.reopen_reason ?? '',
            });
        case 'superseded':
            return t('people.register.superseded_note');
        default:
            return null;
    }
}

function CloseList({ closes }: { closes: MonthClose[] }) {
    return (
        <ul className="grid gap-2" data-test="close-list">
            {closes.map((close) => {
                const note = closeNote(close);

                return (
                    <li
                        key={close.id}
                        className={cn(
                            'grid gap-2 rounded-md border p-3 text-sm sm:grid-cols-[minmax(9rem,1fr)_auto_auto] sm:items-center sm:gap-4',
                            close.status === 'superseded' &&
                                'text-muted-foreground',
                        )}
                        data-test="close-item"
                        data-month={close.month}
                        data-status={close.status}
                    >
                        <div className="min-w-0">
                            <p className="first-letter:uppercase">
                                {monthLabel(close.month)}
                                <span className="ml-2 text-xs text-muted-foreground">
                                    {t('people.register.version', {
                                        version: close.version,
                                    })}
                                </span>
                            </p>
                            <p className="tabular text-xs text-muted-foreground">
                                {t('people.register.close_figures', {
                                    worked: formatMinutes(close.worked_minutes),
                                    expected: formatMinutes(
                                        close.expected_minutes,
                                    ),
                                    difference: formatDifference(
                                        close.difference_minutes,
                                    ),
                                })}
                            </p>
                            {note ? (
                                <p className="mt-1 text-xs break-words">
                                    {note}
                                </p>
                            ) : null}
                        </div>
                        <CloseStatusBadge
                            status={close.status}
                            className="justify-self-start"
                        />
                        <div className="flex flex-wrap items-center gap-2">
                            <HashText
                                hash={close.pdf_sha256}
                                label={t('people.closes.pdf_hash')}
                            />
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={pdf.url(close.id)}
                                    aria-label={t(
                                        'people.register.download_close',
                                        {
                                            month: monthLabel(close.month),
                                            version: close.version,
                                        },
                                    )}
                                >
                                    <FileDown aria-hidden="true" />
                                    {t('people.register.pdf')}
                                </a>
                            </Button>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
