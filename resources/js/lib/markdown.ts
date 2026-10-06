/**
 * Markdown SANEADO del texto de privacidad (D-075). Convierte un subconjunto pequeño de markdown
 * en un árbol de nodos que se pinta con elementos de React (components/privacy/safe-markdown.tsx):
 * nunca se genera HTML ni se usa dangerouslySetInnerHTML.
 *
 * - Bloques: encabezados (#), párrafos, listas (-, *, + y 1.), citas (>) y separadores (---).
 * - En línea: **negrita** / __negrita__, *cursiva* / _cursiva_, `código`, [enlaces](url),
 *   <https://autoenlaces> y direcciones http(s) sueltas.
 * - Enlaces: solo http(s), mailto, rutas de la propia app (/…) y anclas (#…). Un enlace con otro
 *   destino (javascript:, data:, //otro-sitio…) se queda en su texto, sin enlace.
 * - El HTML crudo (<script>, <img onerror>…) no se interpreta: queda como texto visible.
 */

export type MarkdownInline =
    | { type: 'text'; text: string }
    | { type: 'strong'; children: MarkdownInline[] }
    | { type: 'emphasis'; children: MarkdownInline[] }
    | { type: 'code'; text: string }
    | { type: 'link'; href: string; children: MarkdownInline[] }
    | { type: 'break' };

export type MarkdownBlock =
    | { type: 'heading'; level: number; children: MarkdownInline[] }
    | { type: 'paragraph'; children: MarkdownInline[] }
    | {
          type: 'list';
          ordered: boolean;
          start: number;
          items: MarkdownBlock[][];
      }
    | { type: 'quote'; children: MarkdownBlock[] }
    | { type: 'rule' };

/** Profundidad máxima de citas y listas anidadas (evita recursiones enormes). */
const MAX_DEPTH = 8;

const HEADING = /^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/;
const RULE = /^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/;
const QUOTE = /^ {0,3}>[ \t]?(.*)$/;
const LIST_ITEM = /^( {0,3})([-*+]|\d{1,9}[.)])(?:[ \t]+(.*))?$/;
const PUNCTUATION = /[!"#$%&'()*+,\-./:;<=>?@[\\\]^_`{|}~]/;

/** Caracteres de control (U+0000 a U+001F y U+007F). */
function hasControlCharacters(value: string): boolean {
    for (let index = 0; index < value.length; index++) {
        const code = value.charCodeAt(index);

        if (code < 0x20 || code === 0x7f) {
            return true;
        }
    }

    return false;
}

/**
 * Destino de enlace permitido, o null. Rechaza caracteres de control (con los que se puede
 * esconder «javascript:») y cualquier esquema que no sea http, https o mailto.
 */
export function safeHref(raw: string): string | null {
    const href = raw.trim();

    if (href === '' || hasControlCharacters(href)) {
        return null;
    }

    if (/^(https?:\/\/|mailto:)/i.test(href)) {
        return href;
    }

    if (
        href.startsWith('#') ||
        (href.startsWith('/') &&
            !href.startsWith('//') &&
            !href.startsWith('/\\'))
    ) {
        return href;
    }

    return null;
}

/** El destino lleva a otro sitio (se abre en otra pestaña). */
export function isExternalHref(href: string): boolean {
    return /^https?:\/\//i.test(href);
}

/**
 * Los enlaces pasan a texto sin enlace, con su dominio visible entre paréntesis (D-224): para lo que
 * escribe la IA a partir de textos de la plantilla, que podrían colar un enlace de phishing.
 */
export function withoutLinks(blocks: MarkdownBlock[]): MarkdownBlock[] {
    const inline = (nodes: MarkdownInline[]): MarkdownInline[] =>
        nodes.flatMap((node): MarkdownInline[] => {
            switch (node.type) {
                case 'link': {
                    const domain = linkDomain(node.href);

                    return [
                        ...inline(node.children),
                        ...(domain
                            ? [{ type: 'text' as const, text: ` (${domain})` }]
                            : []),
                    ];
                }
                case 'strong':
                case 'emphasis':
                    return [{ ...node, children: inline(node.children) }];
                default:
                    return [node];
            }
        });

    return blocks.map((block): MarkdownBlock => {
        switch (block.type) {
            case 'heading':
            case 'paragraph':
                return { ...block, children: inline(block.children) };
            case 'list':
                return { ...block, items: block.items.map(withoutLinks) };
            case 'quote':
                return { ...block, children: withoutLinks(block.children) };
            default:
                return block;
        }
    });
}

/** El dominio de un enlace absoluto («ejemplo.com»); el texto tal cual si no lo es. */
export function linkDomain(href: string): string {
    try {
        return isExternalHref(href) ? new URL(href).hostname : href;
    } catch {
        return href;
    }
}

export function parseMarkdown(source: string): MarkdownBlock[] {
    const lines = source
        .replace(/\r\n?/g, '\n')
        .replace(/\t/g, '    ')
        .split('\n');

    return parseBlocks(lines, 0);
}

function isBlank(line: string): boolean {
    return line.trim() === '';
}

/** ¿La línea empieza un bloque que corta un párrafo? */
function startsBlock(line: string): boolean {
    return (
        HEADING.test(line) ||
        RULE.test(line) ||
        QUOTE.test(line) ||
        LIST_ITEM.test(line)
    );
}

function parseBlocks(lines: string[], depth: number): MarkdownBlock[] {
    const blocks: MarkdownBlock[] = [];
    let index = 0;

    while (index < lines.length) {
        const line = lines[index];

        if (isBlank(line)) {
            index++;
            continue;
        }

        const heading = HEADING.exec(line);

        if (heading) {
            blocks.push({
                type: 'heading',
                level: heading[1].length,
                children: parseInline(heading[2] ?? ''),
            });
            index++;
            continue;
        }

        if (RULE.test(line)) {
            blocks.push({ type: 'rule' });
            index++;
            continue;
        }

        if (QUOTE.test(line) && depth < MAX_DEPTH) {
            const quoted: string[] = [];

            while (index < lines.length && !isBlank(lines[index])) {
                const match = QUOTE.exec(lines[index]);

                // Continuación perezosa: una línea sin «>» sigue en la cita si no empieza otro bloque.
                if (!match && startsBlock(lines[index])) {
                    break;
                }

                quoted.push(match ? match[1] : lines[index]);
                index++;
            }

            blocks.push({
                type: 'quote',
                children: parseBlocks(quoted, depth + 1),
            });
            continue;
        }

        const item = LIST_ITEM.exec(line);

        if (item && depth < MAX_DEPTH) {
            const [list, next] = parseList(lines, index, depth);
            blocks.push(list);
            index = next;
            continue;
        }

        const paragraph: string[] = [];

        while (index < lines.length && !isBlank(lines[index])) {
            if (paragraph.length > 0 && startsBlock(lines[index])) {
                break;
            }

            paragraph.push(lines[index]);
            index++;
        }

        blocks.push({
            type: 'paragraph',
            children: parseInline(paragraph.join('\n')),
        });
    }

    return blocks;
}

function leadingSpaces(line: string): number {
    return line.length - line.trimStart().length;
}

function markerKind(marker: string): 'bullet' | 'ordered' {
    return /^\d/.test(marker) ? 'ordered' : 'bullet';
}

/**
 * Una lista: elementos del mismo tipo seguidos (puede haber líneas en blanco entre ellos). El
 * contenido de cada elemento son su primera línea y las siguientes sangradas o de continuación,
 * y se analiza como bloques (así se admiten listas anidadas).
 */
function parseList(
    lines: string[],
    start: number,
    depth: number,
): [MarkdownBlock, number] {
    const first = LIST_ITEM.exec(lines[start]) as RegExpExecArray;
    const kind = markerKind(first[2]);
    const items: MarkdownBlock[][] = [];
    let index = start;

    while (index < lines.length) {
        const match = LIST_ITEM.exec(lines[index]);

        if (!match || markerKind(match[2]) !== kind) {
            break;
        }

        const indent = match[1].length + match[2].length + 1;
        const content: string[] = [match[3] ?? ''];
        index++;

        while (index < lines.length) {
            const current = lines[index];

            if (isBlank(current)) {
                // Una línea en blanco solo sigue en el elemento si lo siguiente está sangrado.
                const nextLine = lines[index + 1];

                if (
                    nextLine !== undefined &&
                    !isBlank(nextLine) &&
                    leadingSpaces(nextLine) >= indent
                ) {
                    content.push('');
                    index++;
                    continue;
                }

                break;
            }

            const leading = leadingSpaces(current);

            if (leading >= indent) {
                content.push(current.slice(indent));
                index++;
                continue;
            }

            if (leading >= 2 && LIST_ITEM.test(current.trimStart())) {
                // Sublista con menos sangría de la esperada: sigue siendo del elemento.
                content.push(current.trimStart());
                index++;
                continue;
            }

            if (!startsBlock(current)) {
                content.push(current.trim());
                index++;
                continue;
            }

            break;
        }

        items.push(parseBlocks(content, depth + 1));

        // Líneas en blanco entre elementos de la misma lista.
        let lookahead = index;

        while (lookahead < lines.length && isBlank(lines[lookahead])) {
            lookahead++;
        }

        const following = LIST_ITEM.exec(lines[lookahead] ?? '');

        if (
            lookahead > index &&
            following &&
            markerKind(following[2]) === kind
        ) {
            index = lookahead;
        }
    }

    return [
        {
            type: 'list',
            ordered: kind === 'ordered',
            start: kind === 'ordered' ? Number.parseInt(first[2], 10) : 1,
            items,
        },
        index,
    ];
}

/** Delimitadores de énfasis que abren (no seguidos de espacio). */
function canOpen(text: string, at: number, length: number): boolean {
    const next = text[at + length];

    return next !== undefined && !/\s/.test(next);
}

/** Delimitadores de énfasis que cierran (no precedidos de espacio). */
function canClose(text: string, at: number): boolean {
    const previous = text[at - 1];

    return previous !== undefined && !/\s/.test(previous);
}

function isWordChar(char: string | undefined): boolean {
    return char !== undefined && /[\p{L}\p{N}]/u.test(char);
}

/** Posición del delimitador que cierra el de `from`, o -1. */
function findClosing(text: string, from: number, delimiter: string): number {
    let at = from;

    while (at < text.length) {
        const found = text.indexOf(delimiter, at);

        if (found === -1) {
            return -1;
        }

        // Se salta un «\*» escapado.
        if (text[found - 1] === '\\') {
            at = found + delimiter.length;
            continue;
        }

        if (
            canClose(text, found) &&
            (delimiter[0] !== '_' ||
                !isWordChar(text[found + delimiter.length]))
        ) {
            // «*» suelto: que no sea parte de un «**».
            if (delimiter.length === 1 && text[found + 1] === delimiter) {
                at = found + 2;
                continue;
            }

            return found;
        }

        at = found + delimiter.length;
    }

    return -1;
}

/** Posición del «]» que cierra el «[» de `from` (admite corchetes anidados), o -1. */
function findBracket(text: string, from: number): number {
    let depth = 0;

    for (let at = from; at < text.length; at++) {
        const char = text[at];

        if (char === '\\') {
            at++;
            continue;
        }

        if (char === '[') {
            depth++;
        } else if (char === ']') {
            depth--;

            if (depth === 0) {
                return at;
            }
        }
    }

    return -1;
}

/** Destino de un enlace «(url "título")» que empieza en `from` (el «(»): [url, fin] o null. */
function linkDestination(text: string, from: number): [string, number] | null {
    if (text[from] !== '(') {
        return null;
    }

    let depth = 0;

    for (let at = from; at < text.length; at++) {
        const char = text[at];

        if (char === '\\') {
            at++;
            continue;
        }

        if (char === '(') {
            depth++;
        } else if (char === ')') {
            depth--;

            if (depth === 0) {
                const inside = text.slice(from + 1, at).trim();
                // Sin el título opcional: «url "título"».
                const url =
                    /^<([^>]*)>/.exec(inside)?.[1] ??
                    inside.split(/\s+/)[0] ??
                    '';

                return [url, at + 1];
            }
        } else if (char === '\n') {
            return null;
        }
    }

    return null;
}

const BARE_URL = /^https?:\/\/[^\s<>"]*[^\s<>".,;:!?'")\]]/i;
const AUTOLINK = /^<((?:https?:\/\/|mailto:)[^\s<>]+)>/i;

export function parseInline(text: string, depth = 0): MarkdownInline[] {
    const nodes: MarkdownInline[] = [];
    let buffer = '';

    const flush = () => {
        if (buffer !== '') {
            const last = nodes[nodes.length - 1];

            if (last?.type === 'text') {
                last.text += buffer;
            } else {
                nodes.push({ type: 'text', text: buffer });
            }

            buffer = '';
        }
    };

    const push = (node: MarkdownInline) => {
        flush();
        nodes.push(node);
    };

    let at = 0;

    while (at < text.length) {
        const char = text[at];

        // Escapes: «\*» es un asterisco.
        if (char === '\\' && at + 1 < text.length) {
            if (text[at + 1] === '\n') {
                push({ type: 'break' });
                at += 2;
                continue;
            }

            if (PUNCTUATION.test(text[at + 1])) {
                buffer += text[at + 1];
                at += 2;
                continue;
            }
        }

        // Saltos de línea: dos espacios al final → salto; si no, un espacio.
        if (char === '\n') {
            if (buffer.endsWith('  ')) {
                buffer = buffer.replace(/ +$/, '');
                push({ type: 'break' });
            } else {
                buffer = buffer.replace(/ +$/, '') + ' ';
            }

            at++;

            while (text[at] === ' ') {
                at++;
            }

            continue;
        }

        if (char === '`') {
            const run = /^`+/.exec(text.slice(at))?.[0] ?? '`';
            const closing = text.indexOf(run, at + run.length);

            if (closing !== -1) {
                push({
                    type: 'code',
                    text: text
                        .slice(at + run.length, closing)
                        .replace(/\n/g, ' ')
                        .trim(),
                });
                at = closing + run.length;
                continue;
            }

            buffer += run;
            at += run.length;
            continue;
        }

        if (char === '<') {
            const autolink = AUTOLINK.exec(text.slice(at));
            const href = autolink ? safeHref(autolink[1]) : null;

            if (autolink && href !== null) {
                push({
                    type: 'link',
                    href,
                    children: [
                        {
                            type: 'text',
                            text: autolink[1].replace(/^mailto:/i, ''),
                        },
                    ],
                });
                at += autolink[0].length;
                continue;
            }
        }

        if (char === '[' && depth < MAX_DEPTH) {
            const close = findBracket(text, at);
            const destination =
                close === -1 ? null : linkDestination(text, close + 1);

            if (close !== -1 && destination !== null) {
                const label = parseInline(text.slice(at + 1, close), depth + 1);
                const href = safeHref(destination[0]);

                if (href !== null) {
                    push({ type: 'link', href, children: label });
                } else {
                    // Destino no permitido (javascript:, data:…): solo el texto.
                    flush();
                    nodes.push(...label);
                }

                at = destination[1];
                continue;
            }
        }

        if (
            (char === 'h' || char === 'H') &&
            !isWordChar(text[at - 1]) &&
            depth < MAX_DEPTH
        ) {
            const bare = BARE_URL.exec(text.slice(at));

            if (bare) {
                push({
                    type: 'link',
                    href: bare[0],
                    children: [{ type: 'text', text: bare[0] }],
                });
                at += bare[0].length;
                continue;
            }
        }

        if ((char === '*' || char === '_') && depth < MAX_DEPTH) {
            const double = text[at + 1] === char;
            const delimiter = double ? char + char : char;
            const opens =
                canOpen(text, at, delimiter.length) &&
                (char !== '_' || !isWordChar(text[at - 1]));

            if (opens) {
                const closing = findClosing(
                    text,
                    at + delimiter.length,
                    delimiter,
                );

                if (closing !== -1) {
                    const children = parseInline(
                        text.slice(at + delimiter.length, closing),
                        depth + 1,
                    );
                    push(
                        double
                            ? { type: 'strong', children }
                            : { type: 'emphasis', children },
                    );
                    at = closing + delimiter.length;
                    continue;
                }
            }

            buffer += delimiter;
            at += delimiter.length;
            continue;
        }

        buffer += char;
        at++;
    }

    flush();

    return nodes;
}

/** Texto plano de unos nodos (para nombres accesibles y pruebas). */
export function inlineText(nodes: MarkdownInline[]): string {
    return nodes
        .map((node) => {
            switch (node.type) {
                case 'text':
                case 'code':
                    return node.text;
                case 'break':
                    return '\n';
                default:
                    return inlineText(node.children);
            }
        })
        .join('');
}
