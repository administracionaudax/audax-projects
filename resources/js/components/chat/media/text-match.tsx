/**
 * Coincidencias sin tildes ni mayúsculas, como la búsqueda del servidor (MatchesText y
 * MessageSource): «camion» encuentra «Camión». Las posiciones se refieren al texto original.
 */

export type TextRange = { start: number; end: number };

function foldChar(char: string): string {
    const base = char.normalize('NFD').replace(/\p{M}/gu, '');

    return (base === '' ? char : base).toLowerCase();
}

/** Texto plegado y, por cada unidad del plegado, el tramo del carácter original. */
function fold(text: string): {
    folded: string;
    starts: number[];
    ends: number[];
} {
    let folded = '';
    const starts: number[] = [];
    const ends: number[] = [];
    let position = 0;

    for (const char of text) {
        const start = position;
        const end = position + char.length;
        const piece = foldChar(char);

        for (let index = 0; index < piece.length; index++) {
            folded += piece[index];
            starts.push(start);
            ends.push(end);
        }

        position = end;
    }

    return { folded, starts, ends };
}

/** Todas las apariciones de `query` en `text` (sin solaparse). */
export function matchRanges(text: string, query: string): TextRange[] {
    const needle = fold(query.trim()).folded;

    if (needle === '' || text === '') {
        return [];
    }

    const { folded, starts, ends } = fold(text);
    const ranges: TextRange[] = [];
    let from = 0;

    while (from <= folded.length - needle.length) {
        const index = folded.indexOf(needle, from);

        if (index === -1) {
            break;
        }

        ranges.push({
            start: starts[index],
            end: ends[index + needle.length - 1],
        });
        from = index + needle.length;
    }

    return ranges;
}

/** Texto con las apariciones de `query` resaltadas (<mark>), sin HTML: todo es texto. */
export function HighlightedText({
    text,
    query,
    className,
}: {
    text: string;
    query: string;
    className?: string;
}) {
    const ranges = matchRanges(text, query);

    if (ranges.length === 0) {
        return <span className={className}>{text}</span>;
    }

    const parts: { text: string; mark: boolean }[] = [];
    let cursor = 0;

    for (const range of ranges) {
        if (range.start > cursor) {
            parts.push({ text: text.slice(cursor, range.start), mark: false });
        }

        parts.push({ text: text.slice(range.start, range.end), mark: true });
        cursor = range.end;
    }

    if (cursor < text.length) {
        parts.push({ text: text.slice(cursor), mark: false });
    }

    return (
        <span className={className}>
            {parts.map((part, index) =>
                part.mark ? (
                    <mark
                        key={index}
                        className="rounded-[3px] bg-warning-soft px-0.5 text-foreground"
                    >
                        {part.text}
                    </mark>
                ) : (
                    <span key={index}>{part.text}</span>
                ),
            )}
        </span>
    );
}
