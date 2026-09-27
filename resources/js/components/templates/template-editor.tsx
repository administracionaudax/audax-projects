import {
    ArrowDown,
    ArrowUp,
    Link2,
    ListPlus,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useId, useState } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskPriority } from '@/types';
import type { TemplateTypeOption } from '@/types/templates';
import { IntegerInput } from './integer-input';
import type { EditorErrors, EditorRow } from './template-editor-state';
import {
    addRow,
    addSubtask,
    canBeSubtask,
    canMove,
    childrenOf,
    conflictsOf,
    isSubtask,
    moveRow,
    parentOptions,
    removeRow,
    setParent,
    toggleDependency,
    updateRow,
    wouldCreateCycle,
} from './template-editor-state';

/** Estimación máxima de una tarea: 999 h (TemplateStructure::MAX_ESTIMATE_MINUTES). */
export const MAX_ESTIMATE_MINUTES = 999 * 60;

/** Número de cada fila tal y como se ve: «1», «2», «2.1»… */
export function rowLabels(rows: EditorRow[]): Record<string, string> {
    const labels: Record<string, string> = {};
    let root = 0;
    const childCount: Record<string, number> = {};

    for (const row of rows) {
        if (isSubtask(row) && labels[row.parent_ref as string]) {
            const parent = row.parent_ref as string;
            childCount[parent] = (childCount[parent] ?? 0) + 1;
            labels[row.ref] = `${labels[parent]}.${childCount[parent]}`;
        } else {
            root++;
            labels[row.ref] = String(root);
        }
    }

    return labels;
}

function rowName(row: EditorRow, labels: Record<string, string>): string {
    return t('templates.editor.row_name', {
        number: labels[row.ref] ?? '',
        title: row.title.trim() || t('templates.editor.untitled'),
    });
}

/**
 * Editor de la estructura de una plantilla (D-058): tabla editable con una fila por tarea
 * (título, de qué tarea es subtarea, tipo, prioridad, estimación en h:mm, hito, día de inicio,
 * duración en días y de qué tareas depende), con subtareas de un solo nivel, sin ciclos y los
 * errores del servidor junto a cada campo. La tabla tiene su propio scroll horizontal.
 */
export function TemplateEditor({
    rows,
    onChange,
    errors,
    types,
    priorities,
    maxTasks,
    maxDays,
}: {
    rows: EditorRow[];
    onChange: (rows: EditorRow[]) => void;
    errors: EditorErrors;
    types: TemplateTypeOption[];
    priorities: TaskPriority[];
    maxTasks: number;
    maxDays: number;
}) {
    const id = useId();
    const labels = rowLabels(rows);
    const full = rows.length >= maxTasks;

    return (
        <div className="grid gap-3">
            {errors.general.length > 0 ? (
                <div role="alert" className="grid gap-1">
                    {errors.general.map((message) => (
                        <p
                            key={message}
                            className="flex items-start gap-2 text-sm text-danger"
                        >
                            <TriangleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0"
                            />
                            {message}
                        </p>
                    ))}
                </div>
            ) : null}

            {rows.length === 0 ? (
                <p className="rounded-md border border-dashed bg-muted/60 p-4 text-sm text-muted-foreground">
                    {t('templates.editor.empty')}
                </p>
            ) : (
                <div
                    role="region"
                    aria-label={t('templates.editor.table_label')}
                    tabIndex={0}
                    className={cn(
                        'overflow-x-auto rounded-md border',
                        FOCUS_RING,
                    )}
                >
                    <table className="w-full min-w-[78rem] text-sm">
                        <caption className="sr-only">
                            {t('templates.editor.table_label')}
                        </caption>
                        <thead>
                            <tr className="border-b bg-muted text-left">
                                <th
                                    scope="col"
                                    className="w-24 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.order')}
                                </th>
                                <th
                                    scope="col"
                                    className="min-w-56 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.title')}
                                </th>
                                <th
                                    scope="col"
                                    className="w-44 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.parent')}
                                </th>
                                <th
                                    scope="col"
                                    className="w-40 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.type')}
                                </th>
                                <th
                                    scope="col"
                                    className="w-32 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.priority')}
                                </th>
                                <th
                                    scope="col"
                                    className="w-28 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.estimate')}
                                </th>
                                <th
                                    scope="col"
                                    className="w-16 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.milestone')}
                                </th>
                                <th
                                    scope="col"
                                    className="w-24 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.start_day')}
                                </th>
                                <th
                                    scope="col"
                                    className="w-24 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.duration')}
                                </th>
                                <th
                                    scope="col"
                                    className="w-44 px-2 py-2 font-medium"
                                >
                                    {t('templates.editor.depends_on')}
                                </th>
                                <th
                                    scope="col"
                                    className="w-24 px-2 py-2 text-right font-medium"
                                >
                                    <span className="sr-only">
                                        {t('common.actions')}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <EditorTableRow
                                    key={row.ref}
                                    id={`${id}-${row.ref}`}
                                    row={row}
                                    rows={rows}
                                    labels={labels}
                                    errors={errors.rows[row.ref] ?? {}}
                                    types={types}
                                    priorities={priorities}
                                    maxDays={maxDays}
                                    full={full}
                                    onChange={onChange}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    disabled={full}
                    onClick={() => onChange(addRow(rows))}
                >
                    <Plus aria-hidden="true" />
                    {t('templates.editor.add_task')}
                </Button>
                <p className="text-sm text-muted-foreground" aria-live="polite">
                    {full
                        ? t('templates.editor.full', { max: maxTasks })
                        : t('templates.editor.count', {
                              count: rows.length,
                              max: maxTasks,
                          })}
                </p>
            </div>
        </div>
    );
}

function EditorTableRow({
    id,
    row,
    rows,
    labels,
    errors,
    types,
    priorities,
    maxDays,
    full,
    onChange,
}: {
    id: string;
    row: EditorRow;
    rows: EditorRow[];
    labels: Record<string, string>;
    errors: EditorErrors['rows'][string];
    types: TemplateTypeOption[];
    priorities: TaskPriority[];
    maxDays: number;
    full: boolean;
    onChange: (rows: EditorRow[]) => void;
}) {
    const name = rowName(row, labels);
    const subtask = isSubtask(row);
    const children = childrenOf(rows, row.ref).length;
    const conflicts = conflictsOf(rows, row.ref);
    const update = (changes: Parameters<typeof updateRow>[2]) =>
        onChange(updateRow(rows, row.ref, changes));
    const describedBy = (field: string, error?: string) =>
        error ? `${id}-${field}-error` : undefined;

    return (
        <tr
            className="border-b align-top last:border-b-0 even:bg-muted/40"
            data-test="template-row"
        >
            <td className="px-2 py-2">
                <div className="flex items-center gap-1">
                    <span
                        className={cn(
                            'min-w-8 text-muted-foreground tabular-nums',
                            subtask && 'pl-3',
                        )}
                    >
                        {labels[row.ref]}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        disabled={!canMove(rows, row.ref, 'up')}
                        aria-label={t('templates.editor.move_up', { name })}
                        onClick={() => onChange(moveRow(rows, row.ref, 'up'))}
                    >
                        <ArrowUp aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        disabled={!canMove(rows, row.ref, 'down')}
                        aria-label={t('templates.editor.move_down', { name })}
                        onClick={() => onChange(moveRow(rows, row.ref, 'down'))}
                    >
                        <ArrowDown aria-hidden="true" />
                    </Button>
                </div>
            </td>
            <td className={cn('px-2 py-2', subtask && 'pl-6')}>
                <Input
                    value={row.title}
                    maxLength={255}
                    required
                    placeholder={t('templates.editor.title_placeholder')}
                    aria-label={t('templates.editor.title_label', {
                        number: labels[row.ref] ?? '',
                    })}
                    aria-invalid={errors.title ? true : undefined}
                    aria-describedby={describedBy('title', errors.title)}
                    onChange={(event) => update({ title: event.target.value })}
                />
                <InputError
                    id={`${id}-title-error`}
                    message={errors.title ?? errors.ref}
                    className="mt-1"
                />
            </td>
            <td className="px-2 py-2">
                <NativeSelect
                    value={row.parent_ref ?? ''}
                    disabled={!subtask && !canBeSubtask(rows, row.ref)}
                    aria-label={t('templates.editor.parent_label', { name })}
                    aria-invalid={errors.parent_ref ? true : undefined}
                    aria-describedby={
                        describedBy('parent_ref', errors.parent_ref) ??
                        (children > 0 ? `${id}-parent-help` : undefined)
                    }
                    onChange={(event) =>
                        onChange(
                            setParent(
                                rows,
                                row.ref,
                                event.target.value || null,
                            ),
                        )
                    }
                >
                    <option value="">{t('templates.editor.top_level')}</option>
                    {parentOptions(rows, row.ref).map((option) => (
                        <option key={option.ref} value={option.ref}>
                            {rowName(option, labels)}
                        </option>
                    ))}
                </NativeSelect>
                {children > 0 ? (
                    <p
                        id={`${id}-parent-help`}
                        className="mt-1 text-xs text-muted-foreground"
                    >
                        {t('templates.editor.has_subtasks', {
                            count: children,
                        })}
                    </p>
                ) : null}
                <InputError
                    id={`${id}-parent_ref-error`}
                    message={errors.parent_ref}
                    className="mt-1"
                />
            </td>
            <td className="px-2 py-2">
                <NativeSelect
                    value={
                        row.task_type_id === null
                            ? ''
                            : String(row.task_type_id)
                    }
                    aria-label={t('templates.editor.type_label', { name })}
                    aria-invalid={errors.task_type_id ? true : undefined}
                    aria-describedby={describedBy(
                        'task_type_id',
                        errors.task_type_id,
                    )}
                    onChange={(event) =>
                        update({
                            task_type_id:
                                event.target.value === ''
                                    ? null
                                    : Number(event.target.value),
                        })
                    }
                >
                    <option value="">{t('templates.editor.no_type')}</option>
                    {types
                        .filter(
                            (type) =>
                                type.is_active || type.id === row.task_type_id,
                        )
                        .map((type) => (
                            <option key={type.id} value={String(type.id)}>
                                {type.is_active
                                    ? type.name
                                    : t('templates.editor.inactive_type', {
                                          name: type.name,
                                      })}
                            </option>
                        ))}
                </NativeSelect>
                <InputError
                    id={`${id}-task_type_id-error`}
                    message={errors.task_type_id}
                    className="mt-1"
                />
            </td>
            <td className="px-2 py-2">
                <NativeSelect
                    value={row.priority}
                    aria-label={t('templates.editor.priority_label', { name })}
                    onChange={(event) =>
                        update({ priority: event.target.value as TaskPriority })
                    }
                >
                    {priorities.map((priority) => (
                        <option key={priority} value={priority}>
                            {t(`task.priority.${priority}`)}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={errors.priority} className="mt-1" />
            </td>
            <td className="px-2 py-2">
                {row.is_milestone ? (
                    <p className="py-2 text-xs text-muted-foreground">
                        {t('templates.editor.milestone_no_hours')}
                    </p>
                ) : (
                    <DurationInput
                        value={row.estimated_minutes}
                        onChange={(minutes) =>
                            update({ estimated_minutes: minutes })
                        }
                        max={MAX_ESTIMATE_MINUTES}
                        placeholder={t('templates.editor.estimate_placeholder')}
                        invalid={errors.estimated_minutes ? true : undefined}
                        aria-label={t('templates.editor.estimate_label', {
                            name,
                        })}
                        aria-describedby={describedBy(
                            'estimated_minutes',
                            errors.estimated_minutes,
                        )}
                    />
                )}
                <InputError
                    id={`${id}-estimated_minutes-error`}
                    message={errors.estimated_minutes}
                />
            </td>
            <td className="px-2 py-3">
                <Checkbox
                    checked={row.is_milestone}
                    aria-label={t('templates.editor.milestone_label', { name })}
                    onCheckedChange={(checked) =>
                        update({ is_milestone: checked === true })
                    }
                />
            </td>
            <td className="px-2 py-2">
                <IntegerInput
                    min={1}
                    max={maxDays + 1}
                    value={row.start_offset_days + 1}
                    aria-label={t('templates.editor.start_day_label', { name })}
                    aria-invalid={errors.start_offset_days ? true : undefined}
                    aria-describedby={describedBy(
                        'start_offset_days',
                        errors.start_offset_days,
                    )}
                    onChange={(day) => update({ start_offset_days: day - 1 })}
                />
                <InputError
                    id={`${id}-start_offset_days-error`}
                    message={errors.start_offset_days}
                    className="mt-1"
                />
            </td>
            <td className="px-2 py-2">
                {row.is_milestone ? (
                    <p className="py-2 text-xs text-muted-foreground">
                        {t('templates.editor.milestone_one_day')}
                    </p>
                ) : (
                    <IntegerInput
                        min={1}
                        max={maxDays}
                        value={row.duration_days}
                        aria-label={t('templates.editor.duration_label', {
                            name,
                        })}
                        aria-invalid={errors.duration_days ? true : undefined}
                        aria-describedby={describedBy(
                            'duration_days',
                            errors.duration_days,
                        )}
                        onChange={(days) => update({ duration_days: days })}
                    />
                )}
                <InputError
                    id={`${id}-duration_days-error`}
                    message={errors.duration_days}
                    className="mt-1"
                />
            </td>
            <td className="px-2 py-2">
                <DependencyPicker
                    row={row}
                    rows={rows}
                    labels={labels}
                    name={name}
                    invalid={errors.depends_on !== undefined}
                    describedBy={describedBy('depends_on', errors.depends_on)}
                    onToggle={(predecessor) =>
                        onChange(toggleDependency(rows, row.ref, predecessor))
                    }
                />
                {conflicts.length > 0 ? (
                    <p className="mt-1 flex items-start gap-1 text-xs text-foreground">
                        <TriangleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0 text-warning"
                        />
                        {t('templates.editor.conflict', {
                            name: rowName(conflicts[0], labels),
                        })}
                    </p>
                ) : null}
                <InputError
                    id={`${id}-depends_on-error`}
                    message={errors.depends_on}
                    className="mt-1"
                />
            </td>
            <td className="px-2 py-2">
                <div className="flex justify-end gap-1">
                    {!subtask ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            disabled={full}
                            aria-label={t('templates.editor.add_subtask', {
                                name,
                            })}
                            onClick={() => onChange(addSubtask(rows, row.ref))}
                        >
                            <ListPlus aria-hidden="true" />
                        </Button>
                    ) : null}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={
                            children > 0
                                ? t('templates.editor.remove_with_subtasks', {
                                      name,
                                      count: children,
                                  })
                                : t('templates.editor.remove', { name })
                        }
                        onClick={() => onChange(removeRow(rows, row.ref))}
                    >
                        <Trash2 aria-hidden="true" />
                    </Button>
                </div>
            </td>
        </tr>
    );
}

/**
 * «Depende de…»: casillas con las demás tareas. Las que crearían un ciclo aparecen desactivadas y
 * lo dicen (nunca solo con color).
 */
function DependencyPicker({
    row,
    rows,
    labels,
    name,
    invalid,
    describedBy,
    onToggle,
}: {
    row: EditorRow;
    rows: EditorRow[];
    labels: Record<string, string>;
    name: string;
    invalid: boolean;
    describedBy?: string;
    onToggle: (predecessor: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const others = rows.filter((other) => other.ref !== row.ref);
    const selected = row.depends_on.length;
    const value =
        selected === 0
            ? t('templates.editor.no_dependencies')
            : t('templates.editor.dependencies_count', { count: selected });

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className="w-full justify-start font-normal"
                    aria-label={t('templates.editor.depends_on_label', {
                        name,
                        value,
                    })}
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy}
                >
                    <Link2 aria-hidden="true" />
                    {value}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80 p-0">
                <fieldset className="grid max-h-72 gap-1 overflow-y-auto p-3">
                    <legend className="mb-2 text-sm font-medium">
                        {t('templates.editor.depends_on_legend', { name })}
                    </legend>
                    {others.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('templates.editor.no_other_tasks')}
                        </p>
                    ) : (
                        others.map((other) => {
                            const checked = row.depends_on.includes(other.ref);
                            const cycle =
                                !checked &&
                                wouldCreateCycle(rows, other.ref, row.ref);
                            const optionId = `dep-${row.ref}-${other.ref}`;

                            return (
                                <div
                                    key={other.ref}
                                    className="flex items-start gap-2 py-1"
                                >
                                    <Checkbox
                                        id={optionId}
                                        checked={checked}
                                        disabled={cycle}
                                        onCheckedChange={() =>
                                            onToggle(other.ref)
                                        }
                                    />
                                    <label
                                        htmlFor={optionId}
                                        className={cn(
                                            'text-sm leading-tight',
                                            cycle && 'text-muted-foreground',
                                        )}
                                    >
                                        {rowName(other, labels)}
                                        {cycle ? (
                                            <span className="block text-xs">
                                                {t(
                                                    'templates.editor.would_cycle',
                                                )}
                                            </span>
                                        ) : null}
                                    </label>
                                </div>
                            );
                        })
                    )}
                </fieldset>
            </PopoverContent>
        </Popover>
    );
}
