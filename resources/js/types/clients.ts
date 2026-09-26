/**
 * Props de las páginas del área «clients» (Fase 1). Los tipos de entidad están en ./domain.
 * Contrato con app/Http/Controllers/Clients/ClientController y app/Http/Resources/Clients/*.
 */
import type { AdminPaginated } from './admin';
import type { Client, HourBank, Project } from './domain';

/** ClientRowResource. */
export type ClientListItem = Client & {
    active_projects_count: number;
    /** Minutos imputados este mes en sus proyectos (todas las personas). */
    month_minutes: number;
};

export type ClientStatusFilter = 'activos' | 'inactivos' | 'todos';

export type ClientsIndexProps = {
    clients: AdminPaginated<ClientListItem>;
    filters: {
        q: string;
        estado: ClientStatusFilter;
    };
};

/** ClientHourBankResource. */
export type ClientHourBank = HourBank & {
    project?: { id: number; code: string; name: string; color: string };
    /** Estimación restante de sus tareas abiertas. */
    committed_minutes: number;
};

export type ClientShowProps = {
    client: Client;
    projects: Project[];
    /** Bolsas activas y agotadas. */
    hourBanks: ClientHourBank[];
    /** Bolsas renovadas y cerradas. */
    hourBankHistory: ClientHourBank[];
    hours: {
        month_minutes: number;
        year_minutes: number;
        /** "YYYY-MM-DD", primer día del mes en curso. */
        month_start: string;
        year: number;
    };
    can: { update: boolean };
};
