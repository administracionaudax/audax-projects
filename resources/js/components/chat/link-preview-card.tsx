import { ExternalLink } from 'lucide-react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ChatLinkPreview } from '@/types/chat';

/**
 * Previsualización del primer enlace del mensaje (D-069): dominio, título y descripción que ha
 * resuelto el servidor. Sin imágenes remotas. Se abre en otra pestaña sin enviar la referencia.
 */
export function LinkPreviewCard({ preview }: { preview: ChatLinkPreview }) {
    return (
        <a
            href={preview.url}
            target="_blank"
            rel="noopener noreferrer nofollow"
            aria-label={`${t('chat.link.preview', { domain: preview.domain })}: ${preview.title}`}
            className={cn(
                'group/preview grid max-w-md gap-0.5 rounded-md border-l-2 border-primary bg-muted px-3 py-2 hover:bg-accent',
                FOCUS_RING,
            )}
            data-test="chat-link-preview"
        >
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                <ExternalLink aria-hidden="true" className="size-3" />
                {preview.domain}
            </span>
            <span className="text-sm font-medium break-words text-foreground group-hover/preview:underline">
                {preview.title}
            </span>
            {preview.description ? (
                <span className="line-clamp-2 text-xs break-words text-muted-foreground">
                    {preview.description}
                </span>
            ) : null}
        </a>
    );
}
