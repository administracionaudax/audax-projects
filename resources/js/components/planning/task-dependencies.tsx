import { router } from '@inertiajs/react';
import {
    CircleCheck,
    Diamond,
    Link2,
    Plus,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { toast } from 'sonner';
import { taskDatesText } from '@/components/planning/calendar-chip';
import { toastErrors } from '@/components/tasks/task-requests';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { candidates as candidatesRoute } from '@/routes/planning/dependencies';
import {
    destroy as destroyDependency,
    store as storeDependency,
} from '@/routes/schedule/dependencies';
import type { TaskPanelData } from '@/types';
import type { DependencyCandidate, LinkedTask } from '@/types/planning';

export const CANDIDATE_SEARCH_DEBOUNCE_MS = 200;

type Kind = 'predecessors' | 'successors';

type Candidates = {
    key: string;
    status: 'success' | 'error';
    tasks: DependencyCandidate[];
};

async function fetchCandidates(
    taskId: number,
    search: string,
    signal: AbortSignal,
): Promise<DependencyCandidate[]> {
    const response = await fetch(
        candidatesRoute.url(taskId, {
            query: search === '' ? {} : { buscar: search },
        }),
        {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal,
        },
    );

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const data = (await response.json()) as { tasks?: DependencyCandidate[] };

    return Array.isArray(data.tasks) ? data.tasks : [];
}

/**
 * Tareas del proyecto para «Añadir» (GET /tareas/{task}/dependencias/candidatas): solo mientras
 * el buscador está abierto, con debounce y cancelando la petición anterior.
 */
function useCandidates(taskId: number, rawSearch: string, enabled: boolean) {
    const search = rawSearch.trim();
    const key = `${taskId}|${search}`;
    const [completed, setCompleted] = useState<Candidates | null>(null);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetchCandidates(taskId, search, controller.signal)
                .then((tasks) =>
                    setCompleted({ key, status: 'success', tasks }),
                )
                .catch(() => {
                    if (!controller.signal.aborted) {
                        setCompleted({ key, status: 'error', tasks: [] });
                    }
                });
        }, CANDIDATE_SEARCH_DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [enabled, key, search, taskId]);

    if (completed === null || completed.key !== key) {
        return { status: 'loading' as const, tasks: [] };
    }

    return completed;
}

function AddDependency({
    panel,
    kind,
    exclude,
    onError,
}: {
    panel: TaskPanelData;
    kind: Kind;
    exclude: Set<number>;
    onError: (message: string | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [saving, setSaving] = useState(false);
    const candidates = useCandidates(panel.task.id, search, open);
    const options = candidates.tasks.filter((task) => !exclude.has(task.id));
    // Nombre accesible: contiene el texto visible («Añadir», WCAG 2.5.3) y dice a qué lista.
    const label =
        kind === 'predecessors'
            ? t('planning.dependencies.add_predecessor')
            : t('planning.dependencies.add_successor');

    const add = (other: DependencyCandidate) => {
        setOpen(false);
        setSearch('');
        onError(null);
        setSaving(true);

        router.post(
            storeDependency.url(panel.project.id),
            kind === 'predecessors'
                ? {
                      predecessor_task_id: other.id,
                      successor_task_id: panel.task.id,
                  }
                : {
                      predecessor_task_id: panel.task.id,
                      successor_task_id: other.id,
                  },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['panel'],
                onError: (errors) =>
                    onError(
                        Object.values(errors)[0] ?? t('task_errors.generic'),
                    ),
                onHttpException: () => {
                    toast.error(t('task_errors.server'));

                    return false;
                },
                onNetworkError: () => {
                    toast.error(t('task_errors.network'));

                    return false;
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    setSearch('');
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-fit"
                    disabled={saving}
                    aria-expanded={open}
                    aria-label={label}
                    data-test={`dependencies-add-${kind}`}
                >
                    <Plus aria-hidden="true" />
                    {t('planning.dependencies.add')}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-0" align="start">
                <Command shouldFilter={false}>
                    <CommandInput
                        value={search}
                        onValueChange={setSearch}
                        placeholder={t('planning.dependencies.search')}
                        aria-label={t('planning.dependencies.search')}
                    />
                    <CommandList>
                        {candidates.status === 'loading' ? (
                            <p
                                role="status"
                                className="px-3 py-4 text-sm text-muted-foreground"
                            >
                                {t('planning.dependencies.loading')}
                            </p>
                        ) : candidates.status === 'error' ? (
                            <p
                                role="alert"
                                className="px-3 py-4 text-sm text-danger"
                            >
                                {t('planning.dependencies.load_error')}
                            </p>
                        ) : (
                            <>
                                <CommandEmpty>
                                    {t('planning.dependencies.no_candidates')}
                                </CommandEmpty>
                                <CommandGroup>
                                    {options.map((task) => (
                                        <CommandItem
                                            key={task.id}
                                            value={String(task.id)}
                                            onSelect={() => add(task)}
                                            data-test="dependency-candidate"
                                        >
                                            {task.is_milestone ? (
                                                <Diamond
                                                    aria-hidden="true"
                                                    className="fill-current text-primary-text"
                                                />
                                            ) : task.is_completed ? (
                                                <CircleCheck
                                                    aria-hidden="true"
                                                    className="text-success"
                                                />
                                            ) : (
                                                <Link2 aria-hidden="true" />
                                            )}
                                            <span className="grid min-w-0">
                                                <span className="truncate">
                                                    {task.title}
                                                </span>
                                                <span className="truncate text-xs text-muted-foreground">
                                                    {[
                                                        task.parent_title
                                                            ? t(
                                                                  'planning.dependencies.in_parent',
                                                                  {
                                                                      parent: task.parent_title,
                                                                  },
                                                              )
                                                            : null,
                                                        taskDatesText(task),
                                                        task.is_completed
                                                            ? t(
                                                                  'planning.dependencies.completed',
                                                              )
                                                            : null,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            </span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            </>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

function LinkedItem({
    link,
    kind,
    canUpdate,
    onOpen,
}: {
    link: LinkedTask;
    kind: Kind;
    canUpdate: boolean;
    onOpen: (taskId: number) => void;
}) {
    const [removing, setRemoving] = useState(false);
    const conflictText =
        kind === 'predecessors'
            ? t('planning.dependencies.conflict_predecessor', {
                  task: link.task.title,
              })
            : t('planning.dependencies.conflict_successor', {
                  task: link.task.title,
              });

    const remove = () =>
        router.delete(destroyDependency.url(link.dependency_id), {
            preserveScroll: true,
            preserveState: true,
            only: ['panel'],
            onStart: () => setRemoving(true),
            onError: (errors) => toastErrors(errors as Record<string, string>),
            onHttpException: () => {
                toast.error(t('task_errors.server'));

                return false;
            },
            onNetworkError: () => {
                toast.error(t('task_errors.network'));

                return false;
            },
            onFinish: () => setRemoving(false),
        });

    return (
        <li
            className="flex items-start gap-2 rounded-md border px-2 py-1.5"
            data-test="dependency-item"
            data-task-id={link.task.id}
        >
            <div className="min-w-0 flex-1">
                <button
                    type="button"
                    onClick={() => onOpen(link.task.id)}
                    className={cn(
                        'inline-flex max-w-full items-center gap-1 rounded-md text-left text-sm hover:underline',
                        link.task.is_completed &&
                            'text-muted-foreground line-through',
                        FOCUS_RING,
                    )}
                >
                    {link.task.is_milestone ? (
                        <Diamond
                            aria-hidden="true"
                            className="size-3.5 shrink-0 fill-current text-primary-text"
                        />
                    ) : null}
                    <span className="truncate">{link.task.title}</span>
                    {link.task.is_milestone ? (
                        <span className="sr-only">
                            {' '}
                            ({t('task_fields.milestone')})
                        </span>
                    ) : null}
                </button>
                <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span className="tabular">{taskDatesText(link.task)}</span>
                    {link.task.is_completed ? (
                        <span className="inline-flex items-center gap-1">
                            <CircleCheck
                                aria-hidden="true"
                                className="size-3.5 text-success"
                            />
                            {t('planning.dependencies.completed')}
                        </span>
                    ) : null}
                    {link.conflict ? (
                        <span
                            className="inline-flex items-center gap-1 font-medium text-danger"
                            title={conflictText}
                            data-test="dependency-conflict"
                        >
                            <TriangleAlert
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {t('planning.dependencies.conflict')}
                            <span className="sr-only">: {conflictText}</span>
                        </span>
                    ) : null}
                </p>
            </div>
            {canUpdate ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7 shrink-0"
                    disabled={removing}
                    onClick={remove}
                    aria-label={t('planning.dependencies.remove', {
                        task: link.task.title,
                    })}
                    data-test="dependency-remove"
                >
                    <X aria-hidden="true" />
                </Button>
            ) : null}
        </li>
    );
}

function Group({
    panel,
    kind,
    links,
    exclude,
    onOpen,
}: {
    panel: TaskPanelData;
    kind: Kind;
    links: LinkedTask[];
    exclude: Set<number>;
    onOpen: (taskId: number) => void;
}) {
    const headingId = useId();
    const errorId = useId();
    const [error, setError] = useState<string | null>(null);

    return (
        <div
            className="grid gap-2"
            role="group"
            aria-labelledby={headingId}
            data-test={`dependencies-${kind}`}
        >
            <h4
                id={headingId}
                className="text-xs font-medium text-muted-foreground"
            >
                {kind === 'predecessors'
                    ? t('planning.dependencies.predecessors')
                    : t('planning.dependencies.successors')}
            </h4>
            {links.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {kind === 'predecessors'
                        ? t('planning.dependencies.no_predecessors')
                        : t('planning.dependencies.no_successors')}
                </p>
            ) : (
                <ul className="grid gap-1.5">
                    {links.map((link) => (
                        <LinkedItem
                            key={link.dependency_id}
                            link={link}
                            kind={kind}
                            canUpdate={panel.can.update}
                            onOpen={onOpen}
                        />
                    ))}
                </ul>
            )}
            {panel.can.update ? (
                <div
                    className="grid gap-1"
                    aria-describedby={error ? errorId : undefined}
                >
                    <AddDependency
                        panel={panel}
                        kind={kind}
                        exclude={exclude}
                        onError={setError}
                    />
                    <InputError
                        id={errorId}
                        message={error ?? undefined}
                        data-test="dependency-error"
                    />
                </div>
            ) : null}
        </div>
    );
}

/**
 * Sección «Dependencias» del panel de la tarea (D-056, D-062): «Depende de» (predecesoras) y
 * «Bloquea a» (sucesoras), enlazadas a su tarea y con la marca de conflicto de fechas (D-057,
 * icono y texto). Quien puede editar añade (tareas del mismo proyecto) y quita; los errores del
 * servidor (un ciclo, por ejemplo) se ven junto al botón. Las dependencias son una ayuda: avisan,
 * no impiden mover.
 */
export function TaskDependencies({
    panel,
    onOpen,
}: {
    panel: TaskPanelData;
    onOpen: (taskId: number) => void;
}) {
    const dependencies = panel.dependencies;

    if (!dependencies) {
        return null;
    }

    const conflicts = [
        ...dependencies.predecessors,
        ...dependencies.successors,
    ].filter((link) => link.conflict).length;

    return (
        <div className="grid gap-4" data-test="task-dependencies">
            {conflicts > 0 ? (
                <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0 text-danger"
                    />
                    {t('planning.dependencies.conflicts_help')}
                </p>
            ) : null}
            <Group
                panel={panel}
                kind="predecessors"
                links={dependencies.predecessors}
                exclude={
                    new Set(
                        dependencies.predecessors.map((link) => link.task.id),
                    )
                }
                onOpen={onOpen}
            />
            <Group
                panel={panel}
                kind="successors"
                links={dependencies.successors}
                exclude={
                    new Set(dependencies.successors.map((link) => link.task.id))
                }
                onOpen={onOpen}
            />
            {!panel.can.update ? (
                <p className="text-xs text-muted-foreground">
                    {t('planning.dependencies.read_only')}
                </p>
            ) : null}
        </div>
    );
}
