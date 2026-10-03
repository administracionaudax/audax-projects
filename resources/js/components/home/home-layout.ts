/**
 * Orden de las tarjetas de Inicio (D-138).
 *
 * `HOME_CARD_IDS` es el contrato con `App\Domain\Home\HomeLayout::CARDS` (la lista blanca que
 * valida el servidor): tests/fixtures/home-cards.json los compara en Pest y en Vitest.
 */
export const HOME_CARD_IDS = [
    'today-tasks',
    'timer',
    'week-hours',
    'workload',
    'unlogged-days',
    'indicators',
    'absences',
    'milestones',
    'mentions',
] as const;

export type HomeCardId = (typeof HOME_CARD_IDS)[number];

/**
 * Ordena las tarjetas que se pintan (`available`, en su orden por defecto) según el orden guardado:
 * primero las guardadas, en su orden; después, en su orden por defecto, las que falten (tarjetas
 * nuevas o que dependen del rol). Se ignoran los ids guardados que no se pintan (ya no existen o
 * no le corresponden a quien mira) y los repetidos.
 */
export function orderCards<T extends string>(
    available: readonly T[],
    saved: readonly string[] | null | undefined,
): T[] {
    if (!saved || saved.length === 0) {
        return [...available];
    }

    const known = new Set<string>(available);
    const ordered: T[] = [];
    const seen = new Set<string>();

    for (const id of saved) {
        if (known.has(id) && !seen.has(id)) {
            seen.add(id);
            ordered.push(id as T);
        }
    }

    return [...ordered, ...available.filter((id) => !seen.has(id))];
}

/** ¿Dos listas con los mismos ids en el mismo orden? */
export function sameOrder(a: readonly string[], b: readonly string[]): boolean {
    return a.length === b.length && a.every((id, index) => id === b[index]);
}
