/**
 * Estado del editor de plantillas (D-058), sin React: filas en el orden en que se ven (cada tarea
 * de primer nivel seguida de sus subtareas), con sus dependencias «depende de…» en la propia fila.
 * Las reglas son las de ProjectTemplateService::normalize(): referencias únicas, subtareas de un
 * solo nivel, dependencias entre tareas que existen y sin ciclos.
 */
import type { TemplateStructure, TemplateTask } from '@/types/templates';

export type EditorRow = TemplateTask & {
    /** Referencias de las tareas de las que depende (predecesoras). */
    depends_on: string[];
};

/** Campos de una fila con error propio junto al campo. */
export type RowField =
    | 'title'
    | 'parent_ref'
    | 'task_type_id'
    | 'priority'
    | 'estimated_minutes'
    | 'start_offset_days'
    | 'duration_days'
    | 'depends_on'
    | 'ref';

export type EditorErrors = {
    /** Errores de toda la estructura (p. ej. «entre 1 y 500 tareas»). */
    general: string[];
    /** Por referencia de fila y campo. */
    rows: Record<string, Partial<Record<RowField, string>>>;
};

export function isSubtask(row: Pick<TemplateTask, 'parent_ref'>): boolean {
    return row.parent_ref !== null && row.parent_ref !== '';
}

/** Filas en orden de lectura: cada tarea de primer nivel con sus subtareas debajo. */
export function orderRows(rows: EditorRow[]): EditorRow[] {
    const refs = new Set(rows.map((row) => row.ref));
    const roots = rows.filter(
        (row) => !isSubtask(row) || !refs.has(row.parent_ref as string),
    );
    const ordered: EditorRow[] = [];

    for (const root of roots) {
        ordered.push(root);
        ordered.push(...rows.filter((row) => row.parent_ref === root.ref));
    }

    return ordered;
}

export function rowsFromStructure(structure: TemplateStructure): EditorRow[] {
    const dependsOn = new Map<string, string[]>();

    for (const link of structure.dependencies) {
        dependsOn.set(link.to_ref, [
            ...(dependsOn.get(link.to_ref) ?? []),
            link.from_ref,
        ]);
    }

    return orderRows(
        structure.tasks.map((task) => ({
            ...task,
            depends_on: dependsOn.get(task.ref) ?? [],
        })),
    );
}

export function structureFromRows(rows: EditorRow[]): TemplateStructure {
    return {
        tasks: rows.map(({ depends_on: _dependsOn, ...task }) => ({
            ...task,
            estimated_minutes: task.is_milestone
                ? null
                : task.estimated_minutes,
            duration_days: task.is_milestone ? 1 : task.duration_days,
        })),
        dependencies: rows.flatMap((row) =>
            row.depends_on.map((from) => ({ from_ref: from, to_ref: row.ref })),
        ),
    };
}

/** Referencia nueva que no usa ninguna fila («n1», «n2»…). */
export function nextRef(rows: EditorRow[]): string {
    const used = new Set(rows.map((row) => row.ref));
    let n = rows.length + 1;

    while (used.has(`n${n}`)) {
        n++;
    }

    return `n${n}`;
}

export function blankRow(
    rows: EditorRow[],
    overrides: Partial<EditorRow> = {},
): EditorRow {
    return {
        ref: nextRef(rows),
        parent_ref: null,
        title: '',
        task_type_id: null,
        priority: 'normal',
        estimated_minutes: null,
        is_milestone: false,
        start_offset_days: 0,
        duration_days: 1,
        depends_on: [],
        ...overrides,
    };
}

/** Índice de la última fila del bloque de una tarea de primer nivel (ella o su última subtarea). */
function blockEnd(rows: EditorRow[], ref: string): number {
    let end = rows.findIndex((row) => row.ref === ref);

    rows.forEach((row, index) => {
        if (row.parent_ref === ref) {
            end = Math.max(end, index);
        }
    });

    return end;
}

/** Añade una tarea de primer nivel al final. Empieza donde acaba la última, para ir encadenando. */
export function addRow(rows: EditorRow[]): EditorRow[] {
    const last = rows.filter((row) => !isSubtask(row)).at(-1);
    const start = last
        ? last.start_offset_days + (last.is_milestone ? 0 : last.duration_days)
        : 0;

    return [...rows, blankRow(rows, { start_offset_days: start })];
}

/** Añade una subtarea al final de las de su tarea, con sus mismas fechas. */
export function addSubtask(rows: EditorRow[], parentRef: string): EditorRow[] {
    const parent = rows.find((row) => row.ref === parentRef);

    if (!parent || isSubtask(parent)) {
        return rows;
    }

    const at = blockEnd(rows, parentRef) + 1;
    const row = blankRow(rows, {
        parent_ref: parentRef,
        start_offset_days: parent.start_offset_days,
        duration_days: parent.is_milestone ? 1 : parent.duration_days,
    });

    return [...rows.slice(0, at), row, ...rows.slice(at)];
}

/** Quita una fila y, si es de primer nivel, sus subtareas; y las dependencias que las nombran. */
export function removeRow(rows: EditorRow[], ref: string): EditorRow[] {
    const removed = new Set(
        rows
            .filter((row) => row.ref === ref || row.parent_ref === ref)
            .map((row) => row.ref),
    );

    return rows
        .filter((row) => !removed.has(row.ref))
        .map((row) =>
            row.depends_on.some((from) => removed.has(from))
                ? {
                      ...row,
                      depends_on: row.depends_on.filter(
                          (from) => !removed.has(from),
                      ),
                  }
                : row,
        );
}

export function updateRow(
    rows: EditorRow[],
    ref: string,
    changes: Partial<Omit<EditorRow, 'ref' | 'parent_ref' | 'depends_on'>>,
): EditorRow[] {
    return rows.map((row) => {
        if (row.ref !== ref) {
            return row;
        }

        const next = { ...row, ...changes };

        // Un hito no lleva horas y es un solo día (la entrega).
        if (next.is_milestone) {
            next.estimated_minutes = null;
            next.duration_days = 1;
        }

        return next;
    });
}

export function childrenOf(rows: EditorRow[], ref: string): EditorRow[] {
    return rows.filter((row) => row.parent_ref === ref);
}

/** Tareas de las que puede ser subtarea: las de primer nivel, salvo ella misma. */
export function parentOptions(rows: EditorRow[], ref: string): EditorRow[] {
    return rows.filter((row) => row.ref !== ref && !isSubtask(row));
}

/** Una tarea con subtareas no puede pasar a subtarea (solo hay un nivel). */
export function canBeSubtask(rows: EditorRow[], ref: string): boolean {
    return childrenOf(rows, ref).length === 0;
}

/**
 * Cambia de quién es subtarea (o la deja en primer nivel) y la coloca en su sitio: al final de las
 * subtareas de su nueva tarea, o justo después del bloque de la tarea de la que colgaba.
 */
export function setParent(
    rows: EditorRow[],
    ref: string,
    parentRef: string | null,
): EditorRow[] {
    const row = rows.find((item) => item.ref === ref);

    if (!row || row.parent_ref === parentRef) {
        return rows;
    }

    if (parentRef !== null) {
        const parent = rows.find((item) => item.ref === parentRef);

        if (
            !parent ||
            isSubtask(parent) ||
            parentRef === ref ||
            !canBeSubtask(rows, ref)
        ) {
            return rows;
        }
    }

    const previousParent = row.parent_ref;
    const rest = rows.filter((item) => item.ref !== ref);
    const moved = { ...row, parent_ref: parentRef };
    const at =
        parentRef !== null
            ? blockEnd(rest, parentRef) + 1
            : previousParent !== null
              ? blockEnd(rest, previousParent) + 1
              : rest.length;

    return [...rest.slice(0, at), moved, ...rest.slice(at)];
}

/**
 * Sube o baja una fila: una tarea de primer nivel se mueve con sus subtareas entre las demás
 * tareas de primer nivel; una subtarea, entre las de su misma tarea.
 */
export function moveRow(
    rows: EditorRow[],
    ref: string,
    direction: 'up' | 'down',
): EditorRow[] {
    const row = rows.find((item) => item.ref === ref);

    if (!row) {
        return rows;
    }

    if (isSubtask(row)) {
        const siblings = childrenOf(rows, row.parent_ref as string);
        const index = siblings.findIndex((item) => item.ref === ref);
        const other = siblings[direction === 'up' ? index - 1 : index + 1];

        if (!other) {
            return rows;
        }

        const a = rows.findIndex((item) => item.ref === ref);
        const b = rows.findIndex((item) => item.ref === other.ref);
        const next = [...rows];
        [next[a], next[b]] = [next[b], next[a]];

        return next;
    }

    const blocks = rows
        .filter((item) => !isSubtask(item))
        .map((root) => [
            root,
            ...rows.filter((item) => item.parent_ref === root.ref),
        ]);
    const index = blocks.findIndex((block) => block[0].ref === ref);
    const target = direction === 'up' ? index - 1 : index + 1;

    if (index < 0 || target < 0 || target >= blocks.length) {
        return rows;
    }

    [blocks[index], blocks[target]] = [blocks[target], blocks[index]];

    return blocks.flat();
}

export function canMove(
    rows: EditorRow[],
    ref: string,
    direction: 'up' | 'down',
): boolean {
    return moveRow(rows, ref, direction) !== rows;
}

/**
 * ¿Crearía un ciclo que `successor` dependa de `predecessor`? Sí, si desde `successor` ya se llega
 * a `predecessor` siguiendo las dependencias (como DependencyService en el servidor).
 */
export function wouldCreateCycle(
    rows: EditorRow[],
    predecessor: string,
    successor: string,
): boolean {
    if (predecessor === successor) {
        return true;
    }

    // Aristas predecesora → sucesoras.
    const successors = new Map<string, string[]>();
    for (const row of rows) {
        for (const from of row.depends_on) {
            successors.set(from, [...(successors.get(from) ?? []), row.ref]);
        }
    }

    const queue = [successor];
    const seen = new Set(queue);

    while (queue.length > 0) {
        const current = queue.shift() as string;

        if (current === predecessor) {
            return true;
        }

        for (const next of successors.get(current) ?? []) {
            if (!seen.has(next)) {
                seen.add(next);
                queue.push(next);
            }
        }
    }

    return false;
}

/** Añade o quita «`ref` depende de `predecessor`». No añade nada que cree un ciclo. */
export function toggleDependency(
    rows: EditorRow[],
    ref: string,
    predecessor: string,
): EditorRow[] {
    const row = rows.find((item) => item.ref === ref);

    if (!row) {
        return rows;
    }

    if (row.depends_on.includes(predecessor)) {
        return rows.map((item) =>
            item.ref === ref
                ? {
                      ...item,
                      depends_on: item.depends_on.filter(
                          (from) => from !== predecessor,
                      ),
                  }
                : item,
        );
    }

    if (wouldCreateCycle(rows, predecessor, ref)) {
        return rows;
    }

    return rows.map((item) =>
        item.ref === ref
            ? { ...item, depends_on: [...item.depends_on, predecessor] }
            : item,
    );
}

/** Último día (relativo) de una tarea: su entrega. Un hito, su día. */
export function endDay(row: TemplateTask): number {
    return (
        row.start_offset_days + (row.is_milestone ? 0 : row.duration_days - 1)
    );
}

/** Días que ocupa la plantilla (del día 0 a la entrega más tardía). */
export function totalDays(rows: TemplateTask[]): number {
    return rows.reduce((max, row) => Math.max(max, endDay(row) + 1), 0);
}

/**
 * Predecesoras que acaban el mismo día o después de que empiece la tarea (D-057): no impide
 * guardar, pero al aplicar la plantilla la tarea saldría en conflicto.
 */
export function conflictsOf(rows: EditorRow[], ref: string): EditorRow[] {
    const row = rows.find((item) => item.ref === ref);

    if (!row) {
        return [];
    }

    return row.depends_on
        .map((from) => rows.find((item) => item.ref === from))
        .filter(
            (predecessor): predecessor is EditorRow =>
                predecessor !== undefined &&
                row.start_offset_days <= endDay(predecessor),
        );
}

/**
 * Errores del servidor (`structure.tasks.3.title`, `structure.tasks.3.depends_on`,
 * `structure`…) repartidos por fila y campo. Las filas son las que se enviaron, en su orden.
 */
export function mapErrors(
    errors: Record<string, string | undefined>,
    rows: EditorRow[],
): EditorErrors {
    const result: EditorErrors = { general: [], rows: {} };

    for (const [key, message] of Object.entries(errors)) {
        if (!message || !key.startsWith('structure')) {
            continue;
        }

        const match = /^structure\.tasks\.(\d+)\.([a-z_]+)$/.exec(key);
        const row = match ? rows[Number(match[1])] : undefined;

        if (match && row) {
            result.rows[row.ref] = {
                ...result.rows[row.ref],
                [match[2] as RowField]: message,
            };
        } else if (!result.general.includes(message)) {
            result.general.push(message);
        }
    }

    return result;
}
