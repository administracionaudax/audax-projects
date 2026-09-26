import { useState } from 'react';
import type { LoggableTask } from '@/types';

const PREFIX = 'audax.timesheet.rows';

function storageKey(personId: number, week: string): string {
    return `${PREFIX}.${personId}.${week}`;
}

function read(key: string): LoggableTask[] {
    try {
        const raw = window.localStorage.getItem(key);
        const parsed: unknown = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed)
            ? parsed.filter(
                  (task): task is LoggableTask =>
                      typeof task === 'object' &&
                      task !== null &&
                      typeof (task as LoggableTask).id === 'number' &&
                      typeof (task as LoggableTask).title === 'string' &&
                      typeof (task as LoggableTask).project === 'object',
              )
            : [];
    } catch {
        return [];
    }
}

function write(key: string, tasks: LoggableTask[]): void {
    try {
        if (tasks.length === 0) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, JSON.stringify(tasks));
        }
    } catch {
        // Sin almacenamiento (modo privado, bloqueado): las filas viven solo en esta visita.
    }
}

/**
 * Filas sin horas de la hoja semanal (añadidas a mano o copiadas de la semana anterior, D-036).
 * No son datos: en cuanto se imputa en una, pasa a ser una fila real. Se recuerdan en este
 * navegador por persona y semana para que no se pierdan al recargar.
 */
export function useExtraRows(
    personId: number,
    week: string,
): {
    rows: LoggableTask[];
    add: (tasks: LoggableTask[]) => number;
    remove: (taskId: number) => void;
} {
    const key = storageKey(personId, week);
    const [state, setState] = useState<{ key: string; rows: LoggableTask[] }>(
        () => ({ key, rows: read(key) }),
    );

    // Otra persona u otra semana (misma página): se leen sus filas.
    let rows = state.rows;
    if (state.key !== key) {
        rows = read(key);
        setState({ key, rows });
    }

    const add = (tasks: LoggableTask[]): number => {
        const known = new Set(rows.map((task) => task.id));
        const fresh = tasks.filter((task) => !known.has(task.id));

        if (fresh.length > 0) {
            const next = [...rows, ...fresh];
            write(key, next);
            setState({ key, rows: next });
        }

        return fresh.length;
    };

    const remove = (taskId: number) => {
        const next = rows.filter((task) => task.id !== taskId);
        write(key, next);
        setState({ key, rows: next });
    };

    return { rows, add, remove };
}
