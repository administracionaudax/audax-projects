/**
 * Enlaces entre informes. Los de R1 salen de las rutas tipadas de Wayfinder; los dashboards de
 * cliente, proyecto y el informe detallado los sirven otras áreas (R2 y R3), así que se enlazan por
 * su URL (docs/PLAN-FASE-2.md, «Rutas»), como hace lib/urls.ts entre áreas.
 */
import { department, direction, index, person } from '@/routes/reports';
import type { ReportQuery } from '@/types';

type QueryValue =
    | string
    | number
    | ReadonlyArray<string | number>
    | null
    | undefined;

export type Query = Record<string, QueryValue>;

function withQuery(path: string, query?: Query): string {
    if (!query) {
        return path;
    }

    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value === undefined || value === null) {
            continue;
        }

        if (typeof value === 'string' || typeof value === 'number') {
            params.set(key, String(value));
        } else {
            value.forEach((item) => params.append(`${key}[]`, String(item)));
        }
    }

    const search = params.toString();

    return search ? `${path}?${search}` : path;
}

export const reportUrls = {
    index: () => index.url(),
    direction: (query?: Query) => withQuery(direction.url(), query),
    department: (id: number, query?: Query) =>
        withQuery(department.url(id), query),
    person: (id: number, query?: Query) => withQuery(person.url(id), query),
    client: (id: number, query?: Query) =>
        withQuery(`/informes/clientes/${id}`, query),
    project: (id: number, query?: Query) =>
        withQuery(`/informes/proyectos/${id}`, query),
    detail: (query?: Query) => withQuery('/informes/detalle', query),
} as const;

/**
 * Periodo de la URL sin los filtros de dimensión (persona, cliente…): para pasar de un informe a
 * otro conservando el periodo y la comparación.
 */
export function periodQuery(query: ReportQuery): Query {
    const { periodo, fecha, desde, hasta, comparar } = query;

    return { periodo, fecha, desde, hasta, comparar };
}
