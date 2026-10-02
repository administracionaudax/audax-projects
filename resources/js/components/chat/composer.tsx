import { Reply, SendHorizontal, Smile, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { ChatAvatar } from '@/components/chat/chat-avatar';
import { EmojiPopover } from '@/components/chat/emoji-popover';
import {
    editableToBody,
    insertMention,
    mentionCandidates,
    mentionQueryAt,
} from '@/components/chat/mentions';
import type { MentionCandidate, MentionRef } from '@/components/chat/mentions';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Editor de mensajes (SPEC §12): markdown ligero en un textarea, Intro envía y Mayús+Intro salta
 * de línea, autocompletado de menciones (@ → participantes y @todos) con teclado completo
 * (flechas, Intro o Tab para elegir, Esc para cerrar), selector de emojis y borrador por
 * conversación en localStorage. Con el cuadro vacío, ↑ edita el último mensaje propio.
 * En modo edición se usa dentro del mensaje (Intro guarda, Esc cancela).
 */

export const MAX_BODY = 10_000;

type Draft = { text: string; mentions: MentionRef[] };

const DRAFT_PREFIX = 'audax.chat.draft.';

export function draftKey(conversationId: number): string {
    return `${DRAFT_PREFIX}${conversationId}`;
}

export function readDraft(conversationId: number): Draft | null {
    try {
        const raw = window.localStorage.getItem(draftKey(conversationId));
        const parsed = raw ? (JSON.parse(raw) as Partial<Draft>) : null;

        return parsed && typeof parsed.text === 'string'
            ? {
                  text: parsed.text,
                  mentions: Array.isArray(parsed.mentions)
                      ? parsed.mentions
                      : [],
              }
            : null;
    } catch {
        return null;
    }
}

function writeDraft(conversationId: number, draft: Draft): void {
    try {
        if (draft.text.trim() === '') {
            window.localStorage.removeItem(draftKey(conversationId));
        } else {
            window.localStorage.setItem(
                draftKey(conversationId),
                JSON.stringify(draft),
            );
        }
    } catch {
        // Sin almacenamiento (modo privado, cuota): el borrador no se guarda.
    }
}

export type ComposerReply = { id: number; author: string; excerpt: string };

export type MentionPerson = { id: number; name: string; is_active: boolean };

export function Composer({
    conversationId,
    people,
    onSubmit,
    mode = 'new',
    initial,
    replyTo = null,
    onCancelReply,
    onCancel,
    onEditLast,
    onTyping,
    placeholder,
    autoFocus = false,
    disabled = false,
    mediaSlot,
}: {
    conversationId: number;
    /** Personas mencionables (participantes activos, sin quien escribe). */
    people: MentionPerson[];
    /** Envía el cuerpo (con <@ID>). En edición, se cierra si devuelve true. */
    onSubmit: (body: string) => Promise<boolean> | boolean;
    mode?: 'new' | 'edit';
    /** Texto inicial (edición). En modo «nuevo» se recupera el borrador. */
    initial?: Draft;
    replyTo?: ComposerReply | null;
    onCancelReply?: () => void;
    onCancel?: () => void;
    onEditLast?: () => void;
    onTyping?: () => void;
    placeholder?: string;
    autoFocus?: boolean;
    disabled?: boolean;
    /** Botones de adjuntar y grabar audio (área C3). */
    mediaSlot?: ReactNode;
}) {
    const [draft, setDraft] = useState<Draft>(
        () =>
            initial ??
            (mode === 'new' ? readDraft(conversationId) : null) ?? {
                text: '',
                mentions: [],
            },
    );
    const [query, setQuery] = useState<{ start: number; query: string } | null>(
        null,
    );
    const [active, setActive] = useState(0);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const caret = useRef(draft.text.length);
    const listId = useId();
    const hintId = useId();
    const replyId = useId();

    const candidates: MentionCandidate[] = query
        ? mentionCandidates(people, query.query, true)
        : [];
    const menuOpen = candidates.length > 0;
    const length = draft.text.trim().length;
    const tooLong = length > MAX_BODY;

    // Borrador por conversación (solo al escribir un mensaje nuevo).
    useEffect(() => {
        if (mode !== 'new') {
            return;
        }

        const timer = window.setTimeout(
            () => writeDraft(conversationId, draft),
            300,
        );

        return () => window.clearTimeout(timer);
    }, [conversationId, draft, mode]);

    // Altura según el contenido (hasta 8 líneas; después, scroll).
    useEffect(() => {
        const element = textarea.current;

        if (element) {
            element.style.height = 'auto';
            element.style.height = `${Math.min(element.scrollHeight, 192)}px`;
        }
    }, [draft.text]);

    useEffect(() => {
        if (autoFocus) {
            const element = textarea.current;
            element?.focus();
            element?.setSelectionRange(
                element.value.length,
                element.value.length,
            );
        }
    }, [autoFocus]);

    const syncQuery = (text: string, position: number) => {
        caret.current = position;
        const next = mentionQueryAt(text, position);
        setQuery(next);

        if (next?.query !== query?.query) {
            setActive(0);
        }
    };

    const placeCaret = (position: number) => {
        caret.current = position;
        window.requestAnimationFrame(() => {
            textarea.current?.focus();
            textarea.current?.setSelectionRange(position, position);
        });
    };

    const choose = (candidate: MentionCandidate) => {
        if (!query) {
            return;
        }

        const result = insertMention(
            draft.text,
            query.start,
            caret.current,
            candidate,
        );
        const mention = result.mention;
        setDraft((current) => ({
            text: result.text,
            mentions: mention
                ? [...current.mentions, mention]
                : current.mentions,
        }));
        setQuery(null);
        placeCaret(result.caret);
    };

    const insertText = (value: string) => {
        const position = Math.min(caret.current, draft.text.length);
        const text =
            draft.text.slice(0, position) + value + draft.text.slice(position);
        setDraft((current) => ({ ...current, text }));
        placeCaret(position + value.length);
    };

    const reset = () => {
        setDraft({ text: '', mentions: [] });
        setQuery(null);
        setError(null);

        if (mode === 'new') {
            writeDraft(conversationId, { text: '', mentions: [] });
        }
    };

    const submit = async () => {
        const text = draft.text.trim();

        if (text === '') {
            if (mode === 'edit') {
                setError(t('chat.composer.edit_empty'));
            }

            return;
        }

        if (tooLong || submitting || disabled) {
            return;
        }

        const body = editableToBody(text, draft.mentions);

        if (mode === 'new') {
            // Al momento: el mensaje aparece «Enviando…» y, si falla, se puede reintentar.
            reset();
            void onSubmit(body);

            return;
        }

        setSubmitting(true);

        try {
            if (await onSubmit(body)) {
                reset();
            }
        } finally {
            setSubmitting(false);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (menuOpen) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                setActive(
                    (current) =>
                        (current + step + candidates.length) %
                        candidates.length,
                );

                return;
            }

            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                choose(candidates[active] ?? candidates[0]);

                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                setQuery(null);

                return;
            }
        }

        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.altKey &&
            !event.nativeEvent.isComposing
        ) {
            event.preventDefault();
            void submit();

            return;
        }

        if (event.key === 'Escape') {
            if (mode === 'edit') {
                event.preventDefault();
                onCancel?.();
            } else if (replyTo) {
                event.preventDefault();
                onCancelReply?.();
            }

            return;
        }

        if (
            event.key === 'ArrowUp' &&
            draft.text === '' &&
            mode === 'new' &&
            onEditLast
        ) {
            event.preventDefault();
            onEditLast();
        }
    };

    const activeId = menuOpen ? `${listId}-${active}` : undefined;
    const describedBy = [replyTo ? replyId : null, hintId]
        .filter(Boolean)
        .join(' ');

    return (
        <div className="grid gap-1.5" data-test="chat-composer">
            {replyTo ? (
                <div className="flex items-start gap-2 rounded-[3px] border-l-2 border-primary bg-muted px-2 py-1.5 text-sm">
                    <Reply
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <p id={replyId} className="min-w-0 flex-1">
                        <span className="block text-xs text-muted-foreground">
                            {t('chat.composer.replying_to', {
                                name: replyTo.author,
                            })}
                        </span>
                        <span className="block truncate text-foreground">
                            {replyTo.excerpt}
                        </span>
                    </p>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        onClick={onCancelReply}
                        aria-label={t('chat.composer.cancel_reply')}
                    >
                        <X aria-hidden="true" />
                    </Button>
                </div>
            ) : null}

            <div className="relative">
                {menuOpen ? (
                    <ul
                        id={listId}
                        role="listbox"
                        aria-label={t('chat.mention.list')}
                        className="absolute bottom-full left-0 z-20 mb-1 max-h-64 w-72 max-w-full overflow-y-auto rounded-[3px] border bg-popover p-1 text-popover-foreground"
                        data-test="chat-mention-list"
                    >
                        {candidates.map((candidate, index) => (
                            <li
                                key={
                                    candidate.kind === 'person'
                                        ? candidate.id
                                        : 'everyone'
                                }
                                id={`${listId}-${index}`}
                                role="option"
                                aria-selected={index === active}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    choose(candidate);
                                }}
                                onMouseEnter={() => setActive(index)}
                                className={cn(
                                    'flex cursor-pointer items-center gap-2 rounded-[3px] px-2 py-1.5 text-sm',
                                    index === active && 'bg-accent',
                                )}
                            >
                                {candidate.kind === 'person' ? (
                                    <>
                                        <ChatAvatar
                                            small
                                            user={{
                                                name: candidate.name,
                                                avatar: null,
                                                is_active: candidate.is_active,
                                            }}
                                        />
                                        <span className="truncate">
                                            {candidate.name}
                                        </span>
                                        {!candidate.is_active ? (
                                            <span className="text-xs text-muted-foreground">
                                                {t('chat.mention.inactive')}
                                            </span>
                                        ) : null}
                                    </>
                                ) : (
                                    <>
                                        <span className="font-medium">
                                            @{t('chat.mention.everyone')}
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {t('chat.mention.everyone_hint')}
                                        </span>
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : null}
                <div aria-live="polite" className="sr-only">
                    {menuOpen
                        ? t('chat.mention.suggestions', {
                              count: candidates.length,
                          })
                        : ''}
                </div>

                <div
                    className={cn(
                        'flex items-end gap-1 rounded-[3px] border border-input bg-background p-1 focus-within:border-ring',
                        (tooLong || error) && 'border-destructive',
                    )}
                >
                    {mediaSlot}
                    <textarea
                        ref={textarea}
                        rows={1}
                        value={draft.text}
                        disabled={disabled}
                        placeholder={
                            placeholder ?? t('chat.composer.placeholder')
                        }
                        aria-label={
                            mode === 'edit'
                                ? t('chat.composer.edit_label')
                                : t('chat.composer.label')
                        }
                        aria-describedby={describedBy}
                        aria-invalid={tooLong || error !== null || undefined}
                        aria-autocomplete="list"
                        aria-controls={menuOpen ? listId : undefined}
                        aria-activedescendant={activeId}
                        onChange={(event) => {
                            const text = event.target.value;
                            setDraft((current) => ({ ...current, text }));
                            setError(null);
                            syncQuery(text, event.target.selectionStart);
                            onTyping?.();
                        }}
                        onSelect={(event) =>
                            syncQuery(
                                event.currentTarget.value,
                                event.currentTarget.selectionStart,
                            )
                        }
                        onKeyDown={onKeyDown}
                        onBlur={() => setQuery(null)}
                        className="max-h-48 min-h-9 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm text-foreground outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
                        data-test="chat-composer-input"
                    />
                    <EmojiPopover
                        align="end"
                        onSelect={insertText}
                        trigger={
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-9"
                                disabled={disabled}
                                aria-label={t('chat.emoji.insert')}
                            >
                                <Smile aria-hidden="true" />
                            </Button>
                        }
                    />
                    {mode === 'new' ? (
                        <Button
                            type="button"
                            size="icon"
                            className="size-9"
                            onClick={() => void submit()}
                            disabled={disabled || length === 0 || tooLong}
                            aria-label={t('chat.composer.send')}
                            data-test="chat-send"
                        >
                            <SendHorizontal aria-hidden="true" />
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                <span
                    id={hintId}
                    className={cn(mode === 'new' && 'max-md:sr-only')}
                >
                    {mode === 'edit'
                        ? t('chat.composer.edit_hint')
                        : t('chat.composer.hint')}
                </span>
                {tooLong || length > MAX_BODY - 1000 ? (
                    <span
                        className={cn(
                            'tabular',
                            tooLong && 'text-destructive-foreground',
                        )}
                        aria-live="polite"
                    >
                        {tooLong
                            ? t('chat.composer.too_long', {
                                  max: formatNumber(MAX_BODY),
                                  count: formatNumber(length),
                              })
                            : `${formatNumber(length)} / ${formatNumber(MAX_BODY)}`}
                    </span>
                ) : null}
            </div>

            {error ? (
                <p className="text-xs text-destructive-foreground" role="alert">
                    {error}
                </p>
            ) : null}

            {mode === 'edit' ? (
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onCancel}
                        disabled={submitting}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => void submit()}
                        disabled={submitting || tooLong}
                    >
                        {t('common.save')}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
