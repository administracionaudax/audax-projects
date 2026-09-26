import { Search, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { FilterSelect } from '@/components/projects-list/filter-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import type {
    BillingType,
    Option,
    ProjectListFilters,
    ProjectStatus,
} from '@/types';

const STATUSES: ProjectStatus[] = [
    'planned',
    'active',
    'on_hold',
    'completed',
    'archived',
];

const BILLING_TYPES: BillingType[] = [
    'hour_bank',
    'fixed_price',
    'time_and_materials',
    'internal',
];

/** Filtros por defecto: sin archivados, sin búsqueda y de todo el mundo. */
export const DEFAULT_PROJECT_FILTERS: ProjectListFilters = {
    cliente: null,
    estado: '',
    tipo: null,
    responsable: null,
    departamento: null,
    buscar: '',
    mios: false,
};

/** Parámetros de la URL: solo los que no están por defecto. */
export function projectFiltersQuery(
    filters: ProjectListFilters,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.cliente !== null) query.cliente = filters.cliente;
    if (filters.estado !== '') query.estado = filters.estado;
    if (filters.tipo !== null) query.tipo = filters.tipo;
    if (filters.responsable !== null) query.responsable = filters.responsable;
    if (filters.departamento !== null)
        query.departamento = filters.departamento;
    if (filters.buscar.trim() !== '') query.buscar = filters.buscar.trim();
    if (filters.mios) query.mios = 1;

    return query;
}

export function hasActiveProjectFilters(filters: ProjectListFilters): boolean {
    return Object.keys(projectFiltersQuery(filters)).length > 0;
}

function toOptions(options: Option[]) {
    return options.map((option) => ({
        value: String(option.id),
        label: option.name,
    }));
}

/**
 * Barra de filtros del listado de proyectos (SPEC §6, D-037): búsqueda por nombre o código,
 * cliente, estado (por defecto sin archivados), tipo, responsable, departamento implicado y
 * «mis proyectos». La búsqueda espera a que dejes de escribir.
 */
export function ProjectFiltersBar({
    filters,
    options,
    onChange,
}: {
    filters: ProjectListFilters;
    options: { clients: Option[]; owners: Option[]; departments: Option[] };
    onChange: (filters: ProjectListFilters) => void;
}) {
    const id = useId();
    const [search, setSearch] = useState(filters.buscar);

    useEffect(() => {
        if (search.trim() === filters.buscar.trim()) {
            return;
        }

        const timer = window.setTimeout(
            () => onChange({ ...filters, buscar: search }),
            350,
        );

        return () => window.clearTimeout(timer);
    }, [search, filters, onChange]);

    const set = <K extends keyof ProjectListFilters>(
        key: K,
        value: ProjectListFilters[K],
    ) => onChange({ ...filters, [key]: value });

    const toId = (value: string | null) =>
        value === null ? null : Number(value);

    return (
        <div
            role="search"
            aria-label={t('projects.filters.label')}
            className="grid gap-3 rounded-md border bg-card p-3"
        >
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid min-w-0 flex-1 basis-60 gap-1.5">
                    <Label
                        htmlFor={`${id}-search`}
                        className="text-xs text-muted-foreground"
                    >
                        {t('projects.filters.search')}
                    </Label>
                    <div className="relative">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            id={`${id}-search`}
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={t(
                                'projects.filters.search_placeholder',
                            )}
                            className="pl-8"
                            autoComplete="off"
                        />
                    </div>
                </div>
                <div className="flex items-center gap-2 pb-2">
                    <Switch
                        id={`${id}-mine`}
                        checked={filters.mios}
                        onCheckedChange={(checked) => set('mios', checked)}
                    />
                    <Label htmlFor={`${id}-mine`} className="font-normal">
                        {t('projects.filters.mine')}
                    </Label>
                </div>
                {hasActiveProjectFilters(filters) ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="mb-0.5"
                        onClick={() => {
                            setSearch('');
                            onChange(DEFAULT_PROJECT_FILTERS);
                        }}
                    >
                        <X aria-hidden="true" />
                        {t('projects.filters.clear')}
                    </Button>
                ) : null}
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <FilterSelect
                    id={`${id}-client`}
                    label={t('projects.filters.client')}
                    allLabel={t('projects.filters.all_clients')}
                    value={
                        filters.cliente === null
                            ? null
                            : String(filters.cliente)
                    }
                    options={toOptions(options.clients)}
                    onChange={(value) => set('cliente', toId(value))}
                />
                <FilterSelect
                    id={`${id}-status`}
                    label={t('projects.filters.status')}
                    value={filters.estado === '' ? null : filters.estado}
                    allLabel={t('projects.filters.not_archived')}
                    options={[
                        ...STATUSES.map((status) => ({
                            value: status,
                            label: t(`project.status.${status}`),
                        })),
                        {
                            value: 'todos',
                            label: t('projects.filters.all_statuses'),
                        },
                    ]}
                    onChange={(value) =>
                        set(
                            'estado',
                            (value ?? '') as ProjectListFilters['estado'],
                        )
                    }
                />
                <FilterSelect
                    id={`${id}-type`}
                    label={t('projects.filters.billing_type')}
                    allLabel={t('projects.filters.all_types')}
                    value={filters.tipo}
                    options={BILLING_TYPES.map((type) => ({
                        value: type,
                        label: t(`project.billing_type.${type}`),
                    }))}
                    onChange={(value) =>
                        set('tipo', value as BillingType | null)
                    }
                />
                <FilterSelect
                    id={`${id}-owner`}
                    label={t('projects.filters.owner')}
                    allLabel={t('projects.filters.all_owners')}
                    value={
                        filters.responsable === null
                            ? null
                            : String(filters.responsable)
                    }
                    options={toOptions(options.owners)}
                    onChange={(value) => set('responsable', toId(value))}
                />
                <FilterSelect
                    id={`${id}-department`}
                    label={t('projects.filters.department')}
                    allLabel={t('projects.filters.all_departments')}
                    value={
                        filters.departamento === null
                            ? null
                            : String(filters.departamento)
                    }
                    options={toOptions(options.departments)}
                    onChange={(value) => set('departamento', toId(value))}
                />
            </div>
        </div>
    );
}
