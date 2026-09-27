import { X } from 'lucide-react';
import { useId } from 'react';
import { DEFAULT_FILTERS, filtersQuery } from '@/components/gantt/preferences';
import type { GanttFilters } from '@/components/gantt/types';
import { FilterSelect } from '@/components/projects-list/filter-select';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { Option, ProjectStatus } from '@/types';

const STATUSES: ProjectStatus[] = [
    'active',
    'planned',
    'on_hold',
    'completed',
    'archived',
];

function toOptions(options: ReadonlyArray<Option>) {
    return options.map((option) => ({
        value: String(option.id),
        label: option.name,
    }));
}

/**
 * Filtros del Gantt multiproyecto (SPEC §6.1): cliente, departamento implicado, responsable
 * (gestor principal) y estado del proyecto (por defecto, activos). Todo va en la URL.
 */
export function GanttFiltersBar({
    filters,
    options,
    onChange,
}: {
    filters: GanttFilters;
    options: { clients: Option[]; owners: Option[]; departments: Option[] };
    onChange: (filters: GanttFilters) => void;
}) {
    const id = useId();
    const toId = (value: string | null) =>
        value === null ? null : Number(value);
    const active = Object.keys(filtersQuery(filters)).length > 0;

    return (
        <div
            role="search"
            aria-label={t('gantt.filters.label')}
            className="grid gap-3 rounded-md border bg-card p-3"
        >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FilterSelect
                    id={`${id}-client`}
                    label={t('gantt.filters.client')}
                    allLabel={t('gantt.filters.all_clients')}
                    value={
                        filters.cliente === null
                            ? null
                            : String(filters.cliente)
                    }
                    options={toOptions(options.clients)}
                    onChange={(value) =>
                        onChange({ ...filters, cliente: toId(value) })
                    }
                />
                <FilterSelect
                    id={`${id}-department`}
                    label={t('gantt.filters.department')}
                    allLabel={t('gantt.filters.all_departments')}
                    value={
                        filters.departamento === null
                            ? null
                            : String(filters.departamento)
                    }
                    options={toOptions(options.departments)}
                    onChange={(value) =>
                        onChange({ ...filters, departamento: toId(value) })
                    }
                />
                <FilterSelect
                    id={`${id}-owner`}
                    label={t('gantt.filters.owner')}
                    allLabel={t('gantt.filters.all_owners')}
                    value={
                        filters.responsable === null
                            ? null
                            : String(filters.responsable)
                    }
                    options={toOptions(options.owners)}
                    onChange={(value) =>
                        onChange({ ...filters, responsable: toId(value) })
                    }
                />
                <FilterSelect
                    id={`${id}-status`}
                    label={t('gantt.filters.status')}
                    value={filters.estado}
                    options={[
                        ...STATUSES.map((status) => ({
                            value: status,
                            label: t(`project.status.${status}`),
                        })),
                        {
                            value: 'sin-archivar',
                            label: t('gantt.filters.not_archived'),
                        },
                        {
                            value: 'todos',
                            label: t('gantt.filters.all_statuses'),
                        },
                    ]}
                    onChange={(value) =>
                        onChange({
                            ...filters,
                            estado: (value ??
                                DEFAULT_FILTERS.estado) as GanttFilters['estado'],
                        })
                    }
                />
            </div>
            {active ? (
                <div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onChange(DEFAULT_FILTERS)}
                    >
                        <X aria-hidden="true" />
                        {t('gantt.filters.clear')}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
