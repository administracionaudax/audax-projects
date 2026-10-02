import type { ReactNode } from 'react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Markdown ligero de los mensajes (D-069): **negrita** (o __negrita__), *cursiva* (o _cursiva_),
 * `código`, bloques ```código```, [enlaces](https://…) y URLs sueltas, menciones <@ID> (se pintan
 * como @Nombre) y @todos.
 *
 * SANEADO por construcción: el texto se convierte en nodos y se pinta con elementos de React
 * (que escapan todo); NUNCA se inserta HTML. Los enlaces solo admiten http, https y mailto, y se
 * abren en otra pestaña con rel="noopener noreferrer nofollow". Lo que no es un enlace válido se
 * queda como texto. Mismo criterio de enlaces que el servidor (App\Domain\Chat\Links\FirstLink).
 */

export type MarkdownNode =
    | { type: 'text'; value: string }
    | { type: 'strong'; children: MarkdownNode[] }
    | { type: 'em'; children: MarkdownNode[] }
    | { type: 'code'; value: string }
    | { type: 'codeblock'; value: string }
    | { type: 'link'; href: string; label: string }
    | { type: 'mention'; id: number }
    | { type: 'everyone' };

/** Nombre de cada persona mencionable (participantes y personas citadas en los mensajes). */
export type MentionNames = ReadonlyMap<number, string>;

const MAX_DEPTH = 4;

const WORD = /[\p{L}\p{N}]/u;

/** URL segura para un enlace (http, https o mailto) o null. */
export function safeHref(raw: string): string | null {
    const value = raw.trim();

    if (!/^(https?:\/\/|mailto:)/i.test(value)) {
        return null;
    }

    try {
        const url = new URL(value);

        return ['http:', 'https:', 'mailto:'].includes(url.protocol)
            ? value
            : null;
    } catch {
        return null;
    }
}

/** Quita la puntuación final que no es de la URL y los paréntesis de cierre sin abrir. */
export function trimUrl(url: string): string {
    let result = url;

    while (result.length > 0) {
        const last = result[result.length - 1];

        if ('.,;:!?\'"'.includes(last)) {
            result = result.slice(0, -1);
            continue;
        }

        if (
            last === ')' &&
            (result.match(/\(/g)?.length ?? 0) <
                (result.match(/\)/g)?.length ?? 0)
        ) {
            result = result.slice(0, -1);
            continue;
        }

        break;
    }

    return result;
}

function isWord(char: string | undefined): boolean {
    return char !== undefined && WORD.test(char);
}

function isSpace(char: string | undefined): boolean {
    return char === undefined || /\s/.test(char);
}

/** Longitud de la URL suelta que empieza en i (0 si no hay). */
function urlAt(src: string, i: number): number {
    if (isWord(src[i - 1]) || !/^https?:\/\//i.test(src.slice(i, i + 8))) {
        return 0;
    }

    const match = /^https?:\/\/[^\s<>"'`]+/i.exec(src.slice(i));

    return match ? trimUrl(match[0]).length : 0;
}

/** ¿Abre énfasis? Al principio de una palabra y seguido de algo que no es un espacio. */
function canOpen(src: string, i: number, delimiter: string): boolean {
    const before = src[i - 1];
    const after = src[i + delimiter.length];

    return (
        !isWord(before) &&
        before !== delimiter[0] &&
        !isSpace(after) &&
        after !== delimiter[0]
    );
}

/**
 * Cierre del énfasis cuyo contenido empieza en `contentStart` (o -1), con al menos un carácter
 * dentro y saltando el código y las URLs (sus _ y * no cuentan).
 */
function findClose(
    src: string,
    contentStart: number,
    delimiter: string,
): number {
    for (let j = contentStart + 1; j < src.length; j++) {
        const char = src[j];

        if (char === '`') {
            const end = src.indexOf('`', j + 1);

            if (end !== -1) {
                j = end;
                continue;
            }
        }

        const url = urlAt(src, j);

        if (url > 0) {
            j += url - 1;
            continue;
        }

        if (
            src.startsWith(delimiter, j) &&
            !isSpace(src[j - 1]) &&
            !isWord(src[j + delimiter.length]) &&
            src[j + delimiter.length] !== delimiter[0] &&
            src[j - 1] !== delimiter[0]
        ) {
            return j;
        }
    }

    return -1;
}

function parseInline(src: string, depth: number): MarkdownNode[] {
    const nodes: MarkdownNode[] = [];
    let text = '';
    let i = 0;

    const flush = () => {
        if (text !== '') {
            nodes.push({ type: 'text', value: text });
            text = '';
        }
    };

    while (i < src.length) {
        const char = src[i];

        if (char === '`') {
            const end = src.indexOf('`', i + 1);

            if (end > i + 1 && !src.slice(i + 1, end).includes('\n')) {
                flush();
                nodes.push({ type: 'code', value: src.slice(i + 1, end) });
                i = end + 1;
                continue;
            }
        }

        if (char === '<') {
            const mention = /^<@(\d{1,10})>/.exec(src.slice(i, i + 14));

            if (mention) {
                flush();
                nodes.push({ type: 'mention', id: Number(mention[1]) });
                i += mention[0].length;
                continue;
            }
        }

        if (char === '[') {
            const link = /^\[([^\]\n]{1,500})\]\(([^\s)]+)\)/.exec(
                src.slice(i),
            );
            const href = link ? safeHref(link[2]) : null;

            if (link && href) {
                flush();
                nodes.push({ type: 'link', href, label: link[1] });
                i += link[0].length;
                continue;
            }
        }

        if (char === 'h' || char === 'H') {
            const length = urlAt(src, i);
            const url = src.slice(i, i + length);
            const href = length > 0 ? safeHref(url) : null;

            if (href) {
                flush();
                nodes.push({ type: 'link', href, label: url });
                i += length;
                continue;
            }
        }

        if (
            char === '@' &&
            !isWord(src[i - 1]) &&
            /^@todos(?![\p{L}\p{N}_])/iu.test(src.slice(i, i + 7))
        ) {
            flush();
            nodes.push({ type: 'everyone' });
            i += 6;
            continue;
        }

        if ((char === '*' || char === '_') && depth < MAX_DEPTH) {
            const delimiter = src[i + 1] === char ? char + char : char;

            if (canOpen(src, i, delimiter)) {
                const close = findClose(src, i + delimiter.length, delimiter);

                if (close !== -1) {
                    flush();
                    nodes.push({
                        type: delimiter.length === 2 ? 'strong' : 'em',
                        children: parseInline(
                            src.slice(i + delimiter.length, close),
                            depth + 1,
                        ),
                    });
                    i = close + delimiter.length;
                    continue;
                }
            }

            // Sin cierre: la marca se queda como texto (entera, para no abrir con la segunda).
            text += delimiter;
            i += delimiter.length;
            continue;
        }

        text += char;
        i++;
    }

    flush();

    return nodes;
}

/** Nodos del cuerpo de un mensaje. */
export function parseMarkdown(src: string): MarkdownNode[] {
    const nodes: MarkdownNode[] = [];
    let i = 0;

    while (i < src.length) {
        const start = src.indexOf('```', i);
        const end = start === -1 ? -1 : src.indexOf('```', start + 3);

        if (start === -1 || end === -1) {
            nodes.push(...parseInline(src.slice(i), 0));
            break;
        }

        if (start > i) {
            nodes.push(...parseInline(src.slice(i, start), 0));
        }

        let code = src.slice(start + 3, end);
        const newline = code.indexOf('\n');

        // ```lenguaje en la primera línea: no se pinta.
        if (newline !== -1 && /^[\w+#.-]*$/.test(code.slice(0, newline))) {
            code = code.slice(newline + 1);
        }

        nodes.push({ type: 'codeblock', value: code.replace(/\n$/, '') });
        i = end + 3;

        if (src[i] === '\n') {
            i++;
        }
    }

    return nodes;
}

function mentionName(names: MentionNames, id: number): string {
    return names.get(id) ?? t('chat.mention.unknown');
}

/** Texto plano (vistas previas, citas, portapapeles): sin marcas y con @Nombre. */
export function markdownToPlainText(src: string, names: MentionNames): string {
    const walk = (nodes: MarkdownNode[]): string =>
        nodes
            .map((node) => {
                switch (node.type) {
                    case 'text':
                    case 'code':
                    case 'codeblock':
                        return node.value;
                    case 'strong':
                    case 'em':
                        return walk(node.children);
                    case 'link':
                        return node.label;
                    case 'mention':
                        return `@${mentionName(names, node.id)}`;
                    case 'everyone':
                        return '@todos';
                }
            })
            .join('');

    return walk(parseMarkdown(src)).replace(/\s+/g, ' ').trim();
}

function renderNodes(
    nodes: MarkdownNode[],
    names: MentionNames,
    currentUserId: number | null,
    prefix: string,
): ReactNode[] {
    return nodes.map((node, index) => {
        const key = `${prefix}-${index}`;

        switch (node.type) {
            case 'text':
                return node.value;
            case 'strong':
                return (
                    <strong key={key} className="font-medium">
                        {renderNodes(node.children, names, currentUserId, key)}
                    </strong>
                );
            case 'em':
                return (
                    <em key={key}>
                        {renderNodes(node.children, names, currentUserId, key)}
                    </em>
                );
            case 'code':
                return (
                    <code
                        key={key}
                        className="rounded-[3px] bg-muted px-1 py-0.5 font-mono text-[0.85em] text-foreground"
                    >
                        {node.value}
                    </code>
                );
            case 'codeblock':
                return (
                    <pre
                        key={key}
                        className="my-1 overflow-x-auto rounded-[3px] bg-muted p-2 font-mono text-[0.85em] whitespace-pre text-foreground"
                    >
                        <code>{node.value}</code>
                    </pre>
                );
            case 'link':
                return (
                    <a
                        key={key}
                        href={node.href}
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        className={cn(
                            'break-all text-primary-text underline underline-offset-2',
                            FOCUS_RING,
                        )}
                    >
                        {node.label}
                    </a>
                );
            case 'mention':
                return (
                    <span
                        key={key}
                        data-mention={node.id}
                        className={cn(
                            'rounded-[3px] px-0.5',
                            node.id === currentUserId
                                ? 'bg-info-soft text-info'
                                : 'text-primary-text',
                        )}
                    >
                        @{mentionName(names, node.id)}
                    </span>
                );
            case 'everyone':
                return (
                    <span
                        key={key}
                        data-mention="everyone"
                        className="rounded-[3px] bg-warning-soft px-0.5 text-warning"
                    >
                        @todos
                    </span>
                );
        }
    });
}

/** Cuerpo de un mensaje pintado (saneado: solo elementos de React). */
export function MarkdownText({
    body,
    names,
    currentUserId = null,
    className,
}: {
    body: string;
    names: MentionNames;
    currentUserId?: number | null;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'text-sm break-words whitespace-pre-wrap text-foreground',
                className,
            )}
        >
            {renderNodes(parseMarkdown(body), names, currentUserId, 'md')}
        </div>
    );
}

/** ¿El cuerpo menciona a esta persona (o a todos)? */
export function mentionsUser(body: string | null, userId: number): boolean {
    if (!body) {
        return false;
    }

    const visit = (nodes: MarkdownNode[]): boolean =>
        nodes.some((node) => {
            if (node.type === 'mention') {
                return node.id === userId;
            }

            if (node.type === 'everyone') {
                return true;
            }

            return node.type === 'strong' || node.type === 'em'
                ? visit(node.children)
                : false;
        });

    return visit(parseMarkdown(body));
}
