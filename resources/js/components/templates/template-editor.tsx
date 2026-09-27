import {
    ArrowDown,
    ArrowUp,
    ListPlus,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { memo, useId, useMemo } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { DurationInput } from '@/components/domain/duration-input';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
    isSubtask,
    moveRow,
    removeRow,
    rowLabels,
    rowsMeta,
    setParent,
    toggleDependency,
    updateRow,
} from './template-editor-state';
import {
    DependencyPicker,
    EditorRowsContext,
    ParentPicker,
    rowName,
} from './template-row-pickers';

/** Estimación máxima de una tarea: 999 h (TemplateStructure::MAX_ESTIMATE_MINUTES). */
export const MAX_ESTIMATE_MINUTES = 999 * 60;

/** Cambia las filas a partir de las actuales (el setState de la página). */
export type EditorChange = (update: (rows: EditorRow[]) => EditorRow[]) => void;

/**
 * Acciones de una fila. No cambian entre renders (trabajan sobre las filas actuales con
 * EditorChange), así que no obligan a volver a pintar las filas que no cambian.
 */
type RowActions = {
    update: (ref: string, changes: Parameters<typeof updateRow>[2]) => void;
    move: (ref: string, direction: 'up' | 'down') => void;
    setParent: (ref: string, parentRef: string | null) => void;
    toggleDependency: (ref: string, predecessor: string) => void;
    addSubtask: (ref: string) => void;
    remove: (ref: string) => void;
};

/** Sin errores: el mismo objeto siempre, para no romper la memoización de las filas. */
const NO_ERRORS: EditorErrors['rows'][string] = {};

/**
 * Editor de la estructura de una plantilla (D-058): tabla editable con una fila por tarea
 * (título, de qué tarea es subtarea, tipo, prioridad, estimación en h:mm, hito, día de inicio,
 * duración en días y de qué tareas depende), con subtareas de un solo nivel, sin ciclos y los
 * errores del servidor junto a cada campo. La tabla tiene su propio scroll horizontal.
 * Hasta 500 tareas: lo que cada fila necesita de las demás se calcula una vez por render
 * (rowsMeta), las filas están memoizadas con acciones estables (al escribir en una solo se vuelve
 * a pintar esa) y las listas de «Subtarea de» y «Depende de…» solo se pintan al abrirlas.
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
    onChange: EditorChange;
    errors: EditorErrors;
    types: TemplateTypeOption[];
    priorities: TaskPriority[];
    maxTasks: number;
    maxDays: number;
}) {
    const id = useId();
    const labels = useMemo(() => rowLabels(rows), [rows]);
    const meta = useMemo(() => rowsMeta(rows), [rows]);
    const byRef = useMemo(
        () => new Map(rows.map((row) => [row.ref, row])),
        [rows],
    );
    const context = useMemo(() => ({ rows, labels }), [rows, labels]);
    const full = rows.length >= maxTasks;
    const actions = useMemo<RowActions>(
        () => ({
            update: (ref, changes) =>
                onChange((current) => updateRow(current, ref, changes)),
            move: (ref, direction) =>
                onChange((current) => moveRow(current, ref, direction)),
            setParent: (ref, parentRef) =>
                onChange((current) => setParent(current, ref, parentRef)),
            toggleDependency: (ref, predecessor) =>
                onChange((current) =>
                    toggleDependency(current, ref, predecessor),
                ),
            addSubtask: (ref) =>
                onChange((current) => addSubtask(current, ref)),
            remove: (ref) => onChange((current) => removeRow(current, ref)),
        }),
        [onChange],
    );

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
                            <EditorRowsContext.Provider value={context}>
                                {rows.map((row, index) => {
                                    const info = meta.get(row.ref);
                                    const parent = info?.parent
                                        ? byRef.get(info.parent)
                                        : undefined;

                                    return (
                                        <EditorTableRow
                                            key={row.ref}
                                            id={`${id}-${index}`}
                                            row={row}
                                            label={info?.label ?? ''}
                                            canMoveUp={info?.canMoveUp ?? false}
                                            canMoveDown={
                                                info?.canMoveDown ?? false
                                            }
                                            subtasks={info?.children ?? 0}
                                            parentName={
                                                parent
                                                    ? rowName(
                                                          parent,
                                                          labels[parent.ref] ??
                                                              '',
                                                      )
                                                    : null
                                            }
                                            conflictName={
                                                info?.conflict
                                                    ? rowName(
                                                          info.conflict,
                                                          labels[
                                                              info.conflict.ref
                                                          ] ?? '',
                                                      )
                                                    : null
                                            }
                                            errors={
                                                errors.rows[row.ref] ??
                                                NO_ERRORS
                                            }
                                            types={types}
                                            priorities={priorities}
                                            maxDays={maxDays}
                                            full={full}
                                            actions={actions}
                                        />
                                    );
                                })}
                            </EditorRowsContext.Provider>
                        </tbody>
                    </table>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    disabled={full}
                    onClick={() => onChange(addRow)}
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

const EditorTableRow = memo(function EditorTableRow({
    id,
    row,
    label,
    canMoveUp,
    canMoveDown,
    subtasks,
    parentName,
    conflictName,
    errors,
    types,
    priorities,
    maxDays,
    full,
    actions,
}: {
    id: string;
    row: EditorRow;
    label: string;
    canMoveUp: boolean;
    canMoveDown: boolean;
    /** Subtareas que tiene (una tarea con subtareas no puede pasar a subtarea). */
    subtasks: number;
    /** «1. Diseño» si es subtarea. */
    parentName: string | null;
    /** Predecesora que acaba cuando esta ya ha empezado (D-057). */
    conflictName: string | null;
    errors: EditorErrors['rows'][string];
    types: TemplateTypeOption[];
    priorities: TaskPriority[];
    maxDays: number;
    full: boolean;
    actions: RowActions;
}) {
    const name = rowName(row, label);
    const subtask = isSubtask(row);
    const update = (changes: Parameters<typeof updateRow>[2]) =>
        actions.update(row.ref, changes);
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
                        {label}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        disabled={!canMoveUp}
                        aria-label={t('templates.editor.move_up', { name })}
                        onClick={() => actions.move(row.ref, 'up')}
                    >
                        <ArrowUp aria-hidden="true" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        disabled={!canMoveDown}
                        aria-label={t('templates.editor.move_down', { name })}
                        onClick={() => actions.move(row.ref, 'down')}
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
                        number: label,
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
                <ParentPicker
                    rowRef={row.ref}
                    name={name}
                    value={subtask ? (row.parent_ref as string) : null}
                    valueName={parentName}
                    disabled={!subtask && subtasks > 0}
                    invalid={errors.parent_ref ? true : undefined}
                    describedBy={
                        describedBy('parent_ref', errors.parent_ref) ??
                        (subtasks > 0 ? `${id}-parent-help` : undefined)
                    }
                    onSelect={(parentRef) =>
                        actions.setParent(row.ref, parentRef)
                    }
                />
                {subtasks > 0 ? (
                    <p
                        id={`${id}-parent-help`}
                        className="mt-1 text-xs text-muted-foreground"
                    >
                        {t('templates.editor.has_subtasks', {
                            count: subtasks,
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
                    rowRef={row.ref}
                    name={name}
                    count={row.depends_on.length}
                    invalid={errors.depends_on !== undefined}
                    describedBy={describedBy('depends_on', errors.depends_on)}
                    onToggle={(predecessor) =>
                        actions.toggleDependency(row.ref, predecessor)
                    }
                />
                {conflictName !== null ? (
                    <p className="mt-1 flex items-start gap-1 text-xs text-foreground">
                        <TriangleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0 text-warning"
                        />
                        {t('templates.editor.conflict', {
                            name: conflictName,
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
                            onClick={() => actions.addSubtask(row.ref)}
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
                            subtasks > 0
                                ? t('templates.editor.remove_with_subtasks', {
                                      name,
                                      count: subtasks,
                                  })
                                : t('templates.editor.remove', { name })
                        }
                        onClick={() => actions.remove(row.ref)}
                    >
                        <Trash2 aria-hidden="true" />
                    </Button>
                </div>
            </td>
        </tr>
    );
});
