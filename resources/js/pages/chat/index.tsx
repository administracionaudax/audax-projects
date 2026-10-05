import { Head, setLayoutProps } from '@inertiajs/react';
import { MessagesSquare } from 'lucide-react';
import { ConversationList } from '@/components/chat/conversation-list';
import { ConversationView } from '@/components/chat/conversation-view';
import { useConversationList } from '@/components/chat/use-conversation-list';
import { EmptyState } from '@/components/empty-state';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { index as chatIndex, show as chatShow } from '@/routes/chat';
import type { ChatIndexPageProps } from '@/types/chat';

/**
 * Altura del chat: la ventana menos la cabecera de la app (h-16; h-12 con la barra lateral
 * plegada), en escritorio el margen y el borde del contenido, y el aviso de privacidad mientras
 * esté pendiente (--privacy-banner-space, Fase 7) y el aviso global (--global-banner-space, Fase 10). Así la lista y los mensajes tienen su propio
 * scroll y el editor queda siempre a la vista.
 */
const FULL_HEIGHT =
    'h-[calc(100svh-4rem-var(--privacy-banner-space,0px)-var(--global-banner-space,0px))] md:h-[calc(100svh-5rem-2px-var(--privacy-banner-space,0px)-var(--global-banner-space,0px))] md:group-has-data-[collapsible=icon]/sidebar-wrapper:h-[calc(100svh-4rem-2px-var(--privacy-banner-space,0px)-var(--global-banner-space,0px))]';

/**
 * /chat y /chat/{conversación} (SPEC §12). En escritorio, la lista y la conversación a la vez;
 * en el móvil, pantallas separadas (la lista en /chat y la conversación, con «volver», en
 * /chat/{id}). Al cambiar de conversación solo se piden sus props (la lista se queda).
 */
export default function ChatIndex({
    conversations,
    conversation,
    messages,
    pinned,
    focus,
}: ChatIndexPageProps) {
    const list = useConversationList(conversations);
    const open = conversation !== null && messages !== null;

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.chat'), href: chatIndex() },
            ...(conversation
                ? [
                      {
                          title: conversation.title,
                          href: chatShow(conversation.id),
                      },
                  ]
                : []),
        ],
    });

    return (
        <>
            <Head
                title={
                    conversation
                        ? `${conversation.title} · ${t('chat.title')}`
                        : t('chat.title')
                }
            />

            <div className={cn('flex min-h-0 overflow-hidden', FULL_HEIGHT)}>
                <ConversationList
                    items={list.items}
                    activeId={conversation?.id ?? null}
                    loadError={list.failed}
                    onRetry={() => void list.refresh()}
                    className={cn(
                        'w-full shrink-0 border-r md:w-80 lg:w-96',
                        open ? 'hidden md:flex' : 'flex',
                    )}
                />

                {open ? (
                    <ConversationView
                        key={`${conversation.id}:${focus ?? ''}`}
                        conversation={conversation}
                        initial={messages}
                        pinned={pinned}
                        focus={focus}
                        backHref={urls.chat()}
                        onActivity={list.applyActivity}
                        className="min-w-0"
                    />
                ) : (
                    <div className="hidden min-w-0 flex-1 items-center justify-center p-6 md:flex">
                        <EmptyState
                            icon={MessagesSquare}
                            title={t('chat.empty.select.title')}
                            description={t('chat.empty.select.description')}
                            className="max-w-sm"
                        />
                    </div>
                )}
            </div>
        </>
    );
}

ChatIndex.layout = {
    breadcrumbs: [{ title: t('nav.chat'), href: chatIndex() }],
};
