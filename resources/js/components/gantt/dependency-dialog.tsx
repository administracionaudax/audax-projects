import { Check, CircleAlert, Link2, Unlink } from 'lucide-react';
import { useId, useState } from 'react';
import type { GanttTask } from '@/components/gantt/types';
import { formatRange } from '@/components/gantt/conflict-dialog';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskDependencyItem } from '@/types/schedule';

type Direction = 'after' | 'before';

/**
 * «Añadir dependencia…» (alternativa accesible a arrastrar el conector, D-060): se elige si la
 * tarea va después de otra (la otra es su predecesora) o antes (su sucesora) y la otra tarea en
 * una lista con buscador de las tareas del proyecto. Los errores del servidor (un ciclo, otra
 * tarea) se enseñan aquí mismo. También lista sus dependencias para quitarlas.
 */
export function DependencyDialog({
    task,
    candidates,
    dependencies,
    onLink,
    onUnlink,
    onOpenChange,
    onCloseAutoFocus,
}: {
    task: GanttTask | null;
    /** Tareas del mismo proyecto (la propia se descarta). */
    candidates: ReadonlyArray<GanttTask>;
    dependencies: ReadonlyArray<TaskDependencyItem>;
    onLink: (
        predecessor: GanttTask,
        successor: GanttTask,
        callbacks: {
            onSuccess: () => void;
            onFailure: (message: string) => void;
            onFinish: () => void;
        },
    ) => void;
    onUnlink?: (dependency: TaskDependencyItem) => void;
    onOpenChange: (open: boolean) => void;
    onCloseAutoFocus?: (event: Event) => void;
}) {
    return (
        <Dialog open={task !== null} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-lg"
                onCloseAutoFocus={onCloseAutoFocus}
                data-test="gantt-dependency-dialog"
            >
                {task ? (
                    <DependencyForm
                        key={task.id}
                        task={task}
                        candidates={candidates}
                        dependencies={dependencies}
                        onLink={onLink}
                        onUnlink={onUnlink}
                        onDone={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function DependencyForm({
    task,
    candidates,
    dependencies,
    onLink,
    onUnlink,
    onDone,
}: {
    task: GanttTask;
    candidates: ReadonlyArray<GanttTask>;
    dependencies: ReadonlyArray<TaskDependencyItem>;
    onLink: Parameters<typeof DependencyDialog>[0]['onLink'];
    onUnlink?: (dependency: TaskDependencyItem) => void;
    onDone: () => void;
}) {
    const directionId = useId();
    const listId = useId();
    const errorId = useId();
    const [direction, setDirection] = useState<Direction>('after');
    const [selected, setSelected] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const others = candidates.filter(
        (candidate) =>
            candidate.id !== task.id &&
            candidate.project_id === task.project_id,
    );
    const byId = new Map(others.map((candidate) => [candidate.id, candidate]));
    const own = dependencies.filter(
        (dependency) =>
            dependency.predecessor_task_id === task.id ||
            dependency.successor_task_id === task.id,
    );
    const linked = new Set(
        own.map((dependency) =>
            dependency.predecessor_task_id === task.id
                ? `before-${dependency.successor_task_id}`
                : `after-${dependency.predecessor_task_id}`,
        ),
    );

    const submit = () => {
        const other = selected !== null ? byId.get(selected) : undefined;

        if (!other) {
            setError(t('gantt.dependency.choose'));

            return;
        }

        const [predecessor, successor] =
            direction === 'after' ? [other, task] : [task, other];

        setProcessing(true);
        setError(null);
        onLink(predecessor, successor, {
            onSuccess: onDone,
            onFailure: (message) => setError(message),
            onFinish: () => setProcessing(false),
        });
    };

    const titleOf = (id: number) =>
        id === task.id ? task.title : (byId.get(id)?.title ?? `#${id}`);

    return (
        <>
            <DialogHeader>
                <DialogTitle>{t('gantt.dependency.title')}</DialogTitle>
                <DialogDescription>
                    {t('gantt.dependency.description', { task: task.title })}
                </DialogDescription>
            </DialogHeader>

            <form
                className="grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <fieldset className="grid gap-2">
                    <legend
                        id={directionId}
                        className="mb-1 text-sm font-medium"
                    >
                        {t('gantt.dependency.direction')}
                    </legend>
                    <RadioGroup
                        value={direction}
                        onValueChange={(value) => {
                            setDirection(
                                value === 'before' ? 'before' : 'after',
                            );
                            setError(null);
                        }}
                        aria-labelledby={directionId}
                        className="gap-2"
                    >
                        <div className="flex items-center gap-2">
                            <RadioGroupItem
                                value="after"
                                id={`${directionId}-after`}
                            />
                            <Label
                                htmlFor={`${directionId}-after`}
                                className="font-normal"
                            >
                                {t('gantt.dependency.after', {
                                    task: task.title,
                                })}
                            </Label>
                        </div>
                        <div className="flex items-center gap-2">
                            <RadioGroupItem
                                value="before"
                                id={`${directionId}-before`}
                            />
                            <Label
                                htmlFor={`${directionId}-before`}
                                className="font-normal"
                            >
                                {t('gantt.dependency.before', {
                                    task: task.title,
                                })}
                            </Label>
                        </div>
                    </RadioGroup>
                </fieldset>

                <div className="grid gap-1.5">
                    <p id={listId} className="text-sm font-medium">
                        {t('gantt.dependency.other_task')}
                    </p>
                    <Command
                        className="rounded-md border"
                        label={t('gantt.dependency.other_task')}
                        filter={(value, search, keywords) => {
                            const haystack = normalize(
                                [value, ...(keywords ?? [])].join(' '),
                            );

                            return haystack.includes(normalize(search)) ? 1 : 0;
                        }}
                    >
                        <CommandInput
                            placeholder={t('gantt.dependency.search')}
                            aria-invalid={error ? true : undefined}
                            aria-describedby={error ? errorId : undefined}
                        />
                        <CommandList
                            label={t('gantt.dependency.other_task')}
                            className="max-h-56"
                        >
                            <CommandEmpty>
                                {t('gantt.dependency.empty')}
                            </CommandEmpty>
                            {others.map((candidate) => {
                                const already = linked.has(
                                    `${direction}-${candidate.id}`,
                                );

                                return (
                                    <CommandItem
                                        key={candidate.id}
                                        value={String(candidate.id)}
                                        keywords={[candidate.title]}
                                        disabled={already}
                                        onSelect={() => {
                                            setSelected(candidate.id);
                                            setError(null);
                                        }}
                                        data-checked={
                                            selected === candidate.id
                                                ? 'true'
                                                : undefined
                                        }
                                        data-test="gantt-dependency-option"
                                    >
                                        <Check
                                            aria-hidden="true"
                                            className={cn(
                                                'size-4',
                                                selected === candidate.id
                                                    ? 'opacity-100'
                                                    : 'opacity-0',
                                            )}
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {candidate.title}
                                            {selected === candidate.id ? (
                                                <span className="sr-only">
                                                    {' '}
                                                    {t(
                                                        'gantt.dependency.selected',
                                                    )}
                                                </span>
                                            ) : null}
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {already
                                                ? t('gantt.dependency.already')
                                                : formatRange(
                                                      candidate.start_date,
                                                      candidate.due_date,
                                                  )}
                                        </span>
                                    </CommandItem>
                                );
                            })}
                        </CommandList>
                    </Command>
                    {selected !== null && byId.has(selected) ? (
                        <p className="text-sm text-muted-foreground">
                            {direction === 'after'
                                ? t('gantt.dependency.summary_after', {
                                      task: task.title,
                                      other: titleOf(selected),
                                  })
                                : t('gantt.dependency.summary_before', {
                                      task: task.title,
                                      other: titleOf(selected),
                                  })}
                        </p>
                    ) : null}
                </div>

                {error ? (
                    <p
                        id={errorId}
                        role="alert"
                        className="flex items-start gap-1.5 text-sm text-destructive-foreground"
                    >
                        <CircleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        {error}
                    </p>
                ) : null}

                {own.length > 0 ? (
                    <div className="grid gap-2">
                        <p className="text-sm font-medium">
                            {t('gantt.dependency.existing')}
                        </p>
                        <ul className="grid gap-1">
                            {own.map((dependency) => (
                                <li
                                    key={dependency.id}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Link2
                                        aria-hidden="true"
                                        className="size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span className="min-w-0 flex-1 truncate">
                                        {t('gantt.menu.dependency_item', {
                                            predecessor: titleOf(
                                                dependency.predecessor_task_id,
                                            ),
                                            successor: titleOf(
                                                dependency.successor_task_id,
                                            ),
                                        })}
                                    </span>
                                    {onUnlink ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => onUnlink(dependency)}
                                            aria-label={t(
                                                'gantt.dependencies.remove',
                                                {
                                                    predecessor: titleOf(
                                                        dependency.predecessor_task_id,
                                                    ),
                                                    successor: titleOf(
                                                        dependency.successor_task_id,
                                                    ),
                                                },
                                            )}
                                        >
                                            <Unlink aria-hidden="true" />
                                            {t('gantt.dependency.remove')}
                                        </Button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onDone}
                        disabled={processing}
                    >
                        {t('gantt.dependency.cancel')}
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing ? <Spinner /> : null}
                        {t('gantt.dependency.submit')}
                    </Button>
                </DialogFooter>
            </form>
        </>
    );
}

function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}
