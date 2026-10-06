import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useMemo, useRef, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { DatePicker } from '@/components/domain/date-picker';
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
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ClientIcon } from '@/components/weeklies/weekly-ui';
import { addDays, todayInMadrid } from '@/lib/week';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { update as updateDeadline } from '@/routes/weeklies/deadline';
import { index as teamAbsences } from '@/routes/absences/team';
import { update as updateAway } from '@/routes/weeklies/away';
import { store as storeExemption } from '@/routes/weeklies/exemptions';
import { join as joinClients } from '@/routes/weeklies/clients';
import type { UserSummary } from '@/types';
import type {
    WeeklyAwayReason,
    WeeklyCycleSummary,
    WeeklyJoinableClient,
} from '@/types/weeklies';

/** Días que se puede alargar el plazo tras el viernes (UpdateWeeklyDeadlineRequest). */
export const MAX_DEADLINE_DAYS_AFTER_END = 28;

/**
 * «Configurar día límite» (F-068): solo con la semana activa. Del lunes de la semana a cuatro
 * semanas después del viernes; por defecto, el viernes.
 */
export function DeadlineDialog({
    cycle,
    trigger,
}: {
    cycle: WeeklyCycleSummary;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<{ deadline_date: string | null }>({
        deadline_date: cycle.deadline_date,
    });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.setData({ deadline_date: cycle.deadline_date });
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-5"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(updateDeadline.url(cycle.id), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t('weeklies.deadline.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('weeklies.deadline.description', {
                                label: cycle.label,
                                friday: formatDate(cycle.end_date),
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id={`${id}-date`}
                        label={t('weeklies.deadline.field')}
                        error={form.errors.deadline_date}
                    >
                        <DatePicker
                            id={`${id}-date`}
                            value={form.data.deadline_date}
                            onChange={(value) =>
                                form.setData('deadline_date', value)
                            }
                            clearable={false}
                            min={cycle.start_date}
                            max={addDays(
                                cycle.end_date,
                                MAX_DEADLINE_DAYS_AFTER_END,
                            )}
                            invalid={Boolean(form.errors.deadline_date)}
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
                        <Button
                            type="submit"
                            disabled={
                                form.processing || !form.data.deadline_date
                            }
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

/**
 * Eximir a alguien de la weekly (F-038, D-159 y D-228), como «Marcar ausencia» de WeeklySync:
 * - «Solo esta semana»: exención manual con una nota opcional,
 * - «De vacaciones» o «Ausente o de baja»: le marca fuera (WeeklyAway) hasta una fecha opcional, así
 *   que queda exento de esta y de las siguientes semanas cuyo plazo caiga antes de su vuelta, y sin
 *   recordatorios. Enlaza a «Ausencias del equipo» para registrar la ausencia de verdad.
 * Quien tiene una ausencia aprobada que cubre el plazo ya está exento solo (F-097).
 */
export function ExemptDialog({
    cycle,
    person,
    trigger,
}: {
    cycle: WeeklyCycleSummary;
    person: UserSummary;
    trigger: ReactNode;
}) {
    const id = useId();
    const can = usePage().props.auth?.can;
    const [open, setOpen] = useState(false);
    const form = useForm<{
        user_id: number;
        note: string;
        mode: 'week' | WeeklyAwayReason;
        until: string | null;
    }>({ user_id: person.id, note: '', mode: 'week', until: null });
    const week = form.data.mode === 'week';

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.setData({
                        user_id: person.id,
                        note: '',
                        mode: 'week',
                        until: null,
                    });
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-5"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (week) {
                            form.transform((data) => ({
                                user_id: data.user_id,
                                note: data.note,
                            }));
                            form.post(storeExemption.url(cycle.id), {
                                preserveScroll: true,
                                onSuccess: () => setOpen(false),
                            });

                            return;
                        }

                        form.transform((data) => ({
                            reason: data.mode,
                            until: data.until,
                        }));
                        form.put(updateAway.url(person.id), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t('weeklies.exempt_dialog.title', {
                                name: person.name,
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('weeklies.exempt_dialog.description', {
                                label: cycle.label,
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">
                            {t('weeklies.exempt_dialog.mode')}
                        </legend>
                        <RadioGroup
                            value={form.data.mode}
                            onValueChange={(value) =>
                                form.setData(
                                    'mode',
                                    value as 'week' | WeeklyAwayReason,
                                )
                            }
                            className="grid gap-2"
                        >
                            {(['week', 'vacation', 'absent'] as const).map(
                                (mode) => (
                                    <label
                                        key={mode}
                                        className="flex items-start gap-2 text-sm"
                                    >
                                        <RadioGroupItem
                                            value={mode}
                                            className="mt-0.5"
                                            data-test={`weekly-exempt-mode-${mode}`}
                                        />
                                        <span className="grid gap-0.5">
                                            <span>
                                                {t(
                                                    `weeklies.exempt_dialog.mode_${mode}`,
                                                )}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t(
                                                    mode === 'week'
                                                        ? 'weeklies.exempt_dialog.mode_week_help'
                                                        : 'weeklies.exempt_dialog.mode_away_help',
                                                )}
                                            </span>
                                        </span>
                                    </label>
                                ),
                            )}
                        </RadioGroup>
                        <InputError
                            message={
                                (form.errors as Record<string, string>).reason
                            }
                        />
                    </fieldset>
                    {week ? (
                        <Field
                            id={`${id}-note`}
                            label={t('weeklies.exempt_dialog.note')}
                            optional={t('weeklies.common.optional')}
                            error={form.errors.note ?? form.errors.user_id}
                        >
                            <Textarea
                                id={`${id}-note`}
                                value={form.data.note}
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                                maxLength={500}
                                placeholder={t(
                                    'weeklies.exempt_dialog.note_placeholder',
                                )}
                                aria-invalid={
                                    form.errors.note || form.errors.user_id
                                        ? true
                                        : undefined
                                }
                                aria-describedby={describedBy(`${id}-note`, {
                                    error:
                                        form.errors.note ?? form.errors.user_id,
                                })}
                            />
                        </Field>
                    ) : (
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
                                    form.setData('until', value)
                                }
                                min={todayInMadrid()}
                                invalid={Boolean(form.errors.until)}
                            />
                        </Field>
                    )}
                    {can?.viewTeamAbsences ? (
                        <Link
                            href={teamAbsences.url()}
                            className="text-xs underline underline-offset-2"
                            data-test="weekly-exempt-team-absences"
                        >
                            {t('weeklies.away.team_absences_link')}
                        </Link>
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
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="weekly-exempt-confirm"
                        >
                            {form.processing && <Spinner />}
                            {week
                                ? t('weeklies.exempt_dialog.confirm')
                                : t('weeklies.exempt_dialog.confirm_away')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * «Unirme a clientes» (F-034, D-221): varios a la vez. Es una suscripción de la Weekly: el cliente
 * sale propuesto en «Mi weekly», en «Mis clientes» y en su equipo, sin acceso a sus proyectos. La
 * lista (prop opcional `joinable_clients`) se pide al abrir el diálogo.
 */
export function JoinClientsDialog({
    clients,
    trigger,
    propName = 'joinable_clients',
    submitUrl,
    title,
    description,
    assign = false,
}: {
    clients: WeeklyJoinableClient[] | undefined;
    trigger: ReactNode;
    /** «Asignar» a otra persona desde su ficha (D-233) en vez de «Unirme». */
    assign?: boolean;
    /** La prop opcional con la lista (para «Asignar clientes» de la ficha, D-233). */
    propName?: string;
    submitUrl?: string;
    title?: string;
    description?: string;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // La lista recibida se guarda aquí: una recarga en vivo de la página (useWeeklyLive) no trae
    // la prop opcional y la borraba con el diálogo abierto («No hay clientes…»). D-310.
    const [loaded, setLoaded] = useState<WeeklyJoinableClient[] | undefined>(
        undefined,
    );
    const [loadFailed, setLoadFailed] = useState(false);
    const request = useRef(0);
    const list = loaded ?? clients;

    const load = () => {
        const mine = ++request.current;
        let ok = false;
        setLoading(true);
        setLoadFailed(false);
        router.reload({
            only: [propName],
            onSuccess: (page) => {
                const value = (page.props as Record<string, unknown>)[propName];

                if (mine === request.current && Array.isArray(value)) {
                    ok = true;
                    setLoaded(value as WeeklyJoinableClient[]);
                }
            },
            onFinish: () => {
                if (mine === request.current) {
                    setLoading(false);
                    setLoadFailed(!ok);
                }
            },
        });
    };

    const shown = useMemo(
        () => filterJoinableClients(list ?? [], query),
        [list, query],
    );

    const toggle = (clientId: number, checked: boolean) =>
        setSelected((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(clientId);
            } else {
                next.delete(clientId);
            }

            return next;
        });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setQuery('');
                    setSelected(new Set());
                    setError(null);
                    load();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <form
                    className="grid min-w-0 gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post(
                            submitUrl ?? joinClients.url(),
                            { client_ids: [...selected] },
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onSuccess: () => setOpen(false),
                                onError: (errors) =>
                                    setError(
                                        Object.values(errors)[0] ??
                                            t('weeklies.join.failed'),
                                    ),
                            },
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {title ?? t('weeklies.join.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {description ?? t('weeklies.join.description')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="relative">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('weeklies.join.search')}
                            aria-label={t('weeklies.join.search')}
                            className="pl-8"
                        />
                    </div>
                    <div
                        className="max-h-80 min-w-0 overflow-y-auto border"
                        aria-busy={loading}
                    >
                        {loading && list === undefined ? (
                            <p className="flex items-center gap-2 p-3 text-sm text-muted-foreground">
                                <Spinner />
                                {t('weeklies.join.loading')}
                            </p>
                        ) : loadFailed && list === undefined ? (
                            <div className="grid justify-items-start gap-2 p-3">
                                <p role="alert" className="text-sm text-danger">
                                    {t('weeklies.join.load_failed')}
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={load}
                                >
                                    {t('weeklies.join.retry')}
                                </Button>
                            </div>
                        ) : shown.length === 0 ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                {(list ?? []).length > 0 && query.trim() !== ''
                                    ? t('weeklies.join.no_match')
                                    : t('weeklies.join.empty')}
                            </p>
                        ) : (
                            <ul className="grid">
                                {shown.map((client) => {
                                    const checkbox = `${id}-${client.id}`;

                                    return (
                                        <li
                                            key={client.id}
                                            className="flex items-center gap-2 border-b p-3 last:border-b-0"
                                        >
                                            <Checkbox
                                                id={checkbox}
                                                checked={selected.has(
                                                    client.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggle(
                                                        client.id,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <ClientIcon
                                                icon={client.icon}
                                                className="size-6"
                                            />
                                            <label
                                                htmlFor={checkbox}
                                                className="grid min-w-0 text-sm"
                                            >
                                                <span className="truncate">
                                                    {client.name}
                                                </span>
                                                {client.projects.length > 0 ? (
                                                    <span className="truncate text-xs text-muted-foreground">
                                                        {client.projects
                                                            .map(
                                                                (project) =>
                                                                    project.code,
                                                            )
                                                            .join(' · ')}
                                                    </span>
                                                ) : null}
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                    {error ? (
                        <p role="alert" className="text-sm text-foreground">
                            {error}
                        </p>
                    ) : null}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={processing || selected.size === 0}
                            data-test="join-clients-confirm"
                        >
                            {processing && <Spinner />}
                            {selected.size > 0
                                ? t(
                                      assign
                                          ? 'weeklies.person.assign_confirm_count'
                                          : 'weeklies.join.confirm_count',
                                      { count: selected.size },
                                  )
                                : t(
                                      assign
                                          ? 'weeklies.person.assign_confirm'
                                          : 'weeklies.join.confirm',
                                  )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Busca por el nombre del cliente o por el código o el nombre de sus proyectos abiertos. */
export function filterJoinableClients(
    clients: WeeklyJoinableClient[],
    query: string,
): WeeklyJoinableClient[] {
    const needle = query.trim().toLocaleLowerCase('es');

    return clients
        .filter(
            (client) =>
                needle === '' ||
                [
                    client.name,
                    ...client.projects.flatMap((project) => [
                        project.code,
                        project.name,
                    ]),
                ]
                    .join(' ')
                    .toLocaleLowerCase('es')
                    .includes(needle),
        )
        .sort((a, b) => a.name.localeCompare(b.name, 'es'));
}
