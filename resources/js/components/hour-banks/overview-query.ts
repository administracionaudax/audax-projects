import type { HourBankOverviewFilters } from '@/types';

/** Parámetros de la URL: solo los que no están por defecto. */
export function overviewQuery(
    filters: HourBankOverviewFilters,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.cliente !== null) query.cliente = filters.cliente;
    if (filters.departamento !== null)
        query.departamento = filters.departamento;
    if (filters.estado !== '') query.estado = filters.estado;
    if (filters.proximas) query.proximas = 1;

    return query;
}
