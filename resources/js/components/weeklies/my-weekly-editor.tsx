import { Link, router } from '@inertiajs/react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import {
    ArrowLeft,
    CalendarOff,
    ChevronsDownUp,
    ChevronsUpDown,
    CircleAlert,
    CircleCheck,
    CloudOff,
    Info,
    ListChecks,
    Loader2,
    Lock,
    Plus,
    Send,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { weekdayLongLabel } from '@/components/time/week-days';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { PushPrompt } from '@/components/weeklies/push-prompt';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { EntryValue } from '@/components/weeklies/client-entry-box';
import { ClientEntryBox } from '@/components/weeklies/client-entry-box';
import { useWeeklyAutosave } from '@/components/weeklies/use-weekly-autosave';
import {
    ClientIcon,
    PersonStatusBadge,
    WeekLabel,
} from '@/components/weeklies/weekly-ui';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as mySpaceIndex } from '@/routes/my-space';
import { submit as submitRoute } from '@/routes/my-weekly';
import {
    destroy as destroyExemption,
    waive as waiveRoute,
} from '@/routes/weeklies/exemptions';
import type {
    MyWeeklyEditor as Editor,
    WeeklyClientOption,
    WeeklyDraftInput,
} from '@/types/weeklies';

/** Clave de una caja: el id del cliente o «general» (General / Interno). */
const GENERAL = 'general';

type Key = string;

const EMPTY: EntryValue = { body: '', project_id: null, source: 'text' };

/** Consejos de «cómo reportar» (F-047), los de WeeklySync. */
const TIPS = [
    'weeklies.guide.tip_what',
    'weeklies.guide.tip_progress',
    'weeklies.guide.tip_blockers',
    'weeklies.guide.tip_next',
    'weeklies.guide.tip_meetings',
    'weeklies.guide.tip_generic',
    'weeklies.guide.tip_facts',
] as const;

function keyOf(clientId: number | null): Key {
    return clientId === null ? GENERAL : String(clientId);
}

function clientIdOf(key: Key): number | null {
    return key === GENERAL ? null : Number(key);
}

/** Cajas de partida: los clientes propuestos, los que ya tienen apunte y, al final, General. */
function initialState(editor: Editor): {
    order: Key[];
    values: Record<Key, EntryValue>;
} {
    const values: Record<Key, EntryValue> = {};
    const order: Key[] = editor.read_only
        ? []
        : editor.clients.proposed.map((id) => String(id));

    for (const entry of editor.submission?.entries ?? []) {
        const key = keyOf(entry.client_id);
        values[key] = {
            body: entry.body,
            project_id: entry.project_id,
            source: entry.source,
        };

        if (key !== GENERAL && !order.includes(key)) {
            order.push(key);
        }
    }

    if (!editor.read_only || values[GENERAL]) {
        order.push(GENERAL);
    }

    return { order, values };
}

/** Lo que se guarda: un apunte por caja con texto. */
export function draftOf(
    order: Key[],
    values: Record<Key, EntryValue>,
): WeeklyDraftInput {
    return {
        entries: order
            .filter((key) => (values[key]?.body ?? '').trim() !== '')
            .map((key) => ({
                client_id: clientIdOf(key),
                project_id: values[key].project_id,
                body: values[key].body,
                source: values[key].source,
            })),
    };
}

/**
 * «Mi weekly» de una semana (F-043 a F-054; WeeklyReportingInterface de WeeklySync): una caja por
 * cliente propuesto (D-157) más «General / Interno», añadir otros clientes con buscador, plegar y
 * desplegar, la guía de cómo reportar, autocompletar desde mis tareas y horas, el dictado, el
 * borrador autoguardado con su estado y enviar o actualizar. Exento, se ve el aviso con «Quitar mi
 * exención»; con la semana cerrada, solo lectura.
 */
export function MyWeeklyEditor({ editor }: { editor: Editor }) {
    const { cycle, me, submission, can } = editor;
    const readOnly = editor.read_only || !can.write;
    const initial = useMemo(() => initialState(editor), [editor]);
    const [order, setOrder] = useState<Key[]>(initial.order);
    const [values, setValues] = useState<Record<Key, EntryValue>>(
        initial.values,
    );
    const [added, setAdded] = useState<Set<Key>>(() => new Set());
    const [expanded, setExpanded] = useState<Set<Key>>(() => {
        const withText = initial.order.filter(
            (key) => (initial.values[key]?.body ?? '').trim() !== '',
        );

        if (submission?.is_submitted || readOnly) {
            return new Set(readOnly ? withText : []);
        }

        return new Set(initial.order.slice(0, 1));
    });
    const [busy, setBusy] = useState<Set<Key>>(() => new Set());
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [exemptionBusy, setExemptionBusy] = useState(false);
    const [picking, setPicking] = useState(false);

    const catalog = useMemo(
        () =>
            new Map<number, WeeklyClientOption>(
                editor.clients.catalog.map((client) => [client.id, client]),
            ),
        [editor.clients.catalog],
    );
    const entryClients = useMemo(
        () =>
            new Map(
                (submission?.entries ?? [])
                    .filter((entry) => entry.client)
                    .map((entry) => [entry.client_id, entry.client!]),
            ),
        [submission],
    );

    const draft = useMemo(() => draftOf(order, values), [order, values]);
    const autosave = useWeeklyAutosave({
        cycleId: cycle.id,
        draft,
        enabled: !readOnly,
        initialSavedAt: submission?.draft_saved_at ?? null,
    });

    const infoOf = (key: Key) => {
        if (key === GENERAL) {
            return {
                name: t('weeklies.entry.general'),
                icon: null,
                projects: [],
            };
        }

        const id = Number(key);
        const option = catalog.get(id);
        const fallback = entryClients.get(id);

        return {
            name: option?.name ?? fallback?.name ?? t('weeklies.editor.client'),
            icon: option?.icon ?? fallback?.icon ?? null,
            projects: option?.projects ?? [],
        };
    };

    const addKey = (key: Key) => {
        setOrder((current) =>
            current.includes(key)
                ? current
                : [...current.filter((item) => item !== GENERAL), key, GENERAL],
        );
        setExpanded((current) => new Set(current).add(key));
    };

    const update = (key: Key, value: EntryValue) =>
        setValues((current) => ({ ...current, [key]: value }));

    const toggle = (key: Key) =>
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });

    const allExpanded =
        order.length > 0 && order.every((key) => expanded.has(key));

    const autofillKeys = Object.keys(editor.autofill).filter(
        (key) => key === GENERAL || catalog.has(Number(key)),
    );

    const autofill = () => {
        for (const key of autofillKeys) {
            addKey(key);
        }

        setValues((current) => {
            const next = { ...current };

            for (const key of autofillKeys) {
                const text = editor.autofill[key].trim();
                const existing = next[key]?.body ?? '';

                if (text !== '' && !existing.includes(text)) {
                    next[key] = {
                        ...(next[key] ?? EMPTY),
                        body:
                            existing.trim() === ''
                                ? text
                                : `${existing.trimEnd()}\n\n${text}`,
                    };
                }
            }

            return next;
        });
    };

    const available = editor.clients.catalog.filter(
        (client) => client.is_active && !order.includes(String(client.id)),
    );

    const submitted = submission?.is_submitted ?? false;
    const reportedCount = draft.entries.length;
    const dictating = busy.size > 0;

    const submit = () => {
        setSubmitError(null);
        // Si el envío falla, lo escrito no está guardado: se vuelve a autoguardar (D-310).
        const resume = autosave.cancel();
        let sent = false;
        router.post(submitRoute.url(cycle.id), draft, {
            preserveScroll: true,
            onStart: () => setSubmitting(true),
            onSuccess: () => {
                sent = true;
            },
            onFinish: () => {
                setSubmitting(false);

                if (!sent) {
                    resume();
                }
            },
            onError: (errors) =>
                setSubmitError(
                    errors.entries ??
                        Object.values(errors)[0] ??
                        t('weeklies.editor.submit_failed'),
                ),
        });
    };

    const waive = () =>
        router.post(
            waiveRoute.url(cycle.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setExemptionBusy(true),
                onFinish: () => setExemptionBusy(false),
                onError: toastVisitErrors,
            },
        );

    const undoWaiver = () => {
        if (me.exemption_id === null) {
            return;
        }

        router.delete(
            destroyExemption.url({
                cycle: cycle.id,
                exemption: me.exemption_id,
            }),
            {
                preserveScroll: true,
                onStart: () => setExemptionBusy(true),
                onFinish: () => setExemptionBusy(false),
                onError: toastVisitErrors,
            },
        );
    };

    const closed = cycle.status === 'closed';

    return (
        <div className="grid gap-6 pb-4" data-test="weekly-editor">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Link
                    href={mySpaceIndex.url()}
                    className="inline-flex items-center gap-1.5 text-sm text-primary-text hover:underline"
                >
                    <ArrowLeft aria-hidden="true" className="size-4" />
                    {t('weeklies.editor.back')}
                </Link>
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <WeekLabel
                        cycle={cycle}
                        className="text-muted-foreground"
                    />
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 px-1.5 py-0.5 text-xs',
                            closed ? 'bg-neutral-soft' : 'bg-success-soft',
                        )}
                    >
                        {closed ? (
                            <Lock aria-hidden="true" className="size-3" />
                        ) : null}
                        {t(`weeklies.cycle_status.${cycle.status}`)}
                    </span>
                </div>
            </div>

            <header className="flex flex-col gap-3 border bg-card p-4 md:flex-row md:items-start md:justify-between">
                <div className="grid gap-1">
                    <div className="flex items-center gap-2">
                        <h2 className="text-xl font-normal tracking-tight">
                            {t('weeklies.editor.title')}
                        </h2>
                        <Guide />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {closed
                            ? t('weeklies.editor.read_only')
                            : me.exemption_reason
                              ? t('weeklies.editor.exempt_locked')
                              : !me.participates
                                ? t('weeklies.editor.not_participant')
                                : t('weeklies.editor.intro')}
                    </p>
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <PersonStatusBadge status={me.status} />
                        <DeadlineNotice editor={editor} />
                    </div>
                </div>
                {!readOnly ? (
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={() =>
                                setExpanded(
                                    allExpanded ? new Set() : new Set(order),
                                )
                            }
                            data-test="weekly-toggle-all"
                        >
                            {allExpanded ? (
                                <ChevronsDownUp aria-hidden="true" />
                            ) : (
                                <ChevronsUpDown aria-hidden="true" />
                            )}
                            {allExpanded
                                ? t('weeklies.editor.collapse_all')
                                : t('weeklies.editor.expand_all')}
                        </Button>
                        {autofillKeys.length > 0 ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={autofill}
                                title={t('weeklies.editor.autofill_hint')}
                                data-test="weekly-autofill"
                            >
                                <ListChecks aria-hidden="true" />
                                {t('weeklies.editor.autofill')}
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </header>

            {me.exemption_reason ? (
                <div
                    className="flex flex-col gap-3 border border-warning bg-warning-soft p-4 md:flex-row md:items-center md:justify-between"
                    data-test="weekly-exempt-notice"
                >
                    <div className="flex gap-2">
                        <CalendarOff
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-warning"
                        />
                        <div className="grid gap-1 text-sm">
                            <p className="font-medium">
                                {t(
                                    `weeklies.exempt.title.${me.exemption_reason}`,
                                )}
                            </p>
                            {me.exemption_until ? (
                                <p data-test="weekly-exempt-until">
                                    {t('weeklies.exempt.until', {
                                        date: formatDate(me.exemption_until),
                                    })}
                                </p>
                            ) : null}
                            <p>{t('weeklies.exempt.locked')}</p>
                            <p>{t('weeklies.exempt.streak')}</p>
                        </div>
                    </div>
                    {can.waive ? (
                        <Button
                            type="button"
                            onClick={waive}
                            disabled={exemptionBusy}
                            className="shrink-0"
                            data-test="weekly-waive"
                        >
                            {exemptionBusy ? (
                                <Loader2
                                    aria-hidden="true"
                                    className="animate-spin"
                                />
                            ) : (
                                <CircleCheck aria-hidden="true" />
                            )}
                            {t('weeklies.exempt.waive')}
                        </Button>
                    ) : null}
                </div>
            ) : me.waived && can.undo_waiver ? (
                <div className="flex flex-wrap items-center justify-between gap-2 border bg-muted p-3 text-sm">
                    <p>{t('weeklies.exempt.waived')}</p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={undoWaiver}
                        disabled={exemptionBusy}
                    >
                        {t('weeklies.exempt.undo_waiver')}
                    </Button>
                </div>
            ) : null}

            {order.length === 0 ? (
                <p
                    className="border border-dashed bg-muted/60 p-4 text-sm text-muted-foreground"
                    data-test="weekly-editor-empty"
                >
                    {me.exemption_reason
                        ? t('weeklies.editor.nothing_exempt')
                        : t('weeklies.editor.nothing_sent')}
                </p>
            ) : (
                <div className="grid gap-3" data-test="weekly-entries">
                    {order.map((key) => {
                        const info = infoOf(key);

                        return (
                            <ClientEntryBox
                                key={key}
                                cycleId={cycle.id}
                                clientId={clientIdOf(key)}
                                name={info.name}
                                icon={info.icon}
                                projects={info.projects}
                                value={values[key] ?? EMPTY}
                                expanded={expanded.has(key)}
                                readOnly={readOnly}
                                removable={added.has(key)}
                                onToggle={() => toggle(key)}
                                onChange={(value) => update(key, value)}
                                onRemove={() => {
                                    setOrder((current) =>
                                        current.filter((item) => item !== key),
                                    );
                                    setAdded((current) => {
                                        const next = new Set(current);
                                        next.delete(key);

                                        return next;
                                    });
                                }}
                                dictationLocked={dictating && !busy.has(key)}
                                onBusyChange={(value) =>
                                    setBusy((current) => {
                                        if (value === current.has(key)) {
                                            return current;
                                        }

                                        const next = new Set(current);

                                        if (value) {
                                            next.add(key);
                                        } else {
                                            next.delete(key);
                                        }

                                        return next;
                                    })
                                }
                            />
                        );
                    })}
                </div>
            )}

            {!readOnly ? (
                <Popover open={picking} onOpenChange={setPicking}>
                    <PopoverTrigger asChild>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full border-dashed"
                            data-test="weekly-add-client"
                        >
                            <Plus aria-hidden="true" />
                            {t('weeklies.editor.add_client')}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent
                        className="w-(--radix-popover-trigger-width) min-w-64 p-0"
                        align="start"
                    >
                        <Command>
                            <CommandInput
                                placeholder={t('weeklies.editor.search_client')}
                                aria-label={t('weeklies.editor.search_client')}
                            />
                            <CommandList>
                                <CommandEmpty>
                                    {t('weeklies.editor.no_client_found')}
                                </CommandEmpty>
                                <CommandGroup>
                                    {available.map((client) => (
                                        <CommandItem
                                            key={client.id}
                                            value={`${client.name} ${client.id}`}
                                            onSelect={() => {
                                                const key = String(client.id);
                                                addKey(key);
                                                setAdded((current) =>
                                                    new Set(current).add(key),
                                                );
                                                setPicking(false);
                                            }}
                                        >
                                            <ClientIcon
                                                icon={client.icon}
                                                className="size-6"
                                            />
                                            {client.name}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            </CommandList>
                        </Command>
                    </PopoverContent>
                </Popover>
            ) : null}

            {!readOnly ? (
                <div
                    className="sticky bottom-0 z-10 -mx-4 grid gap-2 border-t bg-background px-4 py-3 md:mx-0 md:border md:px-4"
                    data-test="weekly-footer"
                >
                    {submitError ? (
                        <p
                            role="alert"
                            className="flex items-start gap-1.5 text-sm"
                        >
                            <CircleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-danger"
                            />
                            {submitError}
                        </p>
                    ) : null}
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="grid gap-0.5 text-xs text-muted-foreground">
                            <span>
                                {reportedCount === 1
                                    ? t('weeklies.editor.count_one')
                                    : t('weeklies.editor.count_other', {
                                          count: reportedCount,
                                      })}
                            </span>
                            <AutosaveStatus
                                status={autosave.status}
                                savedAt={autosave.savedAt}
                                error={autosave.error}
                                onRetry={() => void autosave.flush()}
                            />
                        </div>
                        <div className="flex flex-col items-end gap-1">
                            {reportedCount === 0 ? (
                                <ConfirmDialog
                                    trigger={
                                        <Button
                                            type="button"
                                            disabled={submitting || dictating}
                                            data-test="weekly-submit"
                                        >
                                            {submitting ? (
                                                <Loader2
                                                    aria-hidden="true"
                                                    className="animate-spin"
                                                />
                                            ) : (
                                                <Send aria-hidden="true" />
                                            )}
                                            {submitted
                                                ? t('weeklies.editor.update')
                                                : t('weeklies.editor.submit')}
                                        </Button>
                                    }
                                    title={t(
                                        'weeklies.editor.empty_submit_title',
                                    )}
                                    description={t(
                                        'weeklies.editor.empty_submit_description',
                                    )}
                                    confirmLabel={
                                        submitted
                                            ? t('weeklies.editor.update')
                                            : t('weeklies.editor.submit')
                                    }
                                    destructive={false}
                                    processing={submitting}
                                    onConfirm={submit}
                                />
                            ) : (
                                <Button
                                    type="button"
                                    onClick={submit}
                                    disabled={submitting || dictating}
                                    data-test="weekly-submit"
                                >
                                    {submitting ? (
                                        <Loader2
                                            aria-hidden="true"
                                            className="animate-spin"
                                        />
                                    ) : (
                                        <Send aria-hidden="true" />
                                    )}
                                    {submitted
                                        ? t('weeklies.editor.update')
                                        : t('weeklies.editor.submit')}
                                </Button>
                            )}
                            {dictating ? (
                                <span className="text-xs text-muted-foreground">
                                    {t('weeklies.editor.wait_dictation')}
                                </span>
                            ) : null}
                        </div>
                    </div>
                </div>
            ) : null}

            {submitted ? <PushPrompt /> : null}

            {submitted && submission?.submitted_at ? (
                <p className="text-xs text-muted-foreground">
                    {t('weeklies.editor.submitted_at', {
                        date: formatDateTime(submission.submitted_at),
                    })}
                    {submission.resubmitted_at
                        ? ` · ${t('weeklies.editor.resubmitted_at', {
                              date: formatDateTime(submission.resubmitted_at),
                          })}`
                        : ''}
                </p>
            ) : null}
        </div>
    );
}

/** Estado del autoguardado (F-051), anunciado sin interrumpir. */
export function AutosaveStatus({
    status,
    savedAt,
    error,
    onRetry,
}: {
    status: string;
    savedAt: string | null;
    error: string | null;
    onRetry: () => void;
}) {
    return (
        <span
            role="status"
            aria-live="polite"
            className="inline-flex flex-wrap items-center gap-1.5"
            data-test="weekly-autosave"
            data-status={status}
        >
            {status === 'pending' || status === 'saving' ? (
                <>
                    <Loader2
                        aria-hidden="true"
                        className="size-3 animate-spin"
                    />
                    {t('weeklies.autosave.saving')}
                </>
            ) : status === 'error' ? (
                <>
                    <CloudOff
                        aria-hidden="true"
                        className="size-3.5 text-danger"
                    />
                    <span className="text-foreground">
                        {error ?? t('weeklies.autosave.error')}
                    </span>
                    <button
                        type="button"
                        className="text-primary-text underline"
                        onClick={onRetry}
                    >
                        {t('weeklies.autosave.retry')}
                    </button>
                </>
            ) : savedAt ? (
                t('weeklies.autosave.saved', {
                    date: formatDateTime(savedAt),
                })
            ) : (
                t('weeklies.autosave.idle')
            )}
        </span>
    );
}

/** Aviso de plazo (F-030): próximamente, pendiente o fuera de plazo. */
function DeadlineNotice({ editor }: { editor: Editor }) {
    const { cycle, me } = editor;

    if (cycle.status === 'closed' || !me.must_submit) {
        return null;
    }

    const date = `${weekdayLongLabel(cycle.deadline_date)} ${formatDate(cycle.deadline_date)}`;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 text-xs',
                me.status === 'overdue'
                    ? 'text-foreground'
                    : 'text-muted-foreground',
            )}
            data-test="weekly-deadline"
        >
            {me.status === 'overdue' ? (
                <CircleAlert
                    aria-hidden="true"
                    className="size-3.5 text-danger"
                />
            ) : null}
            {me.status === 'overdue'
                ? t('weeklies.editor.deadline_overdue', { date })
                : t('weeklies.editor.deadline', { date })}
        </span>
    );
}

/** Guía «cómo reportar» (F-047). */
function Guide() {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    aria-label={t('weeklies.guide.open')}
                    title={t('weeklies.guide.open')}
                >
                    <Info aria-hidden="true" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-80 max-w-[calc(100vw-2rem)]"
            >
                <p className="mb-2 text-sm font-medium">
                    {t('weeklies.guide.title')}
                </p>
                <ul className="grid list-disc gap-1 pl-4 text-xs text-muted-foreground">
                    {TIPS.map((tip) => (
                        <li key={tip}>{t(tip)}</li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}
