import type { MentionNames } from '@/components/chat/markdown';

/**
 * Menciones en el editor (D-069): el mensaje guarda <@ID>, pero en el cuadro de texto se ve
 * @Nombre. El editor recuerda qué @Nombre eligió en el autocompletado y al enviar lo convierte en
 * <@ID>; si se retoca el nombre a mano, se queda como texto. @todos se guarda tal cual.
 */

export type MentionRef = { id: number; name: string };

export type MentionCandidate =
    | { kind: 'person'; id: number; name: string; is_active: boolean }
    | { kind: 'everyone' };

const MAX_CANDIDATES = 8;

export const EVERYONE = 'todos';

/** Minúsculas y sin acentos, para buscar «jose» y encontrar «José». */
export function normalizeSearch(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Cuerpo guardado → texto del editor (al editar un mensaje o recuperar un borrador antiguo). */
export function bodyToEditable(
    body: string,
    names: MentionNames,
): { text: string; mentions: MentionRef[] } {
    const mentions = new Map<number, MentionRef>();

    const text = body.replace(/<@(\d{1,10})>/g, (token, raw: string) => {
        const id = Number(raw);
        const name = names.get(id);

        if (name === undefined) {
            return token;
        }

        mentions.set(id, { id, name });

        return `@${name}`;
    });

    return { text, mentions: [...mentions.values()] };
}

/** Texto del editor → cuerpo: cada @Nombre elegido vuelve a ser <@ID> (primero los más largos). */
export function editableToBody(text: string, mentions: MentionRef[]): string {
    const byName = new Map<string, MentionRef>();

    for (const mention of mentions) {
        byName.set(mention.name, mention);
    }

    const sorted = [...byName.values()].sort(
        (a, b) => b.name.length - a.name.length,
    );
    let body = text;

    for (const mention of sorted) {
        const pattern = new RegExp(
            `(^|[^\\p{L}\\p{N}_@])@${escapeRegExp(mention.name)}(?![\\p{L}\\p{N}_])`,
            'gu',
        );

        body = body.replace(
            pattern,
            (_, before: string) => `${before}<@${mention.id}>`,
        );
    }

    return body;
}

/** Mención que se está escribiendo justo antes del cursor: «hola @lu|» → {start: 5, query: 'lu'}. */
export function mentionQueryAt(
    text: string,
    caret: number,
): { start: number; query: string } | null {
    const before = text.slice(0, caret);
    const match = /(^|[\s(])@([\p{L}\p{N}._-]{0,30})$/u.exec(before);

    if (!match) {
        return null;
    }

    return {
        start: before.length - match[2].length - 1,
        query: match[2],
    };
}

/**
 * Candidatos del autocompletado: personas cuyo nombre (o alguna de sus palabras) empieza por lo
 * escrito, sin tildes ni mayúsculas, y @todos si encaja. Como mucho 8.
 */
export function mentionCandidates(
    people: { id: number; name: string; is_active: boolean }[],
    query: string,
    withEveryone: boolean,
): MentionCandidate[] {
    const needle = normalizeSearch(query);
    const matches = people
        .filter((person) => {
            if (needle === '') {
                return true;
            }

            const name = normalizeSearch(person.name);

            return (
                name.startsWith(needle) ||
                name.split(/\s+/).some((word) => word.startsWith(needle))
            );
        })
        .sort((a, b) => a.name.localeCompare(b.name, 'es'))
        .map((person): MentionCandidate => ({
            kind: 'person',
            id: person.id,
            name: person.name,
            is_active: person.is_active,
        }));

    const everyone: MentionCandidate[] =
        withEveryone && EVERYONE.startsWith(needle)
            ? [{ kind: 'everyone' }]
            : [];

    return [...matches, ...everyone].slice(0, MAX_CANDIDATES);
}

/** Inserta la mención elegida en el texto, en lugar de «@consulta». */
export function insertMention(
    text: string,
    start: number,
    caret: number,
    candidate: MentionCandidate,
): { text: string; caret: number; mention: MentionRef | null } {
    const label =
        candidate.kind === 'everyone' ? `@${EVERYONE}` : `@${candidate.name}`;
    const next = `${text.slice(0, start)}${label} ${text.slice(caret)}`;

    return {
        text: next,
        caret: start + label.length + 1,
        mention:
            candidate.kind === 'person'
                ? { id: candidate.id, name: candidate.name }
                : null,
    };
}
