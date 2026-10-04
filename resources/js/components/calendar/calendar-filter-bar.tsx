import { SlidersHorizontal, X } from 'lucide-react';
import { useId, useState } from 'react';
import {
    countCalendarFilters,
    EMPTY_CALENDAR_FILTERS,
    hasCalendarFilters,
} from '@/components/calendar/calendar-query';
import { SearchField } from '@/components/my-tasks/my-task-filter-bar';
import { MultiSelectFilter } from '@/components/reports/multi-select-filter';
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
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskPriority } from '@/types';
import type {
    TeamCalendarFilters,
    TeamCalendarOptions,
} from '@/types/calendar';

const ALL = '__all';

const PRIORITIES: TaskPriority[] = ['low', 'normal', 'high', 'urgent'];

type Toggle = 'done' | 'milestones' | 'unassigned' | 'mine';

const TOGGLES: { key: Toggle; label: TranslationKey }[] = [
    { key: 'mine', label: 'team_calendar.filters.mine' },
    { key: 'unassigned', label: 'team_calendar.filters.unassigned' },
    { key: 'milestones', label: 'team_calendar.filters.milestones' },
    { key: 'done', label: 'team_calendar.filters.done' },
];

function ToggleField({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const id = useId();

    return (
        <div className="flex h-8 items-center gap-2">
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
            <Label htmlFor={id} className="font-normal">
                {label}
            </Label>
        </div>
    );
}

/**
 * Filtros del calendario del equipo (D-144): búsqueda, persona, proyecto, cliente y tipo (varios),
 * departamento, prioridad, «Solo las mías», «Sin asignar», «Solo hitos» e «Incluir hechas», y
 * «Limpiar filtros». Cada cambio va a la URL. En el móvil se pliegan tras un botón.
 */
export function CalendarFilterBar({
    filters,
    options,
    onChange,
}: {
    filters: TeamCalendarFilters;
    options: TeamCalendarOptions;
    onChange: (filters: TeamCalendarFilters) => void;
}) {
    const groupId = useId();
    const departmentId = useId();
    const priorityId = useId();
    const [open, setOpen] = useState(false);
    const set = (changes: Partial<TeamCalendarFilters>) =>
        onChange({ ...filters, ...changes });
    const active = countCalendarFilters(filters);

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
                    ? t('team_calendar.filters.toggle_active', {
                          count: active,
                      })
                    : t('team_calendar.filters.toggle')}
            </Button>
            <div
                id={groupId}
                role="group"
                aria-label={t('team_calendar.filters.label')}
                className={cn(
                    'flex-wrap items-end gap-3 sm:flex',
                    open ? 'flex flex-col items-stretch sm:flex-row' : 'hidden',
                )}
                data-test="team-filters"
            >
                <SearchField
                    value={filters.q}
                    onSearch={(q) => set({ q })}
                    label={t('team_calendar.filters.search')}
                    placeholder={t('team_calendar.filters.search_placeholder')}
                    testId="team-search"
                />
                <MultiSelectFilter
                    label={t('team_calendar.filters.person')}
                    options={options.people}
                    value={filters.persons}
                    onChange={(persons) => set({ persons })}
                />
                {options.departments.length > 0 ? (
                    <div className="grid gap-1">
                        <Label
                            htmlFor={departmentId}
                            className="text-xs text-muted-foreground"
                        >
                            {t('team_calendar.filters.department')}
                        </Label>
                        <Select
                            value={
                                filters.department === null
                                    ? ALL
                                    : String(filters.department)
                            }
                            onValueChange={(value) =>
                                set({
                                    department:
                                        value === ALL ? null : Number(value),
                                })
                            }
                        >
                            <SelectTrigger
                                id={departmentId}
                                size="sm"
                                className="w-full min-w-36 sm:w-44"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    {t('team_calendar.filters.all_departments')}
                                </SelectItem>
                                {options.departments.map((department) => (
                                    <SelectItem
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                ) : null}
                <MultiSelectFilter
                    label={t('team_calendar.filters.project')}
                    options={options.projects.map((project) => ({
                        id: project.id,
                        name: `${project.code} · ${project.name}`,
                    }))}
                    value={filters.projects}
                    onChange={(projects) => set({ projects })}
                />
                {options.clients.length > 0 ? (
                    <MultiSelectFilter
                        label={t('team_calendar.filters.client')}
                        options={options.clients}
                        value={filters.clients}
                        onChange={(clients) => set({ clients })}
                    />
                ) : null}
                <MultiSelectFilter
                    label={t('team_calendar.filters.type')}
                    options={options.types.map((type) => ({
                        id: type.id,
                        name: type.name,
                        muted: !type.is_active,
                    }))}
                    value={filters.types}
                    onChange={(types) => set({ types })}
                />
                <div className="grid gap-1">
                    <Label
                        htmlFor={priorityId}
                        className="text-xs text-muted-foreground"
                    >
                        {t('team_calendar.filters.priority')}
                    </Label>
                    <Select
                        value={filters.priority ?? ALL}
                        onValueChange={(value) =>
                            set({
                                priority:
                                    value === ALL
                                        ? null
                                        : (value as TaskPriority),
                            })
                        }
                    >
                        <SelectTrigger
                            id={priorityId}
                            size="sm"
                            className="w-full min-w-36 sm:w-40"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('team_calendar.filters.all_priorities')}
                            </SelectItem>
                            {PRIORITIES.map((priority) => (
                                <SelectItem key={priority} value={priority}>
                                    {t(`task.priority.${priority}`)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {TOGGLES.map((toggle) => (
                    <ToggleField
                        key={toggle.key}
                        label={t(toggle.label)}
                        checked={filters[toggle.key]}
                        onChange={(checked) => set({ [toggle.key]: checked })}
                    />
                ))}
                {hasCalendarFilters(filters) ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChange({ ...filters, ...EMPTY_CALENDAR_FILTERS })
                        }
                        data-test="team-clear"
                    >
                        <X aria-hidden="true" />
                        {t('team_calendar.filters.clear')}
                    </Button>
                ) : null}
            </div>
        </div>
    );
}
