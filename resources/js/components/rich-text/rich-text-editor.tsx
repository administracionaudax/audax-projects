import Link from '@tiptap/extension-link';
import Mention from '@tiptap/extension-mention';
import Placeholder from '@tiptap/extension-placeholder';
import {
    EditorContent,
    ReactRenderer,
    useEditor,
    useEditorState,
} from '@tiptap/react';
import type { Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import type { SuggestionOptions, SuggestionProps } from '@tiptap/suggestion';
import {
    Bold,
    Heading3,
    Heading4,
    Italic,
    Link2,
    List,
    ListOrdered,
    Minus,
    Quote,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { MentionList } from '@/components/rich-text/mention-list';
import type {
    MentionItem,
    MentionListHandle,
    MentionListProps,
} from '@/components/rich-text/mention-list';
import { RICH_TEXT_CLASSES } from '@/components/rich-text/rich-text-content';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type RichTextEditorProps = {
    /** HTML inicial (ya saneado por el servidor). */
    value: string;
    onChange: (html: string) => void;
    /** Personas que se pueden mencionar con @ (internos activos). */
    mentionables: MentionItem[];
    placeholder?: string;
    'aria-label': string;
    autoFocus?: boolean;
    /** Ctrl/Cmd + Intro. */
    onSubmit?: () => void;
    className?: string;
};

const MAX_SUGGESTIONS = 8;

function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

/**
 * Sugerencias de menciones: la lista se pinta dentro del propio editor (y no en <body>) para que
 * funcione dentro del panel lateral, que es un diálogo modal.
 */
function mentionSuggestion(
    getPeople: () => MentionItem[],
): Omit<SuggestionOptions<MentionItem>, 'editor'> {
    return {
        items: ({ query }) => {
            const needle = normalize(query);

            return getPeople()
                .filter((person) => normalize(person.name).includes(needle))
                .slice(0, MAX_SUGGESTIONS);
        },
        render: () => {
            let renderer: ReactRenderer<
                MentionListHandle,
                MentionListProps
            > | null = null;

            const place = (props: SuggestionProps<MentionItem>) => {
                const host =
                    props.editor.view.dom.closest<HTMLElement>(
                        '[data-rich-text]',
                    );
                const rect = props.clientRect?.();

                if (!renderer || !host || !rect) {
                    return;
                }

                const hostRect = host.getBoundingClientRect();

                Object.assign(renderer.element.style, {
                    position: 'absolute',
                    left: `${Math.max(0, rect.left - hostRect.left)}px`,
                    top: `${rect.bottom - hostRect.top + 4}px`,
                    zIndex: '60',
                });
            };

            return {
                onStart: (props) => {
                    renderer = new ReactRenderer(MentionList, {
                        props: { items: props.items, command: props.command },
                        editor: props.editor,
                    });

                    const host =
                        props.editor.view.dom.closest<HTMLElement>(
                            '[data-rich-text]',
                        ) ?? document.body;
                    host.appendChild(renderer.element);
                    place(props);
                },
                onUpdate: (props) => {
                    renderer?.updateProps({
                        items: props.items,
                        command: props.command,
                    });
                    place(props);
                },
                onKeyDown: ({ event }) => {
                    if (event.key === 'Escape') {
                        renderer?.destroy();
                        renderer = null;

                        return true;
                    }

                    return renderer?.ref?.onKeyDown(event) ?? false;
                },
                onExit: () => {
                    renderer?.destroy();
                    renderer = null;
                },
            };
        },
    };
}

function ToolbarButton({
    label,
    active,
    onClick,
    children,
}: {
    label: string;
    active?: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            aria-pressed={active}
            title={label}
            className={cn(
                'rounded-md p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground',
                active && 'bg-accent text-foreground',
                FOCUS_RING,
            )}
        >
            {children}
        </button>
    );
}

function LinkButton({ editor }: { editor: Editor }) {
    const inputId = useId();
    const [open, setOpen] = useState(false);
    const [href, setHref] = useState('');
    const active = editor.isActive('link');

    const apply = () => {
        const value = href.trim();

        if (value === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
        } else {
            const url = /^(https?:|mailto:)/i.test(value)
                ? value
                : `https://${value}`;
            editor
                .chain()
                .focus()
                .extendMarkRange('link')
                .setLink({ href: url })
                .run();
        }

        setOpen(false);
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    setHref(String(editor.getAttributes('link').href ?? ''));
                }
            }}
        >
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-label={t('rich_text.link')}
                    aria-pressed={active}
                    title={t('rich_text.link')}
                    className={cn(
                        'rounded-md p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground',
                        active && 'bg-accent text-foreground',
                        FOCUS_RING,
                    )}
                >
                    <Link2 aria-hidden="true" className="size-4" />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="grid w-72 gap-2 p-3">
                <label htmlFor={inputId} className="text-sm font-medium">
                    {t('rich_text.link_url')}
                </label>
                <Input
                    id={inputId}
                    value={href}
                    placeholder="https://"
                    onChange={(event) => setHref(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            apply();
                        }
                    }}
                />
                <div className="flex justify-end gap-2">
                    {active ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setHref('');
                                editor
                                    .chain()
                                    .focus()
                                    .extendMarkRange('link')
                                    .unsetLink()
                                    .run();
                                setOpen(false);
                            }}
                        >
                            {t('rich_text.link_remove')}
                        </Button>
                    ) : null}
                    <Button type="button" size="sm" onClick={apply}>
                        {t('rich_text.link_apply')}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}

/**
 * Editor de texto enriquecido (Tiptap: StarterKit + Link + Placeholder + Mention) para la
 * descripción de las tareas y los comentarios. Devuelve HTML que el servidor sanea SIEMPRE
 * (App\Support\RichText). Se carga bajo demanda (import dinámico) para no pesar en la página.
 */
export default function RichTextEditor({
    value,
    onChange,
    mentionables,
    placeholder,
    'aria-label': ariaLabel,
    autoFocus = false,
    onSubmit,
    className,
}: RichTextEditorProps) {
    const people = useRef(mentionables);
    const submit = useRef(onSubmit);
    const change = useRef(onChange);

    useEffect(() => {
        people.current = mentionables;
        submit.current = onSubmit;
        change.current = onChange;
    });

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                heading: { levels: [3, 4] },
                link: false,
            }),
            Link.configure({
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
                defaultProtocol: 'https',
                HTMLAttributes: {
                    rel: 'noopener noreferrer nofollow',
                    target: '_blank',
                },
            }),
            Placeholder.configure({ placeholder: placeholder ?? '' }),
            Mention.configure({
                suggestion: mentionSuggestion(() => people.current),
            }),
        ],
        content: value,
        autofocus: autoFocus ? 'end' : false,
        immediatelyRender: true,
        editorProps: {
            attributes: {
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-label': ariaLabel,
                class: cn(
                    RICH_TEXT_CLASSES,
                    'min-h-24 px-3 py-2 outline-none',
                    '[&_.is-editor-empty:first-child]:before:pointer-events-none [&_.is-editor-empty:first-child]:before:float-left [&_.is-editor-empty:first-child]:before:h-0 [&_.is-editor-empty:first-child]:before:text-muted-foreground [&_.is-editor-empty:first-child]:before:content-[attr(data-placeholder)]',
                ),
            },
            handleKeyDown: (_view, event) => {
                if (
                    event.key === 'Enter' &&
                    (event.metaKey || event.ctrlKey) &&
                    submit.current
                ) {
                    event.preventDefault();
                    submit.current();

                    return true;
                }

                return false;
            },
        },
        onUpdate: ({ editor: current }) => {
            change.current(current.isEmpty ? '' : current.getHTML());
        },
    });

    const state = useEditorState({
        editor,
        selector: ({ editor: current }) => ({
            bold: current?.isActive('bold') ?? false,
            italic: current?.isActive('italic') ?? false,
            title: current?.isActive('heading', { level: 3 }) ?? false,
            subtitle: current?.isActive('heading', { level: 4 }) ?? false,
            bulletList: current?.isActive('bulletList') ?? false,
            orderedList: current?.isActive('orderedList') ?? false,
            blockquote: current?.isActive('blockquote') ?? false,
        }),
    });

    return (
        <div
            data-rich-text
            className={cn(
                'relative rounded-md border border-input bg-background focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-background',
                className,
            )}
        >
            {editor ? (
                <div
                    role="toolbar"
                    aria-label={t('rich_text.toolbar')}
                    className="flex flex-wrap items-center gap-0.5 border-b px-1 py-1"
                >
                    <ToolbarButton
                        label={t('rich_text.bold')}
                        active={state?.bold}
                        onClick={() =>
                            editor.chain().focus().toggleBold().run()
                        }
                    >
                        <Bold aria-hidden="true" className="size-4" />
                    </ToolbarButton>
                    <ToolbarButton
                        label={t('rich_text.italic')}
                        active={state?.italic}
                        onClick={() =>
                            editor.chain().focus().toggleItalic().run()
                        }
                    >
                        <Italic aria-hidden="true" className="size-4" />
                    </ToolbarButton>
                    {/* Título, Subtítulo y Divisor (F-154, 10.9b), como la barra de WeeklySync. */}
                    <ToolbarButton
                        label={t('rich_text.title')}
                        active={state?.title}
                        onClick={() =>
                            editor
                                .chain()
                                .focus()
                                .toggleHeading({ level: 3 })
                                .run()
                        }
                    >
                        <Heading3 aria-hidden="true" className="size-4" />
                    </ToolbarButton>
                    <ToolbarButton
                        label={t('rich_text.subtitle')}
                        active={state?.subtitle}
                        onClick={() =>
                            editor
                                .chain()
                                .focus()
                                .toggleHeading({ level: 4 })
                                .run()
                        }
                    >
                        <Heading4 aria-hidden="true" className="size-4" />
                    </ToolbarButton>
                    <ToolbarButton
                        label={t('rich_text.bullet_list')}
                        active={state?.bulletList}
                        onClick={() =>
                            editor.chain().focus().toggleBulletList().run()
                        }
                    >
                        <List aria-hidden="true" className="size-4" />
                    </ToolbarButton>
                    <ToolbarButton
                        label={t('rich_text.ordered_list')}
                        active={state?.orderedList}
                        onClick={() =>
                            editor.chain().focus().toggleOrderedList().run()
                        }
                    >
                        <ListOrdered aria-hidden="true" className="size-4" />
                    </ToolbarButton>
                    <ToolbarButton
                        label={t('rich_text.quote')}
                        active={state?.blockquote}
                        onClick={() =>
                            editor.chain().focus().toggleBlockquote().run()
                        }
                    >
                        <Quote aria-hidden="true" className="size-4" />
                    </ToolbarButton>
                    <ToolbarButton
                        label={t('rich_text.divider')}
                        onClick={() =>
                            editor.chain().focus().setHorizontalRule().run()
                        }
                    >
                        <Minus aria-hidden="true" className="size-4" />
                    </ToolbarButton>
                    <LinkButton editor={editor} />
                    <span className="ml-auto px-1 text-xs text-muted-foreground">
                        {t('rich_text.mention_hint')}
                    </span>
                </div>
            ) : null}
            <EditorContent editor={editor} />
        </div>
    );
}
