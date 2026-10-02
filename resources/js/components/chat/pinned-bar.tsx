import { ChevronDown, Pin } from 'lucide-react';
import { useId, useState } from 'react';
import { systemText } from '@/components/chat/system-notice';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ChatPinnedMessage } from '@/types/chat';

/**
 * Barra de mensajes fijados (SPEC §12): cuántos hay y, desplegada, cada uno con su extracto; al
 * pulsar se va al mensaje (aunque no esté cargado).
 */
export function PinnedBar({
    pinned,
    onJump,
}: {
    pinned: ChatPinnedMessage[];
    onJump: (messageId: number) => void;
}) {
    const [open, setOpen] = useState(false);
    const listId = useId();

    if (pinned.length === 0) {
        return null;
    }

    const label =
        pinned.length === 1
            ? t('chat.pinned.one')
            : t('chat.pinned.many', { count: pinned.length });

    return (
        <div className="border-b bg-background" data-test="chat-pinned-bar">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                aria-controls={listId}
                className={cn(
                    'flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-foreground hover:bg-muted',
                    FOCUS_RING,
                    'focus-visible:ring-offset-0',
                )}
            >
                <Pin
                    aria-hidden="true"
                    className="size-4 text-muted-foreground"
                />
                <span className="flex-1">{label}</span>
                <ChevronDown
                    aria-hidden="true"
                    className={cn(
                        'size-4 text-muted-foreground transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </button>
            {open ? (
                <ul
                    id={listId}
                    aria-label={t('chat.pinned.list')}
                    className="max-h-48 overflow-y-auto border-t"
                >
                    {pinned.map((item) => {
                        const text = item.system
                            ? systemText(item.system)
                            : item.excerpt;

                        return (
                            <li key={item.id}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setOpen(false);
                                        onJump(item.id);
                                    }}
                                    aria-label={t('chat.pinned.go', { text })}
                                    className={cn(
                                        'grid w-full gap-0.5 px-4 py-2 text-left text-sm hover:bg-muted',
                                        FOCUS_RING,
                                        'focus-visible:ring-offset-0',
                                    )}
                                >
                                    <span className="line-clamp-2 break-words text-foreground">
                                        {text}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {[
                                            item.author,
                                            item.pinned_by
                                                ? t('chat.pinned.by', {
                                                      name: item.pinned_by,
                                                  })
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            ) : null}
        </div>
    );
}
