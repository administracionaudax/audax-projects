import { router, useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useMemo, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ClientIcon } from '@/components/weeklies/weekly-ui';
import { addDays } from '@/lib/week';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { update as updateDeadline } from '@/routes/weeklies/deadline';
import { store as storeExemption } from '@/routes/weeklies/exemptions';
import { join as joinProjects } from '@/routes/weeklies/projects';
import type { UserSummary } from '@/types';
import type {
    WeeklyCycleSummary,
    WeeklyJoinableProject,
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
 * Eximir a alguien de la weekly de esta semana (F-038, D-159): exención manual con una nota
 * opcional. Quien tiene una ausencia aprobada ya está exento solo (F-097).
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
    const [open, setOpen] = useState(false);
    const form = useForm({ user_id: person.id, note: '' });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.setData({ user_id: person.id, note: '' });
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
                        form.post(storeExemption.url(cycle.id), {
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
                                error: form.errors.note ?? form.errors.user_id,
                            })}
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
                            disabled={form.processing}
                            data-test="weekly-exempt-confirm"
                        >
                            {form.processing && <Spinner />}
                            {t('weeklies.exempt_dialog.confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * «Unirme a proyectos» (F-034, D-156): varios a la vez, como miembro. La lista (prop opcional
 * `joinable_projects`) se pide al abrir el diálogo.
 */
export function JoinProjectsDialog({
    projects,
    trigger,
}: {
    projects: WeeklyJoinableProject[] | undefined;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const groups = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase('es');
        const map = new Map<
            number,
            {
                client: WeeklyJoinableProject['client'];
                projects: WeeklyJoinableProject[];
            }
        >();

        for (const project of projects ?? []) {
            const haystack =
                `${project.code} ${project.name} ${project.client.name}`.toLocaleLowerCase(
                    'es',
                );

            if (needle !== '' && !haystack.includes(needle)) {
                continue;
            }

            const group = map.get(project.client.id) ?? {
                client: project.client,
                projects: [],
            };
            group.projects.push(project);
            map.set(project.client.id, group);
        }

        return [...map.values()].sort((a, b) =>
            a.client.name.localeCompare(b.client.name, 'es'),
        );
    }, [projects, query]);

    const toggle = (projectId: number, checked: boolean) =>
        setSelected((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(projectId);
            } else {
                next.delete(projectId);
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
                    setLoading(true);
                    router.reload({
                        only: ['joinable_projects'],
                        onFinish: () => setLoading(false),
                    });
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
                            joinProjects.url(),
                            { project_ids: [...selected] },
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
                        <DialogTitle>{t('weeklies.join.title')}</DialogTitle>
                        <DialogDescription>
                            {t('weeklies.join.description')}
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
                        {loading && projects === undefined ? (
                            <p className="flex items-center gap-2 p-3 text-sm text-muted-foreground">
                                <Spinner />
                                {t('weeklies.join.loading')}
                            </p>
                        ) : groups.length === 0 ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                {t('weeklies.join.empty')}
                            </p>
                        ) : (
                            groups.map((group) => (
                                <fieldset
                                    key={group.client.id}
                                    className="border-b p-3 last:border-b-0"
                                >
                                    <legend className="sr-only">
                                        {group.client.name}
                                    </legend>
                                    <p
                                        aria-hidden="true"
                                        className="mb-2 flex items-center gap-2 text-sm font-medium"
                                    >
                                        <ClientIcon
                                            icon={group.client.icon}
                                            className="size-6"
                                        />
                                        {group.client.name}
                                    </p>
                                    <ul className="grid gap-1.5">
                                        {group.projects.map((project) => {
                                            const checkbox = `${id}-${project.id}`;

                                            return (
                                                <li
                                                    key={project.id}
                                                    className="flex items-center gap-2"
                                                >
                                                    <Checkbox
                                                        id={checkbox}
                                                        checked={selected.has(
                                                            project.id,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            toggle(
                                                                project.id,
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    <label
                                                        htmlFor={checkbox}
                                                        className="min-w-0 text-sm"
                                                    >
                                                        <span className="text-muted-foreground">
                                                            {project.code}
                                                        </span>{' '}
                                                        {project.name}
                                                    </label>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </fieldset>
                            ))
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
                        >
                            {processing && <Spinner />}
                            {selected.size > 0
                                ? t('weeklies.join.confirm_count', {
                                      count: selected.size,
                                  })
                                : t('weeklies.join.confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
