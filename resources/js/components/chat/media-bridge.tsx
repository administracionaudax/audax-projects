import { LoaderCircle, MicOff } from 'lucide-react';
import type { ReactNode } from 'react';
import { fileIcon, formatBytes } from '@/components/tasks/task-attachments';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    ChatAttachment,
    ChatMessage,
    ChatMessageResponse,
} from '@/types/chat';

/**
 * PUNTO DE ENGANCHE con audios y adjuntos (Fase 6, área C3).
 *
 * C3 exporta desde resources/js/components/chat/media/index.ts <AudioRecorder onRecorded>,
 * <AudioMessage message>, <AttachmentList attachments>, <AttachmentDropzone onFiles> y
 * sendWithMedia(conversationId, {body, files, audio, parentId}). Hasta integrar las dos ramas:
 * - MEDIA_READY es false: el editor no enseña los botones de adjuntar ni de grabar,
 * - AudioMessage y AttachmentList son versiones mínimas (reproductor nativo con la transcripción
 *   y lista de ficheros) para que los mensajes con audio o adjuntos se vean,
 * - AudioRecorder y AttachmentDropzone no hacen nada y sendWithMedia no está disponible.
 * Al integrar, este módulo pasa a ser `export * from '@/components/chat/media'` más
 * `export const MEDIA_READY = true`.
 */

export const MEDIA_READY = false;

export type MediaPayload = {
    body: string | null;
    files: File[];
    audio: { file: File; durationMs: number } | null;
    parentId: number | null;
};

export function AudioRecorder(props: {
    onRecorded: (file: File, durationMs: number) => void;
    disabled?: boolean;
}): ReactNode {
    void props;

    return null;
}

export function AttachmentDropzone({
    children,
}: {
    onFiles: (files: File[]) => void;
    disabled?: boolean;
    children: ReactNode;
}) {
    return <>{children}</>;
}

export async function sendWithMedia(
    conversationId: number,
    payload: MediaPayload,
): Promise<ChatMessageResponse> {
    void conversationId;
    void payload;

    throw new Error(t('chat.errors.media_unavailable'));
}

/** Audio con su transcripción obligatoria (versión mínima hasta C3). */
export function AudioMessage({ message }: { message: ChatMessage }) {
    const audio = message.audio;

    if (!audio) {
        return null;
    }

    const transcription = audio.transcription;
    const status = transcription?.status ?? 'pending';

    return (
        <div className="grid max-w-md gap-1.5" data-test="chat-audio">
            <audio
                controls
                preload="none"
                src={audio.url}
                className="h-10 w-full"
                aria-label={t('chat.audio.player', {
                    name: message.author?.name ?? '',
                })}
            />
            {status === 'done' ? (
                <details className="text-sm">
                    <summary
                        className={cn(
                            'cursor-pointer rounded-[3px] text-muted-foreground',
                            FOCUS_RING,
                        )}
                    >
                        {t('chat.audio.transcription')}
                    </summary>
                    <p className="mt-1 whitespace-pre-wrap text-foreground">
                        {transcription?.text?.trim()
                            ? transcription.text
                            : t('chat.audio.no_speech')}
                    </p>
                </details>
            ) : (
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    {status === 'failed' ? (
                        <MicOff aria-hidden="true" className="size-3.5" />
                    ) : (
                        <LoaderCircle
                            aria-hidden="true"
                            className="size-3.5 animate-spin"
                        />
                    )}
                    {status === 'failed'
                        ? t('chat.audio.pending')
                        : t('chat.audio.transcribing')}
                </p>
            )}
        </div>
    );
}

/** Adjuntos de un mensaje (versión mínima hasta C3): miniatura o icono, nombre y tamaño. */
export function AttachmentList({
    attachments,
}: {
    attachments: ChatAttachment[];
}) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <ul
            className="flex flex-wrap gap-2"
            aria-label={t('chat.attachments.list')}
        >
            {attachments.map((attachment) => {
                const Icon = fileIcon(attachment.mime);

                return (
                    <li key={attachment.id}>
                        <a
                            href={attachment.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={cn(
                                'flex max-w-64 items-center gap-2 rounded-[3px] border bg-card p-1.5 text-sm hover:bg-accent',
                                FOCUS_RING,
                            )}
                        >
                            {attachment.is_image && attachment.thumbnail_url ? (
                                <img
                                    src={attachment.thumbnail_url}
                                    alt=""
                                    className="size-10 rounded-[3px] object-cover"
                                />
                            ) : (
                                <Icon
                                    aria-hidden="true"
                                    className="size-5 shrink-0 text-muted-foreground"
                                />
                            )}
                            <span className="min-w-0">
                                <span className="block truncate text-foreground">
                                    {attachment.original_name}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {formatBytes(attachment.size)}
                                </span>
                            </span>
                        </a>
                    </li>
                );
            })}
        </ul>
    );
}
