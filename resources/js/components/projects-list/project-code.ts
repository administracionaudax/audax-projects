/**
 * Código de proyecto sugerido (SPEC §4.2: «ACME-WEB»): primera palabra significativa del cliente
 * y del nombre, sin acentos, en mayúsculas y de 8 letras como mucho cada una. Gemelo de
 * App\Domain\Projects\ProjectCodeSuggester::suggest (mismos casos en los tests). El servidor
 * comprueba que sea único y, si no, sugiere otro con sufijo (ACME-WEB-2).
 */

export const PROJECT_CODE_MAX_LENGTH = 20;

const PART_LENGTH = 8;

const STOPWORDS = new Set([
    'A',
    'AL',
    'CON',
    'DE',
    'DEL',
    'E',
    'EL',
    'EN',
    'LA',
    'LAS',
    'LOS',
    'O',
    'PARA',
    'POR',
    'U',
    'UN',
    'UNA',
    'Y',
    'THE',
    'OF',
    'AND',
]);

function ascii(text: string): string {
    return text.normalize('NFD').replace(/\p{Diacritic}/gu, '');
}

function keyword(text: string | null | undefined): string {
    const words = ascii(text ?? '')
        .toUpperCase()
        .split(/[^A-Z0-9]+/)
        .filter((word) => word !== '');

    const word = words.find((candidate) => !STOPWORDS.has(candidate));

    return word ? word.slice(0, PART_LENGTH) : '';
}

export function suggestProjectCode(
    clientName: string | null | undefined,
    projectName: string,
): string {
    const parts = [keyword(clientName), keyword(projectName)].filter(
        (part) => part !== '',
    );

    if (parts.length === 2 && parts[0] === parts[1]) {
        parts.pop();
    }

    return parts.length > 0 ? parts.join('-') : '';
}

/** Lo que escribe el usuario, como lo guardará el servidor: mayúsculas, sin acentos, guiones. */
export function normalizeProjectCode(code: string): string {
    return ascii(code)
        .toUpperCase()
        .replace(/\s+/g, '-')
        .slice(0, PROJECT_CODE_MAX_LENGTH);
}
