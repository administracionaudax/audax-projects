import { Link, router } from '@inertiajs/react';
import {
    Archive,
    CheckSquare,
    Info,
    ListChecks,
    Plus,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { ClientIcon } from '@/components/weeklies/weekly-ui';
import { NewTaskDialog } from '@/components/weeklies/tasks/my-space-task-dialogs';
import { MySpaceTaskRow } from '@/components/weeklies/tasks/my-space-task-row';
import {
    SUGGESTIONS_RELOAD,
    suggestionsBusy,
    TaskSuggestionsPanel,
} from '@/components/weeklies/tasks/task-suggestions-panel';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { filterTasks, groupByClient } from '@/lib/my-space-tasks';
import type { TaskStatusFilter } from '@/lib/my-space-tasks';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { suggest as suggestRoute } from '@/routes/my-space/tasks';
import type {
    MySpaceTask,
    MySpaceTaskProject,
    TaskSuggestionBatch,
    WeeklyCycleRef,
} from '@/types/weeklies';

const FILTERS: {
    id: TaskStatusFilter;
    label:
        | 'my_space.tasks.filter.all'
        | 'my_space.tasks.filter.todo'
        | 'my_space.tasks.filter.done';
}[] = [
    { id: 'all', label: 'my_space.tasks.filter.all' },
    { id: 'todo', label: 'my_space.tasks.filter.todo' },
    { id: 'done', label: 'my_space.tasks.filter.done' },
];

/**
 * La pestaña «Tareas» de «Mi espacio» (F-055 a F-063; TaskView de WeeklySync) sobre las tareas de
 * Audax asignadas a mí: Todas, Pendientes o Completadas (F-056), ver u ocultar mis archivadas (F-057),
 * agrupadas por cliente con «Tareas generales» al final (F-055), «Nueva tarea» (F-058) y «Generar
 * tareas con IA» desde la última weekly cerrada (F-062), con las propuestas para revisar.
 */
export function MySpaceTasks({
    tasks,
    projects,
    statuses,
    suggestions,
    source,
}: {
    tasks: MySpaceTask[];
    projects: MySpaceTaskProject[];
    statuses: { open: number | null; done: number | null };
    suggestions: TaskSuggestionBatch | null;
    source: WeeklyCycleRef | null;
}) {
    const [filter, setFilter] = useState<TaskStatusFilter>('all');
    const [archived, setArchived] = useState(false);
    const [creating, setCreating] = useState(false);
    const [requesting, setRequesting] = useState(false);
    const visible = filterTasks(tasks, filter, archived);
    const groups = groupByClient(visible);
    const busy = suggestionsBusy(suggestions);
    const showPanel =
        suggestions !== null &&
        !archived &&
        (busy ||
            suggestions.stuck ||
            suggestions.state === 'failed' ||
            suggestions.items.length > 0 ||
            suggestions.generated_at !== null);

    const generate = () =>
        router.post(
            suggestRoute.url(),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: SUGGESTIONS_RELOAD,
                onStart: () => setRequesting(true),
                onFinish: () => setRequesting(false),
            },
        );

    return (
        <div className="grid gap-6" data-test="my-space-tasks">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <div
                        role="group"
                        aria-label={t('my_space.tasks.filter.label')}
                        // Botones pegados de 32 px, como «Archivadas» a su lado (antes el recuadro con
                        // relleno medía 38 px). D-310.
                        className="inline-flex"
                    >
                        {FILTERS.map((option) => (
                            <button
                                key={option.id}
                                type="button"
                                aria-pressed={filter === option.id}
                                onClick={() => setFilter(option.id)}
                                className={cn(
                                    '-ml-px h-8 border border-input px-3 text-sm first:ml-0',
                                    filter === option.id
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    FOCUS_RING,
                                )}
                                data-test={`my-space-tasks-filter-${option.id}`}
                            >
                                {t(option.label)}
                            </button>
                        ))}
                    </div>
                    <Button
                        type="button"
                        variant={archived ? 'secondary' : 'outline'}
                        size="sm"
                        aria-pressed={archived}
                        onClick={() => setArchived((current) => !current)}
                        data-test="my-space-tasks-archived"
                    >
                        <Archive aria-hidden="true" />
                        {archived
                            ? t('my_space.tasks.hide_archived')
                            : t('my_space.tasks.show_archived')}
                    </Button>
                </div>

                {!archived ? (
                    <div className="grid justify-items-end gap-1">
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            <Button
                                type="button"
                                onClick={() => setCreating(true)}
                                data-test="my-space-tasks-new"
                            >
                                <Plus aria-hidden="true" />
                                {t('my_space.tasks.new.action')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={generate}
                                disabled={source === null || busy || requesting}
                                data-test="my-space-tasks-generate"
                            >
                                {busy || requesting ? (
                                    <Spinner />
                                ) : (
                                    <Sparkles aria-hidden="true" />
                                )}
                                {t('my_space.tasks.generate')}
                            </Button>
                        </div>
                        <p className="flex items-center gap-1 text-xs text-muted-foreground">
                            <Info aria-hidden="true" className="size-3" />
                            {source
                                ? t('my_space.tasks.generate_source', {
                                      week: source.label,
                                  })
                                : t('my_space.tasks.generate_no_source')}
                        </p>
                    </div>
                ) : null}
            </div>

            {showPanel && suggestions ? (
                <TaskSuggestionsPanel batch={suggestions} projects={projects} />
            ) : null}

            {visible.length === 0 ? (
                <div className="grid justify-items-center gap-3 border border-dashed p-10 text-center">
                    {archived ? (
                        <Archive
                            aria-hidden="true"
                            className="size-8 text-muted-foreground"
                            strokeWidth={1.5}
                        />
                    ) : (
                        <CheckSquare
                            aria-hidden="true"
                            className="size-8 text-muted-foreground"
                            strokeWidth={1.5}
                        />
                    )}
                    <p className="text-base">
                        {archived
                            ? t('my_space.tasks.empty.archived')
                            : t('my_space.tasks.empty.title')}
                    </p>
                    {!archived ? (
                        <>
                            <p className="max-w-sm text-sm text-muted-foreground">
                                {filter === 'done'
                                    ? t('my_space.tasks.empty.done')
                                    : t('my_space.tasks.empty.description')}
                            </p>
                            <Button
                                type="button"
                                variant="link"
                                onClick={() => setCreating(true)}
                            >
                                {t('my_space.tasks.empty.create')}
                            </Button>
                        </>
                    ) : null}
                </div>
            ) : (
                <div className="grid gap-8">
                    {groups.map((group) => {
                        const name =
                            group.client?.name ?? t('my_space.tasks.general');
                        const headingId = `my-space-tasks-${group.key}`;

                        return (
                            <section
                                key={group.key}
                                aria-labelledby={headingId}
                                className="grid gap-3"
                            >
                                <h2
                                    id={headingId}
                                    className="flex items-center gap-2 text-base font-normal"
                                >
                                    <ClientIcon icon={group.client?.icon} />
                                    {name}
                                    <span className="tabular text-xs text-muted-foreground">
                                        ({group.tasks.length})
                                    </span>
                                </h2>
                                <ul className="border bg-card">
                                    {group.tasks.map((task) => (
                                        <MySpaceTaskRow
                                            key={task.id}
                                            task={task}
                                            statuses={statuses}
                                        />
                                    ))}
                                </ul>
                            </section>
                        );
                    })}
                </div>
            )}

            <p className="text-sm">
                <Link
                    href={urls.myTasks()}
                    className={cn(
                        'inline-flex items-center gap-1.5 text-primary-text underline',
                        FOCUS_RING,
                    )}
                >
                    <ListChecks aria-hidden="true" className="size-4" />
                    {t('my_space.tasks_link')}
                </Link>
            </p>

            <NewTaskDialog
                open={creating}
                onOpenChange={setCreating}
                projects={projects}
            />
        </div>
    );
}
