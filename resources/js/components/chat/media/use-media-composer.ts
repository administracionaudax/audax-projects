import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    checkChatFiles,
    useMediaLimits,
} from '@/components/chat/media/media-utils';
import {
    MediaUploadError,
    sendWithMedia,
} from '@/components/chat/media/send-with-media';
import type { MediaAudioPayload } from '@/components/chat/media/send-with-media';
import type { SentMediaMessage } from '@/components/chat/media/types';
import { t } from '@/lib/i18n';

export type MediaComposerStatus = 'idle' | 'sending' | 'error';

/** Audio que no se pudo enviar, con el hilo al que respondía. */
export type FailedAudio = MediaAudioPayload & { parentId: number | null };

export type MediaComposer = {
    /** Archivos elegidos, arrastrados o pegados que esperan a enviarse (ya comprobados). */
    files: File[];
    /** Añade archivos: los que no valen se explican con un aviso y no se añaden. */
    addFiles: (files: File[]) => void;
    removeFile: (index: number) => void;
    /** Envía los archivos pendientes (con el texto y el hilo, si los hay). */
    send: (extra?: {
        body?: string | null;
        parentId?: number | null;
    }) => Promise<SentMediaMessage | null>;
    /** Envía un audio grabado (lo que devuelve <AudioRecorder onRecorded>). */
    sendAudio: (
        file: File,
        durationMs: number,
        parentId?: number | null,
    ) => Promise<SentMediaMessage | null>;
    /** Audio que no se pudo enviar: se puede reintentar o descartar (no se pierde la grabación). */
    failedAudio: FailedAudio | null;
    retryAudio: () => Promise<SentMediaMessage | null>;
    discardAudio: () => void;
    /** Cancela la subida en curso. */
    cancel: () => void;
    status: MediaComposerStatus;
    sending: boolean;
    /** Fracción subida, de 0 a 1. */
    progress: number;
    /** Qué se está subiendo ahora. */
    uploading: 'files' | 'audio' | null;
    /** Audios esperando a que termine la subida en curso. */
    queued: number;
    error: string | null;
    dismissError: () => void;
};

/**
 * Estado de lo multimedia del editor de mensajes: archivos pendientes, envío con progreso y
 * cancelación, y reintento de un audio que falló. C1 lo usa con <AttachmentDropzone>,
 * <AttachFilesButton>, <MediaComposerTray> y <AudioRecorder>.
 */
export function useChatMediaComposer(
    conversationId: number,
    { onSent }: { onSent?: (message: SentMediaMessage) => void } = {},
): MediaComposer {
    const { maxAttachmentMb } = useMediaLimits();
    const [files, setFiles] = useState<File[]>([]);
    const [status, setStatus] = useState<MediaComposerStatus>('idle');
    const [progress, setProgress] = useState(0);
    const [uploading, setUploading] = useState<'files' | 'audio' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [failedAudio, setFailedAudio] = useState<FailedAudio | null>(null);
    const controller = useRef<AbortController | null>(null);
    // Audios grabados mientras otra subida estaba en curso: esperan su turno (no se pierden).
    const queue = useRef<Array<() => void>>([]);
    const [queued, setQueued] = useState(0);

    // Al salir de la conversación se cancela lo que se estuviera subiendo.
    useEffect(
        () => () => {
            queue.current = [];
            controller.current?.abort();
        },
        [],
    );

    const run = async (
        kind: 'files' | 'audio',
        payload: Parameters<typeof sendWithMedia>[1],
    ): Promise<SentMediaMessage | null> => {
        if (controller.current !== null) {
            if (kind === 'files') {
                return null;
            }

            // Un audio no se puede volver a grabar: se envía en cuanto termine la subida actual.
            return new Promise((resolve) => {
                queue.current.push(() => {
                    setQueued((count) => Math.max(0, count - 1));
                    void run(kind, payload).then(resolve);
                });
                setQueued((count) => count + 1);
            });
        }

        const abort = new AbortController();
        controller.current = abort;
        setStatus('sending');
        setUploading(kind);
        setProgress(0);
        setError(null);

        try {
            const message = await sendWithMedia(conversationId, payload, {
                signal: abort.signal,
                onProgress: setProgress,
            });

            if (kind === 'files') {
                setFiles([]);
            } else {
                setFailedAudio(null);
            }

            setStatus('idle');
            onSent?.(message);

            return message;
        } catch (caught) {
            const failure =
                caught instanceof MediaUploadError
                    ? caught
                    : new MediaUploadError(
                          'server',
                          t('chat_media.send.server'),
                      );

            if (failure.kind === 'aborted') {
                setStatus('idle');

                return null;
            }

            if (kind === 'audio' && payload.audio) {
                setFailedAudio({
                    ...payload.audio,
                    parentId: payload.parentId ?? null,
                });
            }

            setStatus('error');
            setError(failure.message);

            return null;
        } finally {
            controller.current = null;
            setUploading(null);
            setProgress(0);
            queue.current.shift()?.();
        }
    };

    const addFiles = (incoming: File[]) => {
        const { accepted, rejected } = checkChatFiles(
            incoming,
            maxAttachmentMb,
            files.length,
        );

        for (const { reason } of rejected) {
            toast.error(reason);
        }

        if (accepted.length > 0) {
            setFiles([...files, ...accepted]);
            setError(null);
        }
    };

    return {
        files,
        addFiles,
        removeFile: (index) =>
            setFiles(files.filter((_, position) => position !== index)),
        send: (extra = {}) =>
            files.length === 0
                ? Promise.resolve(null)
                : run('files', {
                      body: extra.body,
                      parentId: extra.parentId,
                      files,
                  }),
        sendAudio: (file, durationMs, parentId = null) =>
            run('audio', { audio: { file, durationMs }, parentId }),
        failedAudio,
        retryAudio: () =>
            failedAudio === null
                ? Promise.resolve(null)
                : run('audio', {
                      audio: failedAudio,
                      parentId: failedAudio.parentId,
                  }),
        discardAudio: () => {
            setFailedAudio(null);
            setError(null);
            setStatus('idle');
        },
        cancel: () => controller.current?.abort(),
        status,
        sending: status === 'sending',
        progress,
        uploading,
        queued,
        error,
        dismissError: () => {
            setError(null);
            setStatus('idle');
        },
    };
}
