import type { TeamMemberRow } from '@/types/weekly-insights';
import type { WeeklyPersonStatus } from '@/types/weeklies';

/**
 * Filtro y orden de la lista del equipo (F-134 y F-135), en el navegador: la plantilla es pequeña y
 * así los filtros son instantáneos, como en WeeklySync.
 */
export type TeamSortKey =
    | 'name'
    | 'email'
    | 'department'
    | 'job_title'
    | 'status';

/** Orden de los estados del reporte (enviados, pendientes, exentos y no requeridos). */
export const STATUS_ORDER: WeeklyPersonStatus[] = [
    'submitted',
    'submitted_late',
    'overdue',
    'pending',
    'upcoming',
    'missed',
    'exempt',
    'not_required',
];

function sortValue(row: TeamMemberRow, key: TeamSortKey): string | number {
    switch (key) {
        case 'email':
            return row.email.toLocaleLowerCase('es');
        case 'department':
            return (row.department?.name ?? '~').toLocaleLowerCase('es');
        case 'job_title':
            return (row.job_title ?? '~').toLocaleLowerCase('es');
        case 'status':
            return row.report_status === null
                ? STATUS_ORDER.length
                : STATUS_ORDER.indexOf(row.report_status);
        default:
            return row.user.name.toLocaleLowerCase('es');
    }
}

/** Filtra y ordena el equipo (F-134 y F-135): una función pura para la página y sus tests. */
export function filterTeam(
    rows: TeamMemberRow[],
    filters: {
        q: string;
        department: string;
        role: string;
        status: string;
        client: string;
    },
    sort: { key: TeamSortKey; dir: 'asc' | 'desc' },
): TeamMemberRow[] {
    const needle = filters.q.trim().toLocaleLowerCase('es');
    const factor = sort.dir === 'asc' ? 1 : -1;

    return rows
        .filter((row) => {
            if (
                needle !== '' &&
                !`${row.user.name} ${row.email} ${row.job_title ?? ''}`
                    .toLocaleLowerCase('es')
                    .includes(needle)
            ) {
                return false;
            }

            if (
                filters.department !== '' &&
                String(row.department?.id ?? 'none') !== filters.department
            ) {
                return false;
            }

            if (filters.role !== '' && row.role !== filters.role) {
                return false;
            }

            if (filters.status !== '' && row.report_status !== filters.status) {
                return false;
            }

            return (
                filters.client === '' ||
                row.client_ids.includes(Number(filters.client))
            );
        })
        .sort((a, b) => {
            const va = sortValue(a, sort.key);
            const vb = sortValue(b, sort.key);

            if (va < vb) {
                return -factor;
            }

            if (va > vb) {
                return factor;
            }

            return a.user.name.localeCompare(b.user.name, 'es');
        });
}

/** Clave de los filtros y el orden de /equipo en la pestaña (se conservan al volver de una ficha). */
export const TEAM_FILTERS_KEY = 'audax.team.filters';

export type StoredTeamView<F> = {
    filters: F;
    sort: { key: TeamSortKey; dir: 'asc' | 'desc' };
};

/**
 * Los filtros y el orden guardados en la pestaña (sessionStorage), o null (10.9b: en WeeklySync la
 * lista seguía montada y no se perdían al volver de una ficha).
 */
export function readTeamView<F extends Record<string, string>>(
    empty: F,
): StoredTeamView<F> | null {
    try {
        const raw = window.sessionStorage.getItem(TEAM_FILTERS_KEY);

        if (!raw) {
            return null;
        }

        const stored = JSON.parse(raw) as Partial<StoredTeamView<F>>;
        const filters = { ...empty };

        for (const key of Object.keys(empty) as (keyof F)[]) {
            const value = stored.filters?.[key];

            if (typeof value === 'string') {
                filters[key] = value as F[keyof F];
            }
        }

        const sort =
            stored.sort && typeof stored.sort.key === 'string'
                ? {
                      key: stored.sort.key,
                      dir: stored.sort.dir === 'desc' ? 'desc' : 'asc',
                  }
                : { key: 'name', dir: 'asc' };

        return { filters, sort } as StoredTeamView<F>;
    } catch {
        return null;
    }
}

export function writeTeamView<F>(view: StoredTeamView<F>): void {
    try {
        window.sessionStorage.setItem(TEAM_FILTERS_KEY, JSON.stringify(view));
    } catch {
        // Sin almacenamiento, los filtros solo duran mientras la página está abierta.
    }
}
