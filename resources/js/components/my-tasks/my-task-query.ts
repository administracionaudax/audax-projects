/**
 * Filtros y orden de Mis tareas en la URL (D-143), en español, como
 * App\Domain\Tasks\MyTaskFilters: ?q=&proyecto=3,7&cliente=&estado=&hechas=1&prioridad=&tipo=
 * &vence=vencidas|hoy|semana|sin_fecha|rango&desde=&hasta=&orden=.
 */
import type { MyTaskDue, MyTaskFilters, MyTaskSort } from '@/types';

export const SORTS: MyTaskSort[] = [
    'logged',
    'due',
    'priority',
    'project',
    'created',
    'updated',
];

export const DUE_OPTIONS: MyTaskDue[] = [
    'overdue',
    'today',
    'week',
    'none',
    'range',
];

const SORT_PARAM: Record<MyTaskSort, string> = {
    logged: 'imputadas',
    due: 'vencimiento',
    priority: 'prioridad',
    project: 'proyecto',
    created: 'creacion',
    updated: 'actualizacion',
};

const DUE_PARAM: Record<MyTaskDue, string> = {
    overdue: 'vencidas',
    today: 'hoy',
    week: 'semana',
    none: 'sin_fecha',
    range: 'rango',
};

/** Parámetros de la URL que cuentan como filtros u orden (para recordar y restaurar). */
export const MY_TASK_PARAMS = [
    'q',
    'proyecto',
    'cliente',
    'estado',
    'hechas',
    'prioridad',
    'tipo',
    'vence',
    'desde',
    'hasta',
    'orden',
] as const;

export const DEFAULT_SORT: MyTaskSort = 'logged';

export const EMPTY_MY_TASK_FILTERS: MyTaskFilters = {
    q: null,
    projects: [],
    clients: [],
    statuses: [],
    done: false,
    priority: null,
    types: [],
    due: null,
    from: null,
    to: null,
    sort: DEFAULT_SORT,
};

/** ¿Hay algún filtro? (el orden no cuenta). */
export function hasMyTaskFilters(filters: MyTaskFilters): boolean {
    return (
        (filters.q ?? '') !== '' ||
        filters.projects.length > 0 ||
        filters.clients.length > 0 ||
        filters.statuses.length > 0 ||
        filters.done ||
        filters.priority !== null ||
        filters.types.length > 0 ||
        filters.due !== null
    );
}

/** Cuántos filtros hay activos (para el botón que los pliega en el móvil). */
export function countMyTaskFilters(filters: MyTaskFilters): number {
    return [
        (filters.q ?? '') !== '',
        filters.projects.length > 0,
        filters.clients.length > 0,
        filters.statuses.length > 0,
        filters.done,
        filters.priority !== null,
        filters.types.length > 0,
        filters.due !== null,
    ].filter(Boolean).length;
}

/** Filtros → parámetros de la URL; sin los vacíos ni el orden por defecto. */
export function myTaskQuery(filters: MyTaskFilters): Record<string, string> {
    const query: Record<string, string> = {};
    const q = (filters.q ?? '').trim();

    if (q !== '') {
        query.q = q;
    }

    if (filters.projects.length > 0) {
        query.proyecto = filters.projects.join(',');
    }

    if (filters.clients.length > 0) {
        query.cliente = filters.clients.join(',');
    }

    if (filters.statuses.length > 0) {
        query.estado = filters.statuses.join(',');
    }

    if (filters.done) {
        query.hechas = '1';
    }

    if (filters.priority !== null) {
        query.prioridad = filters.priority;
    }

    if (filters.types.length > 0) {
        query.tipo = filters.types.join(',');
    }

    if (filters.due !== null) {
        query.vence = DUE_PARAM[filters.due];

        if (filters.due === 'range') {
            if (filters.from) {
                query.desde = filters.from;
            }

            if (filters.to) {
                query.hasta = filters.to;
            }
        }
    }

    if (filters.sort !== DEFAULT_SORT) {
        query.orden = SORT_PARAM[filters.sort];
    }

    return query;
}
