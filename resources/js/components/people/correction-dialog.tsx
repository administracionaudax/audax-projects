import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
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
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    countChanges,
    kindLabel,
    modeLabel,
    newRow,
    nextKind,
    rowsFromEvents,
    rowsPayload,
    tCount,
} from '@/lib/people';
import { store } from '@/routes/people/corrections';
import type {
    ClockKind,
    CorrectionRow,
    DayEvent,
    WorkMode,
} from '@/types/people';

const KINDS: ClockKind[] = [
    'clock_in',
    'pause_start',
    'pause_end',
    'clock_out',
];
const MODES: WorkMode[] = ['on_site', 'remote'];

/**
 * «Proponer una corrección» de un día (D-335; W-018): los fichajes del día como deberían quedar.
 * Cambiar o quitar uno lo anula (no se borra) y lo nuevo se añade al aceptarse. Motivo obligatorio.
 * Si la propone la persona, la valida su responsable; si la propone su responsable o RR. HH., la
 * tiene que aceptar la persona.
 */
export function CorrectionDialog({
    subject,
    date,
    events,
    open,
    onOpenChange,
}: {
    subject: { id: number; name: string; is_me: boolean };
    date: string;
    events: DayEvent[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const id = useId();
    const original = useMemo(
        () => rowsFromEvents(events, date),
        [events, date],
    );
    const [rows, setRows] = useState<CorrectionRow[]>(original);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const changes = countChanges(original, rows);
    const lastMode =
        [...rows].reverse().find((row) => row.work_mode)?.work_mode ?? null;

    const update = (key: string, patch: Partial<CorrectionRow>) =>
        setRows((current) =>
            current.map((row) => {
                if (row.key !== key) {
                    return row;
                }

                const next = { ...row, ...patch };

                if (next.kind === 'clock_in' || next.kind === 'pause_end') {
                    next.work_mode = next.work_mode ?? lastMode ?? 'on_site';
                } else {
                    next.work_mode = null;
                }

                if (next.kind === 'clock_in') {
                    next.next_day = false;
                }

                return next;
            }),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        router.post(
            store.url(),
            {
                user_id: subject.id,
                date,
                reason,
                rows: rowsPayload(rows),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (bag) => setErrors(bag),
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    const rowErrors = Object.entries(errors)
        .filter(([key]) => key === 'rows' || key.startsWith('rows.'))
        .map(([, message]) => message);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (next) {
                    setRows(rowsFromEvents(events, date));
                    setReason('');
                    setErrors({});
                }
            }}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {subject.is_me
                                ? t('people.correction.title')
                                : t('people.correction.title_for', {
                                      name: subject.name,
                                  })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('people.correction.description', {
                                date: formatDate(date),
                            })}{' '}
                            {subject.is_me
                                ? t('people.correction.who_accepts_self')
                                : t('people.correction.who_accepts_other', {
                                      name: subject.name,
                                  })}
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset className="grid gap-3">
                        <legend className="mb-2 text-sm font-medium">
                            {t('people.correction.rows')}
                        </legend>
                        {rows.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('people.correction.empty')}
                            </p>
                        ) : (
                            <ol className="grid gap-2">
                                {rows.map((row, index) => (
                                    <li
                                        key={row.key}
                                        className="relative grid grid-cols-2 items-end gap-2 rounded-md border p-2 sm:grid-cols-[minmax(0,1fr)_6.5rem_minmax(0,9rem)_auto_auto]"
                                        data-test="correction-row"
                                    >
                                        <div className="col-span-2 grid gap-1 pr-10 sm:col-span-1 sm:pr-0">
                                            <Label
                                                htmlFor={`${id}-kind-${row.key}`}
                                                className="text-xs"
                                            >
                                                {t('people.correction.kind')}
                                            </Label>
                                            <NativeSelect
                                                id={`${id}-kind-${row.key}`}
                                                value={row.kind}
                                                onChange={(event) =>
                                                    update(row.key, {
                                                        kind: event.target
                                                            .value as ClockKind,
                                                    })
                                                }
                                                data-test="correction-kind"
                                            >
                                                {KINDS.map((kind) => (
                                                    <option
                                                        key={kind}
                                                        value={kind}
                                                    >
                                                        {kindLabel(kind)}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </div>
                                        <div className="grid gap-1">
                                            <Label
                                                htmlFor={`${id}-time-${row.key}`}
                                                className="text-xs"
                                            >
                                                {t('people.correction.time')}
                                            </Label>
                                            <Input
                                                id={`${id}-time-${row.key}`}
                                                type="time"
                                                step={60}
                                                value={row.time}
                                                onChange={(event) =>
                                                    update(row.key, {
                                                        time: event.target
                                                            .value,
                                                    })
                                                }
                                                required
                                                aria-invalid={
                                                    errors[`rows.${index}.time`]
                                                        ? true
                                                        : undefined
                                                }
                                                className="tabular"
                                                data-test="correction-time"
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="absolute top-1 right-1 size-9 sm:static sm:order-last"
                                            onClick={() =>
                                                setRows((current) =>
                                                    current.filter(
                                                        (candidate) =>
                                                            candidate.key !==
                                                            row.key,
                                                    ),
                                                )
                                            }
                                            aria-label={t(
                                                'people.correction.remove',
                                                {
                                                    index: index + 1,
                                                },
                                            )}
                                            data-test="correction-remove"
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                        {row.kind === 'clock_in' ||
                                        row.kind === 'pause_end' ? (
                                            <div className="grid gap-1">
                                                <Label
                                                    htmlFor={`${id}-mode-${row.key}`}
                                                    className="text-xs"
                                                >
                                                    {t('people.modes.label')}
                                                </Label>
                                                <NativeSelect
                                                    id={`${id}-mode-${row.key}`}
                                                    value={
                                                        row.work_mode ??
                                                        'on_site'
                                                    }
                                                    onChange={(event) =>
                                                        update(row.key, {
                                                            work_mode: event
                                                                .target
                                                                .value as WorkMode,
                                                        })
                                                    }
                                                >
                                                    {MODES.map((mode) => (
                                                        <option
                                                            key={mode}
                                                            value={mode}
                                                        >
                                                            {modeLabel(mode)}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </div>
                                        ) : (
                                            <span className="hidden sm:block" />
                                        )}
                                        {row.kind === 'clock_in' ? (
                                            <span className="hidden sm:block" />
                                        ) : (
                                            <label className="flex h-9 items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={row.next_day}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        update(row.key, {
                                                            next_day:
                                                                checked ===
                                                                true,
                                                        })
                                                    }
                                                />
                                                {t(
                                                    'people.correction.next_day',
                                                )}
                                            </label>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setRows((current) =>
                                        current.length === 0
                                            ? [
                                                  newRow(
                                                      'clock_in',
                                                      '09:00',
                                                      lastMode,
                                                  ),
                                                  newRow('clock_out', '17:00'),
                                              ]
                                            : [
                                                  ...current,
                                                  newRow(
                                                      nextKind(current),
                                                      '',
                                                      lastMode,
                                                  ),
                                              ],
                                    )
                                }
                                data-test="correction-add"
                            >
                                <Plus aria-hidden="true" />
                                {rows.length === 0
                                    ? t('people.correction.add_day')
                                    : t('people.correction.add')}
                            </Button>
                            <span
                                className="self-center text-xs text-muted-foreground"
                                aria-live="polite"
                            >
                                {tCount('people.correction.changes', changes)}
                            </span>
                        </div>
                        {rowErrors.length > 0 ? (
                            <InputError message={rowErrors[0]} />
                        ) : null}
                    </fieldset>

                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-reason`}>
                            {t('people.correction.reason')}
                        </Label>
                        <Textarea
                            id={`${id}-reason`}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder={t(
                                'people.correction.reason_placeholder',
                            )}
                            maxLength={500}
                            required
                            aria-invalid={errors.reason ? true : undefined}
                            aria-describedby={`${id}-reason-hint`}
                            data-test="correction-reason"
                        />
                        <p
                            id={`${id}-reason-hint`}
                            className="text-xs text-muted-foreground"
                        >
                            {t('people.correction.reason_hint')}
                        </p>
                        <InputError message={errors.reason ?? errors.date} />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={
                                processing ||
                                changes === 0 ||
                                reason.trim().length < 5 ||
                                rows.some((row) => row.time === '')
                            }
                            data-test="correction-submit"
                        >
                            {processing ? <Spinner /> : null}
                            {t('people.correction.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
