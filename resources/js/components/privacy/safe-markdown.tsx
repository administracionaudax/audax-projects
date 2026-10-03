import type { ReactNode } from 'react';
import { useMemo } from 'react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { isExternalHref, parseMarkdown } from '@/lib/markdown';
import type { MarkdownBlock, MarkdownInline } from '@/lib/markdown';
import { cn } from '@/lib/utils';

type HeadingTag = 'h2' | 'h3' | 'h4' | 'h5' | 'h6';

const HEADING_CLASSES: Record<HeadingTag, string> = {
    h2: 'text-xl',
    h3: 'text-lg',
    h4: 'text-base',
    h5: 'text-base',
    h6: 'text-sm',
};

/**
 * Pinta el markdown del texto de privacidad (D-075) con elementos de React: el árbol sale de
 * lib/markdown.ts y nada se inserta como HTML. Los encabezados se reordenan para empezar en
 * `headingLevel` (la página ya tiene su h1) sin saltos. La negrita es un énfasis visual con peso
 * 500 (nunca negritas pesadas, SPEC §3.1).
 */
export function SafeMarkdown({
    source,
    headingLevel = 2,
    className,
}: {
    source: string;
    /** Nivel del encabezado más alto del texto (2 = h2). */
    headingLevel?: 2 | 3;
    className?: string;
}) {
    const blocks = useMemo(() => parseMarkdown(source), [source]);
    const minLevel = Math.min(
        6,
        ...blocks
            .filter((block) => block.type === 'heading')
            .map((block) => block.level),
    );

    return (
        <div
            className={cn(
                'space-y-4 text-sm leading-relaxed text-foreground md:text-base',
                className,
            )}
        >
            {renderBlocks(blocks, { base: headingLevel, minLevel })}
        </div>
    );
}

type Levels = { base: number; minLevel: number };

function renderBlocks(blocks: MarkdownBlock[], levels: Levels): ReactNode[] {
    return blocks.map((block, index) => renderBlock(block, index, levels));
}

function renderBlock(
    block: MarkdownBlock,
    key: number,
    levels: Levels,
): ReactNode {
    switch (block.type) {
        case 'heading': {
            const level = Math.min(
                6,
                levels.base + Math.max(0, block.level - levels.minLevel),
            );
            const Tag = `h${level}` as HeadingTag;

            return (
                <Tag
                    key={key}
                    className={cn(
                        'pt-2 leading-snug font-normal tracking-tight',
                        HEADING_CLASSES[Tag],
                    )}
                >
                    {renderInline(block.children)}
                </Tag>
            );
        }
        case 'paragraph':
            return <p key={key}>{renderInline(block.children)}</p>;
        case 'list': {
            const items = block.items.map((item, index) => (
                <li key={index} className="space-y-2 pl-1">
                    {item.length === 1 && item[0].type === 'paragraph'
                        ? renderInline(item[0].children)
                        : renderBlocks(item, levels)}
                </li>
            ));

            return block.ordered ? (
                <ol
                    key={key}
                    start={block.start}
                    className="list-decimal space-y-1.5 pl-6"
                >
                    {items}
                </ol>
            ) : (
                <ul key={key} className="list-disc space-y-1.5 pl-6">
                    {items}
                </ul>
            );
        }
        case 'quote':
            return (
                <blockquote
                    key={key}
                    className="space-y-2 rounded-r-md border-l-2 border-primary bg-muted px-4 py-3 text-foreground"
                >
                    {renderBlocks(block.children, levels)}
                </blockquote>
            );
        case 'rule':
            return <hr key={key} className="border-border" />;
    }
}

function renderInline(nodes: MarkdownInline[]): ReactNode[] {
    return nodes.map((node, index) => {
        switch (node.type) {
            case 'text':
                return node.text;
            case 'break':
                return <br key={index} />;
            case 'strong':
                return (
                    <strong key={index} className="font-medium">
                        {renderInline(node.children)}
                    </strong>
                );
            case 'emphasis':
                return (
                    <em key={index} className="italic">
                        {renderInline(node.children)}
                    </em>
                );
            case 'code':
                return (
                    <code
                        key={index}
                        className="rounded-md bg-muted px-1 py-0.5 font-mono text-[0.9em]"
                    >
                        {node.text}
                    </code>
                );
            case 'link': {
                const external = isExternalHref(node.href);

                return (
                    <a
                        key={index}
                        href={node.href}
                        rel="noopener noreferrer"
                        target={external ? '_blank' : undefined}
                        className={cn(
                            'rounded-xs text-primary-text underline decoration-primary-text/40 underline-offset-4 hover:decoration-current',
                            FOCUS_RING,
                        )}
                    >
                        {renderInline(node.children)}
                        {external ? (
                            <span className="sr-only">
                                {` ${t('privacy.markdown.new_tab')}`}
                            </span>
                        ) : null}
                    </a>
                );
            }
        }
    });
}
