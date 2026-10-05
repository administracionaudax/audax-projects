/**
 * Props de las páginas del área «clients» (Fase 1). Los tipos de entidad están en ./domain.
 * Contrato con app/Http/Controllers/Clients/ClientController y app/Http/Resources/Clients/*.
 */
import type { AdminPaginated } from './admin';
import type { Client, HourBank, Project } from './domain';
import type {
    ClientOwner,
    ClientTab,
    ClientWeeklyData,
    ProjectKindBadge,
    ProjectKindTag,
} from './weekly-insights';
import type { WeeklyJoinableProject } from './weeklies';

/** ClientRowResource. */
export type ClientListItem = Client & {
    active_projects_count: number;
    /** Minutos imputados este mes en sus proyectos (todas las personas). */
    month_minutes: number;
    /** Último envío con un apunte del cliente (con la Weekly, F-124); null sin reportes. */
    last_report_at: string | null;
    /** Satisfacción actual menos la anterior al último cierre; null sin cierres (F-096). */
    satisfaction_trend: number | null;
    /** Proyectos abiertos por tipo (F-120). */
    kind_badges: ProjectKindBadge[];
};

/** Orden de la lista (F-124). */
export type ClientSort = 'nombre' | 'ultimo_reporte' | 'satisfaccion';

export type ClientStatusFilter = 'activos' | 'inactivos' | 'todos';

export type ClientsIndexProps = {
    clients: AdminPaginated<ClientListItem>;
    filters: {
        q: string;
        estado: ClientStatusFilter;
        orden: ClientSort;
        dir: 'asc' | 'desc';
        tipo: ProjectKindTag | '';
        /** Id de la persona (texto) o ''. */
        persona: string;
        /** '1' = «Mis proyectos». */
        mios: '1' | '';
    };
    /** Personas de plantilla para el filtro por persona (F-123); diferida. */
    people?: { id: number; name: string }[];
    /** Columnas de la Weekly (último reporte y satisfacción): módulo encendido y use-weeklies. */
    weekly: boolean;
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
    /**
     * viewReport: informe del cliente (Fase 2, D-044); viewBilling: sus horas para facturar
     * (Fase 2, D-045).
     */
    can: {
        update: boolean;
        viewReport?: boolean;
        viewBilling?: boolean;
        /** La Weekly del cliente: pestañas, resumen con IA y unirse o dejar proyectos (Fase 10). */
        useWeeklies?: boolean;
    };
    /** Pestaña abierta (?pestana=); sin la Weekly, siempre «resumen». */
    tab?: ClientTab;
    /** Responsable: quien gestiona más proyectos abiertos (F-128). */
    owner?: ClientOwner | null;
    /** Proyectos abiertos por tipo (F-120). */
    kindBadges?: ProjectKindBadge[];
    /** La Weekly de la pestaña abierta (diferida); null sin la Weekly. */
    weekly?: ClientWeeklyData | null;
    /** Opcional: router.reload({ only: ['joinable_projects'] }) al abrir «Unirme a proyectos». */
    joinable_projects?: WeeklyJoinableProject[];
};
