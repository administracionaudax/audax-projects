import { Kanban, List, X } from 'lucide-react';
import { useId } from 'react';
import { NONE, PRIORITIES } from '@/components/tasks/task-fields';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { t } from '@/lib/i18n';
import type { TaskFilters, TaskGroupBy, TaskPriority, TaskView } from '@/types';

export const EMPTY_FILTERS: Omit<TaskFilters, 'group' | 'completed'> = {
    assignee: null,
    bank: null,
    type: null,
    priority: null,
    status: null,
    mine: false,
};

function FilterSelect({
    label,
    value,
    onChange,
    options,
    allLabel,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    allLabel: string;
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
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>{allLabel}</SelectItem>
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
 * Vista (lista o kanban), filtros (responsable, «mis tareas», bolsa, tipo, prioridad y estado),
 * mostrar las completadas y agrupar la lista. Todo queda en la URL.
 */
export function TaskToolbar({
    view,
    filters,
    onChange,
}: {
    view: TaskView;
    filters: TaskFilters;
    onChange: (view: TaskView, filters: TaskFilters) => void;
}) {
    const lookups = useTaskLookups();
    const mineId = useId();
    const completedId = useId();
    const groupId = useId();
    const viewLabelId = useId();
    const set = (changes: Partial<TaskFilters>) =>
        onChange(view, { ...filters, ...changes });
    const active =
        filters.assignee !== null ||
        filters.bank !== null ||
        filters.type !== null ||
        filters.priority !== null ||
        filters.status !== null ||
        filters.mine;
    const toId = (value: string): number | null =>
        value === NONE ? null : Number(value);

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <span id={viewLabelId} className="sr-only">
                        {t('task_filters.view')}
                    </span>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={view}
                        onValueChange={(next) => {
                            if (next === 'list' || next === 'kanban') {
                                onChange(next, filters);
                            }
                        }}
                        aria-labelledby={viewLabelId}
                    >
                        <ToggleGroupItem
                            value="list"
                            className="gap-1.5 px-3"
                            data-test="view-list"
                        >
                            <List aria-hidden="true" />
                            {t('task_filters.list')}
                        </ToggleGroupItem>
                        <ToggleGroupItem
                            value="kanban"
                            className="gap-1.5 px-3"
                            data-test="view-kanban"
                        >
                            <Kanban aria-hidden="true" />
                            {t('task_filters.kanban')}
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>
                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex items-center gap-2">
                        <Switch
                            id={mineId}
                            checked={filters.mine}
                            onCheckedChange={(checked) =>
                                set({ mine: checked })
                            }
                        />
                        <Label htmlFor={mineId} className="font-normal">
                            {t('task_filters.mine')}
                        </Label>
                    </div>
                    <div className="flex items-center gap-2">
                        <Switch
                            id={completedId}
                            checked={filters.completed}
                            onCheckedChange={(checked) =>
                                set({ completed: checked })
                            }
                        />
                        <Label htmlFor={completedId} className="font-normal">
                            {t('task_filters.show_completed')}
                        </Label>
                    </div>
                </div>
            </div>
            <div
                className="flex flex-wrap items-end gap-3"
                role="group"
                aria-label={t('task_filters.label')}
            >
                <FilterSelect
                    label={t('task_filters.assignee')}
                    allLabel={t('task_filters.all_people')}
                    value={
                        filters.assignee === null
                            ? NONE
                            : String(filters.assignee)
                    }
                    onChange={(value) =>
                        set({
                            assignee:
                                value === NONE
                                    ? null
                                    : value === 'none'
                                      ? 'none'
                                      : Number(value),
                        })
                    }
                    options={[
                        { value: 'none', label: t('task_fields.no_assignee') },
                        ...lookups.users.map((user) => ({
                            value: String(user.id),
                            label: user.name,
                        })),
                    ]}
                />
                {lookups.usesBanks || lookups.banks.length > 0 ? (
                    <FilterSelect
                        label={t('task_filters.bank')}
                        allLabel={t('task_filters.all_banks')}
                        value={
                            filters.bank === null ? NONE : String(filters.bank)
                        }
                        onChange={(value) => set({ bank: toId(value) })}
                        options={lookups.banks.map((bank) => ({
                            value: String(bank.id),
                            label: bank.name,
                        }))}
                    />
                ) : null}
                <FilterSelect
                    label={t('task_filters.type')}
                    allLabel={t('task_filters.all_types')}
                    value={filters.type === null ? NONE : String(filters.type)}
                    onChange={(value) => set({ type: toId(value) })}
                    options={lookups.types.map((type) => ({
                        value: String(type.id),
                        label: type.name,
                    }))}
                />
                <FilterSelect
                    label={t('task_filters.priority')}
                    allLabel={t('task_filters.all_priorities')}
                    value={filters.priority ?? NONE}
                    onChange={(value) =>
                        set({
                            priority:
                                value === NONE ? null : (value as TaskPriority),
                        })
                    }
                    options={PRIORITIES.map((priority) => ({
                        value: priority,
                        label: t(`task.priority.${priority}`),
                    }))}
                />
                <FilterSelect
                    label={t('task_filters.status')}
                    allLabel={t('task_filters.all_statuses')}
                    value={
                        filters.status === null ? NONE : String(filters.status)
                    }
                    onChange={(value) => set({ status: toId(value) })}
                    options={lookups.statuses.map((status) => ({
                        value: String(status.id),
                        label: status.name,
                    }))}
                />
                {view === 'list' ? (
                    <div className="grid gap-1">
                        <Label
                            htmlFor={groupId}
                            className="text-xs text-muted-foreground"
                        >
                            {t('task_filters.group')}
                        </Label>
                        <Select
                            value={filters.group}
                            onValueChange={(value) =>
                                set({ group: value as TaskGroupBy })
                            }
                        >
                            <SelectTrigger
                                id={groupId}
                                size="sm"
                                className="w-full min-w-36 sm:w-44"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {(
                                    [
                                        'status',
                                        'assignee',
                                        'bank',
                                        'type',
                                        'none',
                                    ] as const
                                )
                                    .filter(
                                        (group) =>
                                            group !== 'bank' ||
                                            lookups.usesBanks,
                                    )
                                    .map((group) => (
                                        <SelectItem key={group} value={group}>
                                            {t(
                                                `task_filters.group_by.${group}`,
                                            )}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    </div>
                ) : null}
                {active ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChange(view, { ...filters, ...EMPTY_FILTERS })
                        }
                    >
                        <X aria-hidden="true" />
                        {t('task_filters.clear')}
                    </Button>
                ) : null}
            </div>
        </div>
    );
}
