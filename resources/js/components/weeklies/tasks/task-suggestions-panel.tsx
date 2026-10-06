import { router } from '@inertiajs/react';
import { Bot, CircleAlert, Info, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { toast } from 'sonner';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
import { PrioritySelect } from '@/components/tasks/task-fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { AI_POLL_MS } from '@/components/weeklies/insights/ai-summary-panel';
import {
    BankPicker,
    ClientPicker,
    ProjectPicker,
} from '@/components/weeklies/tasks/my-space-task-fields';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    defaultBank,
    draftProblems,
    projectsOfClient,
    suggestionDraft,
} from '@/lib/my-space-tasks';
import type { SuggestionDraft } from '@/lib/my-space-tasks';
import {
    accept as acceptRoute,
    dismiss as dismissRoute,
} from '@/routes/my-space/tasks/suggestions';
import type { MySpaceTaskProject, TaskSuggestionBatch } from '@/types/weeklies';

/** Lo que se recarga mientras se generan y al crear o descartar. */
export const SUGGESTIONS_RELOAD = ['suggestions', 'my_tasks'];

export function suggestionsBusy(batch: TaskSuggestionBatch | null): boolean {
    return (
        batch !== null &&
        (batch.state === 'queued' || batch.state === 'running') &&
        !batch.stuck
    );
}

type RowErrors = Partial<
    Record<'title' | 'project' | 'bank' | 'general', string>
>;

/**
 * Tareas sugeridas por IA (F-062, D-204): la tanda que ha propuesto la IA a partir de la última
 * weekly cerrada. Nada se crea solo: cada propuesta se revisa (título, proyecto y bolsa, sugeridos
 * por el cliente; prioridad y entrega) y se crean las marcadas, o se descartan. Mientras se genera,
 * la página recarga la tanda cada 3 s (sin Reverb).
 */
export function TaskSuggestionsPanel({
    batch,
    projects,
}: {
    batch: TaskSuggestionBatch;
    projects: MySpaceTaskProject[];
}) {
    const busy = suggestionsBusy(batch);
    const [edits, setEdits] = useState<Record<string, SuggestionDraft>>({});
    const [errors, setErrors] = useState<Record<string, RowErrors>>({});
    const [processing, setProcessing] = useState(false);
    const drafts = batch.items.map(
        (item) => edits[item.key] ?? suggestionDraft(item, projects),
    );
    const selected = drafts.filter((draft) => draft.selected);
    const sectionRef = useRef<HTMLElement>(null);
    const titleRef = useRef<HTMLHeadingElement>(null);
    const wasBusy = useRef(busy);

    // Al terminar de generar (10.9b, D-231): aviso con cuántas hay y la revisión a la vista, con
    // el foco en su título, para que crearlas sea un clic.
    useEffect(() => {
        if (wasBusy.current && !busy && batch.state === 'done') {
            if (batch.items.length > 0) {
                toast.success(
                    t('my_space.tasks.suggestions.ready', {
                        count: batch.items.length,
                    }),
                );
                sectionRef.current?.scrollIntoView?.({
                    behavior: 'smooth',
                    block: 'start',
                });
                titleRef.current?.focus({ preventScroll: true });
            } else {
                toast.info(t('my_space.tasks.suggestions.none'));
            }
        }

        wasBusy.current = busy;
    }, [busy, batch.state, batch.items.length]);

    useEffect(() => {
        if (!busy) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: SUGGESTIONS_RELOAD }),
            AI_POLL_MS,
        );

        return () => window.clearInterval(timer);
    }, [busy]);

    const edit = (draft: SuggestionDraft, change: Partial<SuggestionDraft>) => {
        setEdits((current) => ({
            ...current,
            [draft.key]: { ...draft, ...change },
        }));
        setErrors((current) => ({ ...current, [draft.key]: {} }));
    };

    const create = () => {
        const local: Record<string, RowErrors> = {};

        for (const draft of selected) {
            const problems = draftProblems(draft, projects);

            if (problems.length > 0) {
                local[draft.key] = Object.fromEntries(
                    problems.map((problem) => [
                        problem,
                        t(`my_space.tasks.errors.${problem}`),
                    ]),
                );
            }
        }

        setErrors(local);

        if (Object.keys(local).length > 0 || selected.length === 0) {
            return;
        }

        router.post(
            acceptRoute.url(),
            {
                tasks: selected.map((draft) => ({
                    key: draft.key,
                    title: draft.title.trim(),
                    project_id: draft.projectId,
                    hour_bank_id: draft.bankId,
                    priority: draft.priority,
                    due_date: draft.dueDate,
                })),
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: SUGGESTIONS_RELOAD,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setEdits({});
                    setErrors({});
                },
                onError: (server) => {
                    const mapped: Record<string, RowErrors> = {};

                    for (const [field, message] of Object.entries(
                        server as Record<string, string>,
                    )) {
                        const match = /^tasks\.(\d+)\.(\w+)$/.exec(field);
                        const key = match
                            ? selected[Number(match[1])]?.key
                            : undefined;

                        if (!key || !match) {
                            mapped._ = { general: message };
                            continue;
                        }

                        const name =
                            match[2] === 'project_id'
                                ? 'project'
                                : match[2] === 'hour_bank_id'
                                  ? 'bank'
                                  : match[2] === 'title'
                                    ? 'title'
                                    : 'general';
                        mapped[key] = { ...mapped[key], [name]: message };
                    }

                    setErrors(mapped);
                },
            },
        );
    };

    const dismiss = (keys?: string[]) =>
        router.delete(dismissRoute.url(), {
            data: keys ? { keys } : {},
            preserveScroll: true,
            preserveState: true,
            only: SUGGESTIONS_RELOAD,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });

    return (
        <section
            ref={sectionRef}
            aria-labelledby="task-suggestions-title"
            aria-busy={busy || processing}
            className="grid gap-3 border bg-card p-4"
            data-test="task-suggestions"
        >
            <header className="flex flex-wrap items-start justify-between gap-2">
                <div className="grid min-w-0 gap-1">
                    <h2
                        id="task-suggestions-title"
                        ref={titleRef}
                        tabIndex={-1}
                        className="flex items-center gap-2 text-base font-normal outline-none"
                    >
                        <Bot
                            aria-hidden="true"
                            className="size-4 text-primary"
                            strokeWidth={1.5}
                        />
                        {t('my_space.tasks.suggestions.title')}
                    </h2>
                    {batch.cycle ? (
                        <p className="text-sm text-muted-foreground">
                            {t('my_space.tasks.suggestions.source', {
                                week: batch.cycle.label,
                            })}
                        </p>
                    ) : null}
                </div>
                {batch.items.length > 0 && !busy ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={processing}
                        onClick={() => dismiss()}
                        data-test="task-suggestions-dismiss-all"
                    >
                        <X aria-hidden="true" />
                        {t('my_space.tasks.suggestions.dismiss_all')}
                    </Button>
                ) : null}
            </header>

            <div aria-live="polite" className="grid gap-3">
                {busy ? (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner />
                        {batch.state === 'queued'
                            ? t('my_space.tasks.suggestions.queued')
                            : t('my_space.tasks.suggestions.generating')}
                    </p>
                ) : batch.stuck ? (
                    <p className="flex items-center gap-2 text-sm text-warning">
                        <CircleAlert aria-hidden="true" className="size-4" />
                        {t('my_space.tasks.suggestions.stuck')}
                    </p>
                ) : batch.state === 'failed' ? (
                    <p
                        role="alert"
                        className="flex items-center gap-2 text-sm text-danger"
                    >
                        <CircleAlert aria-hidden="true" className="size-4" />
                        {t('my_space.tasks.suggestions.failed', {
                            error: batch.error ?? '',
                        })}
                    </p>
                ) : batch.items.length === 0 ? (
                    <p
                        className="text-sm text-muted-foreground"
                        data-test="task-suggestions-empty"
                    >
                        {batch.skipped > 0
                            ? t('my_space.tasks.suggestions.all_exist')
                            : t('my_space.tasks.suggestions.none')}
                        {batch.generated_at
                            ? ` ${t('my_space.tasks.suggestions.generated_at', {
                                  date: formatDateTime(batch.generated_at),
                              })}`
                            : null}
                    </p>
                ) : null}
            </div>

            {!busy && batch.items.length > 0 ? (
                <>
                    <p className="flex items-start gap-2 border bg-muted/40 p-2 text-xs text-foreground">
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0 text-info"
                        />
                        <span>
                            {t('my_space.tasks.suggestions.notice')}
                            {batch.skipped > 0
                                ? ` ${t('my_space.tasks.suggestions.skipped', {
                                      count: batch.skipped,
                                  })}`
                                : null}
                        </span>
                    </p>
                    {errors._?.general ? (
                        <p role="alert" className="text-sm text-danger">
                            {errors._.general}
                        </p>
                    ) : null}
                    <ul className="grid gap-3">
                        {batch.items.map((item, index) => (
                            <SuggestionRow
                                key={item.key}
                                draft={drafts[index]}
                                author={item.author_name}
                                projects={projects}
                                errors={errors[item.key] ?? {}}
                                disabled={processing}
                                onChange={(change) =>
                                    edit(drafts[index], change)
                                }
                                onDismiss={() => dismiss([item.key])}
                            />
                        ))}
                    </ul>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <Button
                            type="button"
                            disabled={processing || selected.length === 0}
                            onClick={create}
                            data-test="task-suggestions-create"
                        >
                            {processing ? <Spinner /> : null}
                            {t('my_space.tasks.suggestions.create', {
                                count: selected.length,
                            })}
                        </Button>
                    </div>
                </>
            ) : null}
        </section>
    );
}

function SuggestionRow({
    draft,
    author,
    projects,
    errors,
    disabled,
    onChange,
    onDismiss,
}: {
    draft: SuggestionDraft;
    author: string | null;
    projects: MySpaceTaskProject[];
    errors: RowErrors;
    disabled: boolean;
    onChange: (change: Partial<SuggestionDraft>) => void;
    onDismiss: () => void;
}) {
    const id = useId();
    const project = projects.find((p) => p.id === draft.projectId);
    const clientProjects = projectsOfClient(projects, draft.clientId);

    return (
        <li
            className="grid gap-3 border p-3"
            data-test={`task-suggestion-${draft.key}`}
        >
            <div className="flex items-start gap-3">
                <Checkbox
                    checked={draft.selected}
                    onCheckedChange={(checked) =>
                        onChange({ selected: checked === true })
                    }
                    aria-label={t('my_space.tasks.suggestions.select', {
                        task: draft.title,
                    })}
                    className="mt-2.5"
                    disabled={disabled}
                />
                <div className="grid min-w-0 flex-1 gap-1.5">
                    <Label htmlFor={`${id}-title`} className="sr-only">
                        {t('my_space.tasks.field.title')}
                    </Label>
                    <Input
                        id={`${id}-title`}
                        value={draft.title}
                        maxLength={255}
                        disabled={disabled}
                        onChange={(event) =>
                            onChange({ title: event.target.value })
                        }
                        aria-invalid={errors.title ? true : undefined}
                        data-test="task-suggestion-title"
                    />
                    <InputError message={errors.title} />
                    {author ? (
                        <p className="text-xs text-muted-foreground">
                            {t('my_space.tasks.suggestions.from', {
                                name: author,
                            })}
                        </p>
                    ) : null}
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    disabled={disabled}
                    onClick={onDismiss}
                    aria-label={t('my_space.tasks.suggestions.dismiss', {
                        task: draft.title,
                    })}
                    title={t('my_space.tasks.suggestions.dismiss_short')}
                >
                    <X aria-hidden="true" />
                </Button>
            </div>
            {draft.selected ? (
                <div className="grid gap-3 sm:grid-cols-2 sm:pl-7 lg:grid-cols-4">
                    <div className="grid content-start gap-1.5">
                        <Label htmlFor={`${id}-client`} className="text-xs">
                            {t('my_space.tasks.field.client')}
                        </Label>
                        <ClientPicker
                            id={`${id}-client`}
                            projects={projects}
                            value={draft.clientId}
                            disabled={disabled}
                            onChange={(clientId) => {
                                const options = projectsOfClient(
                                    projects,
                                    clientId,
                                );
                                const only =
                                    options.length === 1
                                        ? options[0]
                                        : undefined;
                                onChange({
                                    clientId,
                                    projectId: only?.id ?? null,
                                    bankId: defaultBank(only),
                                });
                            }}
                        />
                    </div>
                    <div className="grid content-start gap-1.5">
                        <Label htmlFor={`${id}-project`} className="text-xs">
                            {t('my_space.tasks.field.project')}
                        </Label>
                        <ProjectPicker
                            id={`${id}-project`}
                            projects={
                                clientProjects.length > 0
                                    ? clientProjects
                                    : projects
                            }
                            value={draft.projectId}
                            disabled={disabled}
                            invalid={Boolean(errors.project)}
                            onChange={(projectId) =>
                                onChange({
                                    projectId,
                                    bankId: defaultBank(
                                        projects.find(
                                            (p) => p.id === projectId,
                                        ),
                                    ),
                                })
                            }
                        />
                        <InputError message={errors.project} />
                    </div>
                    {project?.uses_banks ? (
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`${id}-bank`} className="text-xs">
                                {t('my_space.tasks.field.bank')}
                            </Label>
                            <BankPicker
                                id={`${id}-bank`}
                                project={project}
                                value={draft.bankId}
                                disabled={disabled}
                                invalid={Boolean(errors.bank)}
                                onChange={(bankId) => onChange({ bankId })}
                            />
                            <InputError message={errors.bank} />
                        </div>
                    ) : null}
                    <div className="grid content-start gap-1.5">
                        <Label htmlFor={`${id}-priority`} className="text-xs">
                            {t('my_space.tasks.field.priority')}
                        </Label>
                        <PrioritySelect
                            id={`${id}-priority`}
                            value={draft.priority}
                            disabled={disabled}
                            onChange={(priority) => onChange({ priority })}
                        />
                    </div>
                    <div className="grid content-start gap-1.5">
                        <Label htmlFor={`${id}-due`} className="text-xs">
                            {t('my_space.tasks.field.due_date')}
                        </Label>
                        <DatePicker
                            id={`${id}-due`}
                            value={draft.dueDate}
                            disabled={disabled}
                            onChange={(dueDate) => onChange({ dueDate })}
                        />
                    </div>
                    {errors.general ? (
                        <p
                            role="alert"
                            className="text-sm text-danger sm:col-span-2 lg:col-span-4"
                        >
                            {errors.general}
                        </p>
                    ) : null}
                </div>
            ) : null}
        </li>
    );
}
