import { ArrowDown, MessagesSquare } from 'lucide-react';
import {
    Fragment,
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import type { RefObject } from 'react';
import {
    dayKey,
    formatDayLabel,
    withinGroupWindow,
} from '@/components/chat/chat-format';
import type { MentionPerson } from '@/components/chat/composer';
import { MessageItem } from '@/components/chat/message-item';
import type { MessageItemHandlers } from '@/components/chat/message-item';
import type { ConversationController } from '@/components/chat/use-conversation';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ChatMessage } from '@/types/chat';

const BOTTOM_THRESHOLD = 120;
const HIGHLIGHT_MS = 3_000;

type ItemHandlers = Omit<MessageItemHandlers, 'onJump'>;

type Latest = {
    messages: ChatMessage[];
    handlersFor: (message: ChatMessage) => ItemHandlers;
    onJump: (messageId: number) => void;
};

/**
 * Manejadores ESTABLES por mensaje (MessageItem está memorizado): cada uno llama a los de ahora
 * mismo con el mensaje tal como esté ahora, así que nunca usan datos viejos.
 */
function stableHandlers(
    id: number,
    fallback: ChatMessage,
    latest: { current: Latest },
): MessageItemHandlers {
    const current = (): ItemHandlers => {
        const message =
            latest.current.messages.find((item) => item.id === id) ?? fallback;

        return latest.current.handlersFor(message);
    };

    return {
        onReply: () => current().onReply(),
        onReact: (emoji) => current().onReact(emoji),
        onEdit: () => current().onEdit(),
        onDelete: () => current().onDelete(),
        onPin: (pinned) => current().onPin(pinned),
        onCopyLink: () => current().onCopyLink(),
        onCopyText: () => current().onCopyText(),
        onCreateTask: () => current().onCreateTask(),
        onModerate: (hidden) => current().onModerate(hidden),
        onRetry: () => current().onRetry(),
        onDiscard: () => current().onDiscard(),
        onEditSubmit: (body) => current().onEditSubmit(body),
        onEditCancel: () => current().onEditCancel(),
        onJump: (messageId) => latest.current.onJump(messageId),
    };
}

/** ¿Empieza un grupo nuevo (avatar y nombre)? Otro autor, otro día, más de 5 min o sistema. */
function startsGroup(previous: ChatMessage | undefined, message: ChatMessage) {
    return (
        previous === undefined ||
        previous.type === 'system' ||
        message.type === 'system' ||
        previous.author?.id !== message.author?.id ||
        !withinGroupWindow(previous.created_at, message.created_at)
    );
}

/**
 * Lista de mensajes de una conversación (SPEC §12): agrupados por día (con encabezado) y por
 * autor, con el separador «Mensajes nuevos» en lo primero sin leer al abrir. Paginación infinita
 * hacia atrás conservando la posición (y un botón para quien no usa la rueda), hacia delante tras
 * saltar a un mensaje, «Ir al último mensaje» y marcar como leído al ver el último.
 */
export function MessageList({
    controller,
    currentUserId,
    people,
    initialLastRead,
    focusId,
    editingId,
    title,
    handlersFor,
    jumpRef,
}: {
    controller: ConversationController;
    currentUserId: number;
    people: MentionPerson[];
    /** Hasta dónde había leído quien mira al abrir (separador «Mensajes nuevos»). */
    initialLastRead: number | null;
    /** Mensaje al que ir al abrir (?mensaje=). */
    focusId: number | null;
    editingId: number | null;
    /** Nombre de la conversación (etiqueta de la región). */
    title: string;
    handlersFor: (message: ChatMessage) => ItemHandlers;
    /** Para ir a un mensaje desde fuera (barra de fijados). */
    jumpRef?: RefObject<((messageId: number) => void) | null>;
}) {
    const { messages, hasOlder, hasNewer, loadingOlder, loadingNewer, names } =
        controller;
    const scroller = useRef<HTMLDivElement>(null);
    const topSentinel = useRef<HTMLDivElement>(null);
    const bottomSentinel = useRef<HTMLDivElement>(null);
    const anchor = useRef<{ height: number; top: number } | null>(null);
    const stickToBottom = useRef(true);
    const previousLast = useRef<number>(0);
    const pendingJump = useRef<number | null>(focusId);
    const focusOnArrival = useRef(false);
    const [atBottom, setAtBottom] = useState(true);
    const [newBelow, setNewBelow] = useState(0);
    const [highlighted, setHighlighted] = useState<number | null>(null);
    const [firstUnread] = useState<number | null>(() => {
        if (initialLastRead === null || focusId !== null) {
            return null;
        }

        return (
            messages.find(
                (message) =>
                    message.id > initialLastRead &&
                    message.author?.id !== currentUserId,
            )?.id ?? null
        );
    });

    const scrollToBottom = useCallback((smooth = false) => {
        const element = scroller.current;

        if (element && typeof element.scrollTo === 'function') {
            element.scrollTo({
                top: element.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto',
            });
        } else if (element) {
            element.scrollTop = element.scrollHeight;
        }

        stickToBottom.current = true;
        setNewBelow(0);
    }, []);

    const reveal = useCallback((messageId: number, focus = false) => {
        const element = document.getElementById(`mensaje-${messageId}`);

        if (!element) {
            return false;
        }

        element.scrollIntoView({ block: 'center' });

        // Al saltar (fijados, citas), el foco va al mensaje: el lector de pantalla lo lee y el
        // tabulador sigue desde ahí.
        if (focus) {
            element
                .querySelector<HTMLElement>('[data-message-focus]')
                ?.focus({ preventScroll: true });
        }

        setHighlighted(messageId);
        window.setTimeout(
            () =>
                setHighlighted((current) =>
                    current === messageId ? null : current,
                ),
            HIGHLIGHT_MS,
        );

        return true;
    }, []);

    const onJump = useCallback(
        async (messageId: number) => {
            if (reveal(messageId, true)) {
                return;
            }

            pendingJump.current = messageId;
            focusOnArrival.current = true;
            stickToBottom.current = false;

            if (!(await controller.loadAround(messageId))) {
                pendingJump.current = null;
                focusOnArrival.current = false;
            }
        },
        [controller, reveal],
    );

    const latest = useRef<Latest>({
        messages,
        handlersFor,
        onJump: (id) => void onJump(id),
    });
    const proxies = useRef(new Map<number, MessageItemHandlers>());

    useLayoutEffect(() => {
        latest.current = {
            messages,
            handlersFor,
            onJump: (id) => void onJump(id),
        };
    });

    const handlersOf = (message: ChatMessage): MessageItemHandlers => {
        let handlers = proxies.current.get(message.id);

        if (!handlers) {
            handlers = stableHandlers(message.id, message, latest);
            proxies.current.set(message.id, handlers);
        }

        return handlers;
    };

    useEffect(() => {
        if (!jumpRef) {
            return;
        }

        jumpRef.current = (messageId: number) => void onJump(messageId);

        return () => {
            jumpRef.current = null;
        };
    }, [jumpRef, onJump]);

    const loadOlder = useCallback(() => {
        const element = scroller.current;

        if (!element || loadingOlder || !hasOlder) {
            return;
        }

        anchor.current = {
            height: element.scrollHeight,
            top: element.scrollTop,
        };
        void controller.loadOlder();
    }, [controller, hasOlder, loadingOlder]);

    // Primera pintura: al mensaje pedido, a lo primero sin leer o al final.
    useLayoutEffect(() => {
        if (focusId !== null && reveal(focusId)) {
            pendingJump.current = null;
            stickToBottom.current = false;

            return;
        }

        if (firstUnread !== null) {
            const separator = document.getElementById('chat-nuevos');

            if (separator) {
                separator.scrollIntoView({ block: 'start' });
                stickToBottom.current = false;

                return;
            }
        }

        scrollToBottom();
        // Solo al montar: cada conversación monta su propia lista.
    }, []);

    // Tras cada cambio: conservar la posición al cargar anteriores, bajar si estaba abajo o
    // contar los nuevos que quedan por debajo, e ir al mensaje pedido cuando llega su ventana.
    useLayoutEffect(() => {
        const element = scroller.current;
        const last = messages.at(-1);
        const lastId = last?.id ?? 0;

        if (element && anchor.current) {
            element.scrollTop =
                element.scrollHeight -
                anchor.current.height +
                anchor.current.top;
            anchor.current = null;
        } else if (pendingJump.current !== null) {
            if (reveal(pendingJump.current, focusOnArrival.current)) {
                pendingJump.current = null;
                focusOnArrival.current = false;
            }
        } else if (
            lastId !== previousLast.current &&
            previousLast.current !== 0
        ) {
            const mine = last?.author?.id === currentUserId;

            if (stickToBottom.current || mine) {
                scrollToBottom();
            } else if (lastId > previousLast.current) {
                setNewBelow((count) => count + 1);
            }
        }

        previousLast.current = lastId;
    }, [currentUserId, messages, reveal, scrollToBottom]);

    // Paginación hacia atrás al acercarse al principio.
    useEffect(() => {
        const element = topSentinel.current;

        if (
            !element ||
            !hasOlder ||
            typeof IntersectionObserver === 'undefined'
        ) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    loadOlder();
                }
            },
            { root: scroller.current, rootMargin: '300px 0px 0px 0px' },
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, [hasOlder, loadOlder]);

    // Al ver el final: leído (con la pestaña visible) y, tras un salto, los siguientes.
    useEffect(() => {
        const element = bottomSentinel.current;

        if (!element || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (!entries.some((entry) => entry.isIntersecting)) {
                    return;
                }

                if (hasNewer) {
                    void controller.loadNewer();
                } else if (document.visibilityState === 'visible') {
                    void controller.markRead();
                }
            },
            { root: scroller.current, rootMargin: '0px 0px 150px 0px' },
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, [controller, hasNewer]);

    // Al volver a la pestaña con el final a la vista, también cuenta como leído.
    useEffect(() => {
        const onVisible = () => {
            if (
                document.visibilityState === 'visible' &&
                stickToBottom.current &&
                !hasNewer
            ) {
                void controller.markRead();
            }
        };

        document.addEventListener('visibilitychange', onVisible);

        return () =>
            document.removeEventListener('visibilitychange', onVisible);
    }, [controller, hasNewer]);

    const onScroll = () => {
        const element = scroller.current;

        if (!element) {
            return;
        }

        const distance =
            element.scrollHeight - element.scrollTop - element.clientHeight;
        const bottom = distance < BOTTOM_THRESHOLD;
        stickToBottom.current = bottom && !hasNewer;
        setAtBottom(bottom);

        if (bottom) {
            setNewBelow(0);
        }
    };

    const goToLatest = async () => {
        if (hasNewer) {
            await controller.loadLatest();
        }

        scrollToBottom(true);
    };

    let previous: ChatMessage | undefined;

    return (
        <div className="relative flex min-h-0 flex-1 flex-col">
            <div
                ref={scroller}
                onScroll={onScroll}
                tabIndex={0}
                role="region"
                aria-label={t('chat.messages.label', { name: title })}
                className={cn(
                    'min-h-0 flex-1 overflow-y-auto overscroll-contain pb-2',
                    FOCUS_RING,
                    'focus-visible:ring-offset-0',
                )}
                data-test="chat-messages"
            >
                <div ref={topSentinel} aria-hidden="true" />
                {hasOlder ? (
                    <div className="flex justify-center p-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={loadOlder}
                            disabled={loadingOlder}
                        >
                            {loadingOlder ? <Spinner /> : null}
                            {loadingOlder
                                ? t('chat.messages.loading')
                                : t('chat.messages.load_older')}
                        </Button>
                    </div>
                ) : messages.length > 0 ? (
                    <p className="px-4 pt-4 pb-2 text-center text-xs text-muted-foreground">
                        {t('chat.messages.beginning')}
                    </p>
                ) : null}

                {messages.length === 0 ? (
                    <div className="p-4">
                        <EmptyState
                            icon={MessagesSquare}
                            title={t('chat.messages.empty.title')}
                            description={t('chat.messages.empty.description')}
                        />
                    </div>
                ) : (
                    <ol className="grid">
                        {messages.map((message) => {
                            const newDay =
                                previous === undefined ||
                                dayKey(previous.created_at) !==
                                    dayKey(message.created_at);
                            const header =
                                newDay || startsGroup(previous, message);
                            previous = message;

                            return (
                                <Fragment key={message.id}>
                                    {newDay ? (
                                        <li className="sticky top-0 z-[5] flex justify-center py-1.5">
                                            <h3 className="rounded-md border bg-background px-2 py-0.5 text-xs text-muted-foreground first-letter:uppercase">
                                                {formatDayLabel(
                                                    message.created_at,
                                                )}
                                            </h3>
                                        </li>
                                    ) : null}
                                    {message.id === firstUnread ? (
                                        <li
                                            id="chat-nuevos"
                                            className="flex items-center gap-2 px-4 py-1 text-xs text-info"
                                        >
                                            <span
                                                aria-hidden="true"
                                                className="h-px flex-1 bg-info"
                                            />
                                            {t('chat.messages.new_separator')}
                                            <span
                                                aria-hidden="true"
                                                className="h-px flex-1 bg-info"
                                            />
                                        </li>
                                    ) : null}
                                    <MessageItem
                                        message={message}
                                        showHeader={header}
                                        currentUserId={currentUserId}
                                        names={names}
                                        people={people}
                                        highlighted={highlighted === message.id}
                                        editing={editingId === message.id}
                                        handlers={handlersOf(message)}
                                    />
                                </Fragment>
                            );
                        })}
                    </ol>
                )}

                {hasNewer ? (
                    <div className="flex justify-center p-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => void controller.loadNewer()}
                            disabled={loadingNewer}
                        >
                            {loadingNewer ? <Spinner /> : null}
                            {t('chat.messages.load_newer')}
                        </Button>
                    </div>
                ) : null}
                <div ref={bottomSentinel} aria-hidden="true" className="h-px" />
            </div>

            {!atBottom || hasNewer ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => void goToLatest()}
                    className="absolute right-4 bottom-3 z-10 bg-background"
                    data-test="chat-jump-latest"
                >
                    <ArrowDown aria-hidden="true" />
                    {newBelow > 0
                        ? t('chat.messages.new_below', { count: newBelow })
                        : t('chat.messages.jump_latest')}
                </Button>
            ) : null}
        </div>
    );
}
