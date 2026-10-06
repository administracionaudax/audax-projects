import { Head, router, usePage } from '@inertiajs/react';
import { Eye, Lock, LockOpen, TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import { TimeEntryStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatDateTime, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as timeIndex } from '@/routes/time';
import {
    destroy,
    index as locksIndex,
    preview as previewRoute,
    store,
} from '@/routes/time/locks';
import type { TimeLockData, TimeLocksPageProps } from '@/types';

type Scope = 'client' | 'project';

/**
 * Bloqueo de horas al facturar (SPEC §7, D-034; solo admin): por cliente o proyecto y un rango
 * de fechas, con vista previa de lo que se bloqueará y aviso de lo que queda fuera por no estar
 * aprobado; y el listado de bloqueos para deshacerlos.
 */
export default function TimeLocks({
    clients,
    projects,
    filters,
    preview,
    locks,
}: TimeLocksPageProps) {
    const id = useId();
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const [scope, setScope] = useState<Scope>(
        filters.project_id !== null ? 'project' : 'client',
    );
    const [clientId, setClientId] = useState<string>(
        filters.client_id !== null ? String(filters.client_id) : '',
    );
    const [projectId, setProjectId] = useState<string>(
        filters.project_id !== null ? String(filters.project_id) : '',
    );
    const [from, setFrom] = useState<string | null>(filters.date_from);
    const [to, setTo] = useState<string | null>(filters.date_to);
    const [reference, setReference] = useState(filters.reference ?? '');
    const [processing, setProcessing] = useState(false);
    const [confirm, setConfirm] = useState(false);

    const params = (): Record<string, string> => ({
        ...(scope === 'client'
            ? { client_id: clientId }
            : { project_id: projectId }),
        date_from: from ?? '',
        date_to: to ?? '',
        ...(reference.trim() !== '' ? { reference: reference.trim() } : {}),
    });

    // Lo que se previsualizó (lo que devuelve el servidor): es lo que se bloquea, aunque después se
    // toquen los campos. Si el formulario ya no coincide, hay que volver a ver la vista previa.
    const previewed: Record<string, string> = {
        ...(filters.project_id !== null
            ? { project_id: String(filters.project_id) }
            : { client_id: String(filters.client_id ?? '') }),
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    };
    const current = params();
    const stale =
        preview !== null &&
        (current.client_id !== previewed.client_id ||
            current.project_id !== previewed.project_id ||
            current.date_from !== previewed.date_from ||
            current.date_to !== previewed.date_to);

    const showPreview = (event: FormEvent) => {
        event.preventDefault();
        router.get(previewRoute.url(), params(), {
            preserveScroll: true,
            // Con un error de validación se vuelve a la página sin parámetros: se conserva lo escrito.
            preserveState: (page) =>
                Object.keys(page.props.errors ?? {}).length > 0,
        });
    };

    const lock = () => {
        router.post(
            store.url(),
            {
                ...previewed,
                ...(reference.trim() !== ''
                    ? { reference: reference.trim() }
                    : {}),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setConfirm(false);
                },
            },
        );
    };

    const field = (name: string) => `${id}-${name}`;
    const lockable = preview?.summary.lockable;
    const pendingCount = preview
        ? preview.summary.pending.draft.count +
          preview.summary.pending.submitted.count
        : 0;
    const pendingMinutes = preview
        ? preview.summary.pending.draft.minutes +
          preview.summary.pending.submitted.minutes
        : 0;

    return (
        <>
            <Head title={t('hours.locks.title')} />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('hours.locks.heading')}
                    description={t('hours.locks.description')}
                />

                <section
                    aria-labelledby={field('form-heading')}
                    className="grid max-w-3xl gap-4 rounded-md border p-4"
                >
                    <h2 id={field('form-heading')} className="text-lg">
                        {t('hours.locks.form_title')}
                    </h2>
                    <form
                        onSubmit={showPreview}
                        className="grid gap-4"
                        noValidate
                    >
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                {t('hours.locks.scope')}
                            </legend>
                            <RadioGroup
                                value={scope}
                                onValueChange={(value) =>
                                    setScope(value as Scope)
                                }
                                className="flex flex-wrap gap-4"
                            >
                                <label className="flex items-center gap-2 text-sm">
                                    <RadioGroupItem value="client" />
                                    {t('hours.locks.by_client')}
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <RadioGroupItem value="project" />
                                    {t('hours.locks.by_project')}
                                </label>
                            </RadioGroup>
                        </fieldset>

                        <div className="grid gap-2">
                            <Label htmlFor={field('target')}>
                                {scope === 'client'
                                    ? t('hours.locks.client')
                                    : t('hours.locks.project')}
                            </Label>
                            {scope === 'client' ? (
                                <Select
                                    value={clientId}
                                    onValueChange={setClientId}
                                >
                                    <SelectTrigger
                                        id={field('target')}
                                        className="w-full"
                                        aria-invalid={
                                            errors.client_id ? true : undefined
                                        }
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'hours.locks.choose_client',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {clients.map((client) => (
                                            <SelectItem
                                                key={client.id}
                                                value={String(client.id)}
                                            >
                                                {client.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <Select
                                    value={projectId}
                                    onValueChange={setProjectId}
                                >
                                    <SelectTrigger
                                        id={field('target')}
                                        className="w-full"
                                        aria-invalid={
                                            errors.project_id ? true : undefined
                                        }
                                    >
                                        <SelectValue
                                            placeholder={t(
                                                'hours.locks.choose_project',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {projects.map((project) => (
                                            <SelectItem
                                                key={project.id}
                                                value={String(project.id)}
                                            >
                                                {project.code} · {project.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            <InputError
                                message={errors.client_id ?? errors.project_id}
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor={field('from')}>
                                    {t('hours.locks.from')}
                                </Label>
                                <DatePicker
                                    id={field('from')}
                                    value={from}
                                    onChange={setFrom}
                                    invalid={Boolean(errors.date_from)}
                                />
                                <InputError message={errors.date_from} />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor={field('to')}>
                                    {t('hours.locks.to')}
                                </Label>
                                <DatePicker
                                    id={field('to')}
                                    value={to}
                                    onChange={setTo}
                                    invalid={Boolean(errors.date_to)}
                                />
                                <InputError message={errors.date_to} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor={field('reference')}>
                                {t('hours.locks.reference')}
                            </Label>
                            <Input
                                id={field('reference')}
                                value={reference}
                                onChange={(event) =>
                                    setReference(event.target.value)
                                }
                                maxLength={100}
                                placeholder={t(
                                    'hours.locks.reference_placeholder',
                                )}
                                aria-describedby={field('reference-help')}
                            />
                            <p
                                id={field('reference-help')}
                                className="text-xs text-muted-foreground"
                            >
                                {t('hours.locks.reference_help')}
                            </p>
                            <InputError message={errors.reference} />
                        </div>

                        <div>
                            <Button type="submit" variant="outline">
                                <Eye aria-hidden="true" />
                                {t('hours.locks.preview')}
                            </Button>
                        </div>
                    </form>
                </section>

                {preview ? (
                    <section
                        aria-labelledby={field('preview-heading')}
                        className="grid gap-4"
                        data-test="lock-preview"
                    >
                        <h2 id={field('preview-heading')} className="text-lg">
                            {t('hours.locks.preview_title')}
                        </h2>
                        <p className="text-sm">
                            {t('hours.locks.lockable', {
                                count: lockable?.count ?? 0,
                                minutes: formatMinutes(lockable?.minutes ?? 0),
                            })}
                            {preview.summary.locked.count > 0
                                ? ` ${t('hours.locks.already_locked', { count: preview.summary.locked.count })}`
                                : ''}
                        </p>
                        {pendingCount > 0 ? (
                            <Alert className="border-warning bg-warning-soft">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="text-warning"
                                />
                                <AlertTitle>
                                    {t('hours.locks.pending_title')}
                                </AlertTitle>
                                <AlertDescription className="text-foreground">
                                    {t('hours.locks.pending', {
                                        count: pendingCount,
                                        minutes: formatMinutes(pendingMinutes),
                                        submitted:
                                            preview.summary.pending.submitted
                                                .count,
                                        draft: preview.summary.pending.draft
                                            .count,
                                    })}
                                </AlertDescription>
                            </Alert>
                        ) : null}

                        {preview.entries.length > 0 ? (
                            <div
                                className={cn(
                                    'overflow-x-auto rounded-md border',
                                    FOCUS_RING,
                                )}
                                role="region"
                                aria-labelledby={field('preview-heading')}
                                tabIndex={0}
                            >
                                <table className="w-full min-w-[40rem] text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted text-left">
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.date')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.person')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.task')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {t('hours.table.hours')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('hours.table.status')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.entries.map((entry) => (
                                            <tr
                                                key={entry.id}
                                                className="border-b last:border-b-0"
                                            >
                                                <td className="tabular px-3 py-2 whitespace-nowrap">
                                                    {formatDate(entry.date)}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {entry.user?.name}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <span className="block">
                                                        {entry.task?.title}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {entry.project?.code}
                                                    </span>
                                                </td>
                                                <td className="tabular px-3 py-2 text-right">
                                                    {formatMinutes(
                                                        entry.minutes,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <TimeEntryStatusBadge
                                                        status={entry.status}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {(lockable?.count ?? 0) >
                                preview.entries.length ? (
                                    <p className="border-t px-3 py-2 text-xs text-muted-foreground">
                                        {t('hours.locks.more', {
                                            shown: preview.entries.length,
                                            count: lockable?.count ?? 0,
                                        })}
                                    </p>
                                ) : null}
                            </div>
                        ) : (
                            <EmptyState
                                icon={Lock}
                                title={t('hours.locks.nothing')}
                            />
                        )}

                        {stale ? (
                            <p
                                role="status"
                                className="text-sm text-warning"
                                data-test="lock-preview-stale"
                            >
                                {t('hours.locks.stale')}
                            </p>
                        ) : null}

                        {(lockable?.count ?? 0) > 0 && !stale ? (
                            <div>
                                <ConfirmDialog
                                    open={confirm}
                                    onOpenChange={setConfirm}
                                    trigger={
                                        <Button type="button">
                                            <Lock aria-hidden="true" />
                                            {t('hours.locks.lock', {
                                                count: lockable?.count ?? 0,
                                            })}
                                        </Button>
                                    }
                                    title={t('hours.locks.confirm_title')}
                                    description={t(
                                        'hours.locks.confirm_description',
                                        {
                                            count: lockable?.count ?? 0,
                                            minutes: formatMinutes(
                                                lockable?.minutes ?? 0,
                                            ),
                                        },
                                    )}
                                    confirmLabel={t('hours.locks.lock', {
                                        count: lockable?.count ?? 0,
                                    })}
                                    destructive={false}
                                    processing={processing}
                                    onConfirm={lock}
                                />
                            </div>
                        ) : null}
                    </section>
                ) : null}

                <section
                    aria-labelledby={field('list-heading')}
                    className="grid gap-3"
                >
                    <h2 id={field('list-heading')} className="text-lg">
                        {t('hours.locks.list_title')}
                    </h2>
                    <InputError message={errors.lock} />
                    {locks.length === 0 ? (
                        <EmptyState
                            icon={Lock}
                            title={t('hours.locks.empty')}
                        />
                    ) : (
                        <div
                            className={cn(
                                'overflow-x-auto rounded-md border',
                                FOCUS_RING,
                            )}
                            role="region"
                            aria-labelledby={field('list-heading')}
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted text-left">
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('hours.locks.col_scope')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('hours.locks.col_range')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('hours.locks.reference')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-right font-medium"
                                        >
                                            {t('hours.locks.col_entries')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('hours.locks.col_locked')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('hours.locks.col_state')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {locks.map((item) => (
                                        <LockRow key={item.id} lock={item} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function LockRow({ lock }: { lock: TimeLockData }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const scope = lock.project
        ? `${lock.project.code} · ${lock.project.name}`
        : (lock.client?.name ?? '—');

    return (
        <tr className="border-b last:border-b-0" data-test="lock-row">
            <td className="px-3 py-2">
                <span className="block">{scope}</span>
                <span className="block text-xs text-muted-foreground">
                    {lock.project
                        ? t('hours.locks.by_project')
                        : t('hours.locks.by_client')}
                </span>
            </td>
            <td className="tabular px-3 py-2 whitespace-nowrap">
                {formatDate(lock.date_from)} – {formatDate(lock.date_to)}
            </td>
            <td className="px-3 py-2">{lock.reference ?? '—'}</td>
            <td className="tabular px-3 py-2 text-right">
                {lock.entries_count}
            </td>
            <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                {formatDateTime(lock.created_at)}
                {lock.locked_by ? ` · ${lock.locked_by.name}` : ''}
            </td>
            <td className="px-3 py-2">
                {lock.unlocked_at ? (
                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                        <LockOpen aria-hidden="true" className="size-3.5" />
                        {t('hours.locks.unlocked', {
                            date: formatDate(lock.unlocked_at),
                            name: lock.unlocked_by?.name ?? '',
                        })}
                    </span>
                ) : (
                    <ConfirmDialog
                        open={open}
                        onOpenChange={setOpen}
                        trigger={
                            <Button type="button" variant="outline" size="sm">
                                <LockOpen aria-hidden="true" />
                                {t('hours.locks.unlock')}
                            </Button>
                        }
                        title={t('hours.locks.unlock_title')}
                        description={t('hours.locks.unlock_description', {
                            count: lock.entries_count,
                            scope,
                        })}
                        confirmLabel={t('hours.locks.unlock')}
                        processing={processing}
                        onConfirm={() =>
                            router.delete(destroy.url(lock.id), {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => {
                                    setProcessing(false);
                                    setOpen(false);
                                },
                            })
                        }
                    />
                )}
            </td>
        </tr>
    );
}

TimeLocks.layout = {
    breadcrumbs: [
        { title: t('nav.time'), href: timeIndex() },
        { title: t('hours.locks.title'), href: locksIndex() },
    ],
};
