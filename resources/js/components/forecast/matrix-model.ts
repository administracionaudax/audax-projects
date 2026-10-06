/**
 * Modelo de la matriz de la previsión (D-290, D-293 y D-300): los grupos (departamentos y, aparte,
 * los colaboradores externos), sus filas visibles y lo que dice cada celda. Sin React, para poder
 * probarlo y reutilizarlo en «Ver como tabla».
 */
import { cellItems, cellLoad, sourceMatcher } from '@/lib/forecast';
import type { CellItem, LayerToggles, MatrixRowRef } from '@/lib/forecast';
import type {
    ForecastBoard,
    ForecastPerson,
    LoadCell,
    LoadLayers,
} from '@/types/forecast';

/** Clave del grupo de los colaboradores externos (no es un departamento). */
export const COLLABORATORS_GROUP = 'collaborators';

export type MatrixGroup = {
    /** «d12», «d-none» o «collaborators»: lo que se recuerda al plegar. */
    key: string;
    kind: 'department' | 'collaborators';
    departmentId: number | null;
    name: string | null;
    color: string | null;
    /** Personas del grupo (las que pasan el filtro de búsqueda). */
    people: ForecastPerson[];
    /** Personas de plantilla del departamento, sin filtrar (su capacidad). */
    size: number;
    cells: LoadCell[];
    gaps: LoadLayers[] | null;
};

const EMPTY: LoadCell = { capacity: 0, real: 0, firm: 0, tentative: 0 };

function sum(a: LoadCell, b: LoadCell): LoadCell {
    return {
        capacity: a.capacity + b.capacity,
        real: a.real + b.real,
        firm: a.firm + b.firm,
        tentative: a.tentative + b.tentative,
    };
}

function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

/** Los grupos de la matriz: los departamentos en su orden y, al final, los colaboradores. */
export function matrixGroups(board: ForecastBoard, search = ''): MatrixGroup[] {
    const needle = normalize(search);
    const matches = (person: ForecastPerson) =>
        needle === '' || normalize(person.name).includes(needle);
    const groups: MatrixGroup[] = [];

    for (const department of board.departments) {
        const staff = board.people.filter(
            (person) =>
                !person.collaborator && person.department_id === department.id,
        );
        const people = staff.filter(matches);

        if (needle !== '' && people.length === 0) {
            continue;
        }

        groups.push({
            key: department.id === null ? 'd-none' : `d${department.id}`,
            kind: 'department',
            departmentId: department.id,
            name: department.name,
            color: department.color,
            people,
            size: department.people,
            cells: department.cells,
            gaps: needle === '' ? department.gaps : null,
        });
    }

    const collaborators = board.people.filter((person) => person.collaborator);
    const shown = collaborators.filter(matches);

    if (shown.length > 0) {
        groups.push({
            key: COLLABORATORS_GROUP,
            kind: 'collaborators',
            departmentId: null,
            name: null,
            color: null,
            people: shown,
            size: collaborators.length,
            cells: board.buckets.map((_, index) =>
                collaborators.reduce(
                    (total, person) => sum(total, person.cells[index] ?? EMPTY),
                    EMPTY,
                ),
            ),
            gaps: null,
        });
    }

    return groups;
}

export type MatrixRow =
    | { kind: 'group'; key: string; group: MatrixGroup }
    | {
          kind: 'person';
          key: string;
          group: MatrixGroup;
          person: ForecastPerson;
      }
    | { kind: 'gap'; key: string; group: MatrixGroup };

/** ¿Tiene el grupo algún hueco con las capas encendidas? */
export function hasGaps(group: MatrixGroup, layers: LayerToggles): boolean {
    return (group.gaps ?? []).some((gap) => cellLoad(gap, layers) > 0);
}

/** Filas visibles, en orden: cada grupo y, si está desplegado, sus personas y su fila de huecos. */
export function matrixRows(
    groups: MatrixGroup[],
    expanded: (key: string) => boolean,
    layers: LayerToggles,
): MatrixRow[] {
    const rows: MatrixRow[] = [];

    for (const group of groups) {
        rows.push({ kind: 'group', key: `g:${group.key}`, group });

        if (!expanded(group.key)) {
            continue;
        }

        for (const person of group.people) {
            rows.push({
                kind: 'person',
                key: `p:${person.id}`,
                group,
                person,
            });
        }

        if (group.departmentId !== null && hasGaps(group, layers)) {
            rows.push({ kind: 'gap', key: `h:${group.departmentId}`, group });
        }
    }

    return rows;
}

/** Referencia de la fila para buscar sus fuentes (de qué proyectos sale la carga). */
export function rowRef(row: MatrixRow): MatrixRowRef {
    if (row.kind === 'person') {
        return { kind: 'person', id: row.person.id };
    }

    if (row.kind === 'gap') {
        return { kind: 'gap', departmentId: row.group.departmentId ?? 0 };
    }

    return {
        kind: 'department',
        departmentId:
            row.group.kind === 'department' ? row.group.departmentId : null,
        members: row.group.people.map((person) => person.id),
    };
}

/** La celda de una fila en una columna (en un hueco, sin capacidad). */
export function rowCell(row: MatrixRow, index: number): LoadCell {
    if (row.kind === 'person') {
        return row.person.cells[index] ?? EMPTY;
    }

    if (row.kind === 'gap') {
        return { ...EMPTY, ...row.group.gaps?.[index] };
    }

    return row.group.cells[index] ?? EMPTY;
}

/** De qué proyectos sale la carga de una celda de una fila. */
export function rowItems(
    board: ForecastBoard,
    row: MatrixRow,
    index: number,
): CellItem[] {
    return cellItems(board.sources, index, sourceMatcher(rowRef(row)));
}
