import { Search, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import {
    countMyTaskFilters,
    DUE_OPTIONS,
    EMPTY_MY_TASK_FILTERS,
    hasMyTaskFilters,
    SORTS,
} from '@/components/my-tasks/my-task-query';
import { MultiSelectFilter } from '@/components/reports/multi-select-filter';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    MyTaskDue,
    MyTaskFilters,
    MyTaskOptions,
    MyTaskSort,
    TaskPriority,
    TaskStatus,
} from '@/types';

const ALL = '__all';

/** Espera tras la última tecla antes de buscar. */
export const SEARCH_DELAY_MS = 400;

/**
 * Orden de Mis tareas (D-143): «Imputadas recientemente» por defecto, o vencimiento (con las
 * secciones Vencidas, Hoy…), prioridad, proyecto, creación y actualización.
 */
export function MyTaskSortSelect({
    value,
    onChange,
}: {
    value: MyTaskSort;
    onChange: (sort: MyTaskSort) => void;
}) {
    const id = useId();

    return (
        <div className="grid gap-1">
            <Label htmlFor={id} className="text-xs text-muted-foreground">
                {t('my_tasks.sort.label')}
            </Label>
            <Select
                value={value}
                onValueChange={(next) => onChange(next as MyTaskSort)}
            >
                <SelectTrigger
                    id={id}
                    size="sm"
                    className="w-full min-w-48 sm:w-56"
                    data-test="my-tasks-sort"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {SORTS.map((sort) => (
                        <SelectItem key={sort} value={sort}>
                            {t(`my_tasks.sort.${sort}`)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

/** Búsqueda que se aplica al pulsar Intro o al dejar de escribir (SEARCH_DELAY_MS). */
function SearchField({
    value,
    onSearch,
}: {
    value: string | null;
    onSearch: (text: string | null) => void;
}) {
    const id = useId();
    const [text, setText] = useState(value ?? '');
    const [source, setSource] = useState(value);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Si la búsqueda cambia desde fuera (limpiar filtros, atrás en el navegador), se sigue.
    if (source !== value) {
        setSource(value);
        setText(value ?? '');
    }

    useEffect(
        () => () => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    const apply = (next: string) => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }

        const trimmed = next.trim();

        if (trimmed !== (value ?? '')) {
            onSearch(trimmed === '' ? null : trimmed);
        }
    };

    return (
        <div className="grid min-w-0 gap-1 sm:w-72">
            <Label htmlFor={id} className="text-xs text-muted-foreground">
                {t('my_tasks.filters.search')}
            </Label>
            <div className="relative">
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    id={id}
                    type="search"
                    value={text}
                    maxLength={100}
                    autoComplete="off"
                    placeholder={t('my_tasks.filters.search_placeholder')}
                    className="h-8 pl-8"
                    onChange={(event) => {
                        const next = event.target.value;
                        setText(next);

                        if (timer.current !== null) {
                            clearTimeout(timer.current);
                        }

                        timer.current = setTimeout(
                            () => apply(next),
                            SEARCH_DELAY_MS,
                        );
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            apply(text);
                        }
                    }}
                    data-test="my-tasks-search"
                />
            </div>
        </div>
    );
}

function LabeledSelect({
    label,
    value,
    onChange,
    options,
    allLabel,
    testId,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    allLabel: string;
    testId?: string;
}) {
    const id = useId();

    return (
        <div className="grid gap-1">
            <Label htmlFor={id} className="text-xs text-muted-foreground">
                {label}
            </Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger
                    id={id}
                    size="sm"
                    className="w-full min-w-36 sm:w-44"
                    data-test={testId}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>{allLabel}</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

/**
 * Barra de filtros de Mis tareas (D-143): búsqueda (título, código de proyecto o cliente),
 * proyecto, cliente y estado (varios), tipo (varios), prioridad, vencimiento (vencidas, hoy, esta
 * semana, sin fecha o entre fechas), «Incluir hechas» y «Limpiar filtros». Cada cambio va a la URL
 * (la página hace la visita). En el móvil se pliega tras un botón, como en las tareas del proyecto.
 */
export function MyTaskFilterBar({
    filters,
    options,
    statuses,
    onChange,
}: {
    filters: MyTaskFilters;
    options: MyTaskOptions;
    statuses: TaskStatus[];
    onChange: (filters: MyTaskFilters) => void;
}) {
    const doneId = useId();
    const groupId = useId();
    const [open, setOpen] = useState(false);
    const set = (changes: Partial<MyTaskFilters>) =>
        onChange({ ...filters, ...changes });
    const active = countMyTaskFilters(filters);

    return (
        <div className="flex flex-col gap-3">
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="w-fit sm:hidden"
                aria-expanded={open}
                aria-controls={groupId}
                onClick={() => setOpen((value) => !value)}
            >
                <SlidersHorizontal aria-hidden="true" />
                {active > 0
                    ? t('my_tasks.filters.toggle_active', { count: active })
                    : t('my_tasks.filters.toggle')}
            </Button>
            <div
                id={groupId}
                role="group"
                aria-label={t('my_tasks.filters.label')}
                className={cn(
                    'flex-wrap items-end gap-3 sm:flex',
                    open ? 'flex flex-col items-stretch sm:flex-row' : 'hidden',
                )}
                data-test="my-tasks-filters"
            >
                <SearchField value={filters.q} onSearch={(q) => set({ q })} />
                <MultiSelectFilter
                    label={t('my_tasks.filters.project')}
                    options={options.projects.map((project) => ({
                        id: project.id,
                        name: `${project.code} · ${project.name}`,
                    }))}
                    value={filters.projects}
                    onChange={(projects) => set({ projects })}
                />
                {options.clients.length > 0 ? (
                    <MultiSelectFilter
                        label={t('my_tasks.filters.client')}
                        options={options.clients}
                        value={filters.clients}
                        onChange={(clients) => set({ clients })}
                    />
                ) : null}
                <MultiSelectFilter
                    label={t('my_tasks.filters.status')}
                    options={statuses.map((status) => ({
                        id: status.id,
                        name: status.name,
                    }))}
                    value={filters.statuses}
                    onChange={(statusIds) => set({ statuses: statusIds })}
                />
                <MultiSelectFilter
                    label={t('my_tasks.filters.type')}
                    options={options.types.map((type) => ({
                        id: type.id,
                        name: type.name,
                        muted: !type.is_active,
                    }))}
                    value={filters.types}
                    onChange={(types) => set({ types })}
                />
                <LabeledSelect
                    label={t('my_tasks.filters.priority')}
                    allLabel={t('my_tasks.filters.all_priorities')}
                    value={filters.priority ?? ALL}
                    onChange={(value) =>
                        set({
                            priority:
                                value === ALL ? null : (value as TaskPriority),
                        })
                    }
                    options={options.priorities.map((priority) => ({
                        value: priority,
                        label: t(`task.priority.${priority}`),
                    }))}
                    testId="my-tasks-priority"
                />
                <LabeledSelect
                    label={t('my_tasks.filters.due')}
                    allLabel={t('my_tasks.filters.all_due')}
                    value={filters.due ?? ALL}
                    onChange={(value) =>
                        set({
                            due: value === ALL ? null : (value as MyTaskDue),
                            from: null,
                            to: null,
                        })
                    }
                    options={DUE_OPTIONS.map((due) => ({
                        value: due,
                        label: t(`my_tasks.filters.due_option.${due}`),
                    }))}
                    testId="my-tasks-due"
                />
                {filters.due === 'range' ? (
                    <>
                        <div className="grid gap-1">
                            <span className="text-xs text-muted-foreground">
                                {t('my_tasks.filters.from')}
                            </span>
                            <DatePicker
                                value={filters.from}
                                onChange={(from) => set({ from })}
                                aria-label={t('my_tasks.filters.from')}
                                className="h-8"
                            />
                        </div>
                        <div className="grid gap-1">
                            <span className="text-xs text-muted-foreground">
                                {t('my_tasks.filters.to')}
                            </span>
                            <DatePicker
                                value={filters.to}
                                onChange={(to) => set({ to })}
                                aria-label={t('my_tasks.filters.to')}
                                className="h-8"
                            />
                        </div>
                    </>
                ) : null}
                <div className="flex h-8 items-center gap-2">
                    <Switch
                        id={doneId}
                        checked={filters.done}
                        onCheckedChange={(done) => set({ done })}
                    />
                    <Label htmlFor={doneId} className="font-normal">
                        {t('my_tasks.filters.include_done')}
                    </Label>
                </div>
                {hasMyTaskFilters(filters) ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChange({
                                ...EMPTY_MY_TASK_FILTERS,
                                sort: filters.sort,
                            })
                        }
                        data-test="my-tasks-clear"
                    >
                        <X aria-hidden="true" />
                        {t('my_tasks.filters.clear')}
                    </Button>
                ) : null}
            </div>
        </div>
    );
}
