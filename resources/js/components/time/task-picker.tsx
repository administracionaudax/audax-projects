import { Check, ChevronsUpDown, CircleCheck } from 'lucide-react';
import { useState } from 'react';
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
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LoggableTask } from '@/types';
import { groupByProject, useLoggableTasks } from './use-loggable-tasks';

/** Tarea elegida: la del buscador o, si llega de fuera, al menos id, título y proyecto. */
export type PickedTask = Pick<LoggableTask, 'id' | 'title' | 'project_id'> &
    Partial<Pick<LoggableTask, 'project' | 'is_billable' | 'is_completed'>>;

export function taskLabel(task: PickedTask): string {
    return task.project ? `${task.project.code} · ${task.title}` : task.title;
}

/**
 * Buscador de tareas donde se puede imputar (SPEC §7): miembro del proyecto o proyecto interno,
 * agrupadas por proyecto. Sin texto propone las tareas abiertas, las imputadas hace poco y las
 * internas. Accesible con teclado (cmdk).
 */
export function TaskPicker({
    value,
    onChange,
    userId,
    id,
    disabled,
    invalid,
    placeholder,
    className,
    'aria-describedby': describedBy,
    'aria-label': ariaLabel,
}: {
    value: PickedTask | null;
    onChange: (task: LoggableTask) => void;
    /** Persona para la que se imputa (si no es quien busca). */
    userId?: number;
    id?: string;
    disabled?: boolean;
    invalid?: boolean;
    placeholder?: string;
    className?: string;
    'aria-describedby'?: string;
    'aria-label'?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const { status, tasks } = useLoggableTasks(query, {
        userId,
        enabled: open,
    });
    const groups = groupByProject(tasks);

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    setQuery('');
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy}
                    aria-label={ariaLabel}
                    disabled={disabled}
                    className={cn(
                        'w-full justify-between font-normal',
                        !value && 'text-muted-foreground',
                        className,
                    )}
                >
                    <span className="truncate">
                        {value
                            ? taskLabel(value)
                            : (placeholder ??
                              t('hours.task_picker.placeholder'))}
                    </span>
                    <ChevronsUpDown aria-hidden="true" className="opacity-60" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-[min(28rem,calc(100vw-2rem))] p-0"
                align="start"
            >
                <Command shouldFilter={false}>
                    <CommandInput
                        value={query}
                        onValueChange={setQuery}
                        placeholder={t('hours.task_picker.search')}
                        aria-label={t('hours.task_picker.search')}
                    />
                    <CommandList label={t('hours.task_picker.results')}>
                        {status === 'loading' && tasks.length === 0 ? (
                            <div
                                className="flex items-center justify-center gap-2 py-6 text-sm text-muted-foreground"
                                role="status"
                            >
                                <Spinner />
                                {t('hours.task_picker.loading')}
                            </div>
                        ) : null}
                        {status === 'error' ? (
                            <p
                                role="alert"
                                className="py-6 text-center text-sm text-danger"
                            >
                                {t('hours.task_picker.error')}
                            </p>
                        ) : null}
                        {status === 'success' ? (
                            <CommandEmpty>
                                {query.trim() === ''
                                    ? t('hours.task_picker.empty_suggestions')
                                    : t('hours.task_picker.empty', {
                                          query: query.trim(),
                                      })}
                            </CommandEmpty>
                        ) : null}
                        {groups.map((group) => (
                            <CommandGroup
                                key={group.project.id}
                                heading={`${group.project.code} · ${group.project.name}`}
                            >
                                {group.tasks.map((task) => (
                                    <CommandItem
                                        key={task.id}
                                        value={String(task.id)}
                                        onSelect={() => {
                                            onChange(task);
                                            setOpen(false);
                                            setQuery('');
                                        }}
                                    >
                                        <span
                                            aria-hidden="true"
                                            className="size-2 shrink-0 rounded-full"
                                            style={{
                                                backgroundColor:
                                                    task.project.color,
                                            }}
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {task.title}
                                        </span>
                                        {task.is_completed ? (
                                            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                <CircleCheck
                                                    aria-hidden="true"
                                                    className="size-3.5 text-success"
                                                />
                                                {t(
                                                    'hours.task_picker.completed',
                                                )}
                                            </span>
                                        ) : null}
                                        {value?.id === task.id ? (
                                            <Check
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                        ) : null}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
