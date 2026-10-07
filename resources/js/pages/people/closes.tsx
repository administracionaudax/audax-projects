import { Head, Link, router } from '@inertiajs/react';
import {
    BellRing,
    ChevronLeft,
    ChevronRight,
    FileDown,
    FileCheck2,
    RotateCcw,
} from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { PeopleFrame } from '@/components/people/people-ui';
import { CloseStatusBadge, HashText } from '@/components/people/register-ui';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { formatDifference, monthLabel, shiftMonth } from '@/lib/people';
import { generate, index, pdf, remind, reopen } from '@/routes/people/closes';
import type {
    CloseRow,
    CloseState,
    ClosesPageProps,
} from '@/types/people-register';

const STATES: CloseState[] = [
    'confirmed',
    'pending',
    'disagreed',
    'reopened',
    'missing',
];

/**
 * «Cierres» (`/personas/cierres?mes=AAAA-MM`, PLAN-FASE-11 §6.2.4 y §6.3.6; D-347; W-084, W-085 y
 * W-114): el estado del resumen del mes de cada persona (confirmado, pendiente, en desacuerdo,
 * desconfirmado o sin generar), generar los que faltan, desconfirmar con motivo y recordar. Para los
 * responsables (su departamento) y RR. HH. (todos).
 */
export default function ClosesPage(props: ClosesPageProps) {
    const [processing, setProcessing] = useState(false);
    const [reopening, setReopening] = useState<CloseRow | null>(null);
    const url = (query: Record<string, string>) => index.url({ query });
    const departmentQuery: Record<string, string> = props.department_id
        ? { departamento: String(props.department_id) }
        : {};
    const isPast = props.month < props.current_month;
    const generable = props.rows
        .filter((row) => row.can.generate && row.state !== 'reopened')
        .map((row) => row.user.id);

    return (
        <>
            <Head title={t('people.closes.title')} />
            <PeopleFrame
                section="closes"
                title={t('people.closes.title')}
                description={t('people.closes.description')}
                actions={
                    isPast && generable.length > 0 ? (
                        <Button
                            type="button"
                            disabled={processing}
                            onClick={() =>
                                router.post(
                                    generate.url(),
                                    { month: props.month, user_ids: generable },
                                    {
                                        preserveScroll: true,
                                        onStart: () => setProcessing(true),
                                        onFinish: () => setProcessing(false),
                                    },
                                )
                            }
                            data-test="closes-generate"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <FileCheck2 aria-hidden="true" />
                            )}
                            {t('people.closes.generate_all', {
                                count: generable.length,
                            })}
                        </Button>
                    ) : undefined
                }
            >
                <div className="flex flex-wrap items-center justify-between gap-3">
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
                                href={url({
                                    mes: shiftMonth(props.month, -1),
                                    ...departmentQuery,
                                })}
                                preserveScroll
                            >
                                <ChevronLeft aria-hidden="true" />
                            </Link>
                        </Button>
                        <h2
                            className="min-w-44 text-center text-lg first-letter:uppercase"
                            data-test="closes-month"
                        >
                            {monthLabel(props.month)}
                        </h2>
                        {props.month < props.current_month ? (
                            <Button
                                asChild
                                variant="outline"
                                size="icon"
                                aria-label={t('people.workday.next_month')}
                            >
                                <Link
                                    href={url({
                                        mes: shiftMonth(props.month, 1),
                                        ...departmentQuery,
                                    })}
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
                    {props.departments.length > 1 ? (
                        <Select
                            value={
                                props.department_id
                                    ? String(props.department_id)
                                    : 'all'
                            }
                            onValueChange={(value) =>
                                router.get(
                                    url({
                                        mes: props.month,
                                        ...(value === 'all'
                                            ? {}
                                            : { departamento: value }),
                                    }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <SelectTrigger
                                className="w-56"
                                aria-label={t('people.team.department')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    {t('people.team.all_departments')}
                                </SelectItem>
                                {props.departments.map((department) => (
                                    <SelectItem
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    ) : null}
                </div>

                <ul
                    className="flex flex-wrap gap-2 text-sm"
                    aria-label={t('people.closes.summary')}
                    data-test="closes-counts"
                >
                    {STATES.map((state) => (
                        <li
                            key={state}
                            className="flex items-center gap-1.5 rounded-md border px-2 py-1"
                        >
                            <CloseStatusBadge status={state} />
                            <span className="tabular">
                                {props.counts[state] ?? 0}
                            </span>
                        </li>
                    ))}
                </ul>

                {!isPast ? (
                    <p className="text-sm text-muted-foreground">
                        {t('people.closes.month_open')}
                    </p>
                ) : null}

                <div className="hidden overflow-x-auto rounded-md border md:block">
                    <table className="w-full text-sm" data-test="closes-table">
                        <caption className="sr-only">
                            {t('people.closes.caption')}
                        </caption>
                        <thead>
                            <tr className="border-b text-left text-xs text-muted-foreground">
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('people.closes.col_person')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('people.closes.col_state')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {t('people.closes.col_worked')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {t('people.closes.col_difference')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {t('people.closes.col_overtime')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('people.closes.col_detail')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    <span className="sr-only">
                                        {t('people.closes.col_actions')}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {props.rows.map((row) => (
                                <tr
                                    key={row.user.id}
                                    className="border-b align-top last:border-0"
                                    data-test="close-row"
                                    data-state={row.state}
                                >
                                    <th
                                        scope="row"
                                        className="px-3 py-2 text-left font-normal"
                                    >
                                        {row.user.name}
                                        {row.user.department ? (
                                            <span className="block text-xs text-muted-foreground">
                                                {row.user.department}
                                            </span>
                                        ) : null}
                                    </th>
                                    <td className="px-3 py-2">
                                        <CloseStatusBadge status={row.state} />
                                        {row.close && row.versions > 1 ? (
                                            <span className="mt-1 block text-xs text-muted-foreground">
                                                {t('people.register.version', {
                                                    version: row.close.version,
                                                })}
                                            </span>
                                        ) : null}
                                    </td>
                                    <td className="tabular px-3 py-2 text-right">
                                        {row.close
                                            ? `${formatMinutes(row.close.worked_minutes)} / ${formatMinutes(row.close.expected_minutes)}`
                                            : '—'}
                                    </td>
                                    <td className="tabular px-3 py-2 text-right">
                                        {row.close
                                            ? formatDifference(
                                                  row.close.difference_minutes,
                                              )
                                            : ''}
                                    </td>
                                    <td className="tabular px-3 py-2 text-right">
                                        {row.close
                                            ? formatMinutes(
                                                  row.close.overtime_minutes,
                                              )
                                            : ''}
                                    </td>
                                    <td className="max-w-80 px-3 py-2 text-xs break-words">
                                        <RowDetail row={row} />
                                    </td>
                                    <td className="px-3 py-2">
                                        <RowActions
                                            row={row}
                                            month={props.month}
                                            onReopen={() => setReopening(row)}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <ul className="grid gap-2 md:hidden" data-test="closes-cards">
                    {props.rows.map((row) => (
                        <li
                            key={row.user.id}
                            className="grid gap-2 rounded-md border p-3 text-sm"
                            data-state={row.state}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <span>{row.user.name}</span>
                                <CloseStatusBadge status={row.state} />
                            </div>
                            {row.close ? (
                                <p className="tabular text-xs text-muted-foreground">
                                    {t('people.register.close_figures', {
                                        worked: formatMinutes(
                                            row.close.worked_minutes,
                                        ),
                                        expected: formatMinutes(
                                            row.close.expected_minutes,
                                        ),
                                        difference: formatDifference(
                                            row.close.difference_minutes,
                                        ),
                                    })}
                                </p>
                            ) : null}
                            <div className="text-xs">
                                <RowDetail row={row} />
                            </div>
                            <RowActions
                                row={row}
                                month={props.month}
                                onReopen={() => setReopening(row)}
                            />
                        </li>
                    ))}
                </ul>
            </PeopleFrame>

            <ReopenDialog row={reopening} onClose={() => setReopening(null)} />
        </>
    );
}

function RowDetail({ row }: { row: CloseRow }) {
    const close = row.close;

    if (close === null) {
        return (
            <span className="text-muted-foreground">
                {t('people.closes.not_generated')}
            </span>
        );
    }

    switch (row.state) {
        case 'confirmed':
            return (
                <span>
                    {t('people.closes.confirmed_on', {
                        date: formatDate(close.confirmed_at),
                    })}
                </span>
            );
        case 'disagreed':
            return (
                <span>
                    <span className="text-muted-foreground">
                        {t('people.closes.disagreement')}{' '}
                    </span>
                    {close.disagreement_note}
                </span>
            );
        case 'reopened':
            return (
                <span>
                    {t('people.register.reopened_note', {
                        name: close.reopened_by ?? '',
                        date: formatDate(close.reopened_at),
                        reason: close.reopen_reason ?? '',
                    })}
                </span>
            );
        default:
            return (
                <HashText
                    hash={close.pdf_sha256}
                    label={t('people.closes.pdf_hash')}
                />
            );
    }
}

function RowActions({
    row,
    month,
    onReopen,
}: {
    row: CloseRow;
    month: string;
    onReopen: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    return (
        <div className="flex flex-wrap gap-1.5">
            {row.can.generate ? (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={() =>
                        router.post(
                            generate.url(),
                            { month, user_ids: [row.user.id] },
                            options,
                        )
                    }
                    data-test="close-generate"
                >
                    <FileCheck2 aria-hidden="true" />
                    {row.close === null
                        ? t('people.closes.generate')
                        : t('people.closes.regenerate')}
                </Button>
            ) : null}
            {row.can.reopen ? (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={onReopen}
                    data-test="close-reopen"
                >
                    <RotateCcw aria-hidden="true" />
                    {t('people.closes.reopen')}
                </Button>
            ) : null}
            {row.can.remind && row.close ? (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={processing}
                    onClick={() =>
                        router.post(remind.url(row.close?.id ?? 0), {}, options)
                    }
                    data-test="close-remind"
                >
                    <BellRing aria-hidden="true" />
                    {t('people.closes.remind')}
                </Button>
            ) : null}
            {row.close ? (
                <Button asChild size="sm" variant="ghost">
                    <a
                        href={pdf.url(row.close.id)}
                        aria-label={t('people.closes.pdf_of', {
                            name: row.user.name,
                        })}
                    >
                        <FileDown aria-hidden="true" />
                        {t('people.register.pdf')}
                    </a>
                </Button>
            ) : null}
        </div>
    );
}

function ReopenDialog({
    row,
    onClose,
}: {
    row: CloseRow | null;
    onClose: () => void;
}) {
    const id = useId();
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog
            open={row !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
                setReason('');
                setError(undefined);
            }}
        >
            <DialogContent>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (!row?.close) {
                            return;
                        }
                        router.post(
                            reopen.url(row.close.id),
                            { reason },
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onError: (errors) =>
                                    setError(errors.reason ?? errors.close),
                                onSuccess: () => onClose(),
                            },
                        );
                    }}
                >
                    <DialogTitle>
                        {t('people.closes.reopen_title', {
                            name: row?.user.name ?? '',
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('people.closes.reopen_description')}
                    </DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-reason`}>
                            {t('people.closes.reopen_reason')}
                        </Label>
                        <Textarea
                            id={`${id}-reason`}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            maxLength={500}
                            required
                            aria-invalid={error ? true : undefined}
                            data-test="close-reopen-reason"
                        />
                        <InputError message={error} />
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={processing || reason.trim().length < 5}
                            data-test="close-reopen-submit"
                        >
                            {processing ? <Spinner /> : null}
                            {t('people.closes.reopen_submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
