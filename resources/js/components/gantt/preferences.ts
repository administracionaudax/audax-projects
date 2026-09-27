import type {
    GanttColorMode,
    GanttFilters,
    GanttPreferences,
    GanttScale,
} from '@/components/gantt/types';

/** Valores de la URL (en español) de App\Domain\Gantt\GanttPreferences. */
const SCALE_PARAM: Record<GanttScale, string> = {
    day: 'dia',
    week: 'semana',
    month: 'mes',
};

const COLOR_PARAM: Record<GanttColorMode, string> = {
    status: 'estado',
    assignee: 'responsable',
};

export const DEFAULT_PREFERENCES: GanttPreferences = {
    scale: 'week',
    color: 'status',
};

export const DEFAULT_FILTERS: GanttFilters = {
    cliente: null,
    departamento: null,
    responsable: null,
    estado: 'active',
};

/** ?escala=&color= (solo lo que no está por defecto). */
export function preferencesQuery(
    preferences: GanttPreferences,
): Record<string, string> {
    const query: Record<string, string> = {};

    if (preferences.scale !== DEFAULT_PREFERENCES.scale) {
        query.escala = SCALE_PARAM[preferences.scale];
    }

    if (preferences.color !== DEFAULT_PREFERENCES.color) {
        query.color = COLOR_PARAM[preferences.color];
    }

    return query;
}

/** ?cliente=&departamento=&responsable=&estado= (solo lo que no está por defecto). */
export function filtersQuery(filters: GanttFilters): Record<string, string> {
    const query: Record<string, string> = {};

    if (filters.cliente !== null) {
        query.cliente = String(filters.cliente);
    }

    if (filters.departamento !== null) {
        query.departamento = String(filters.departamento);
    }

    if (filters.responsable !== null) {
        query.responsable = String(filters.responsable);
    }

    if (filters.estado !== DEFAULT_FILTERS.estado) {
        query.estado = filters.estado;
    }

    return query;
}
