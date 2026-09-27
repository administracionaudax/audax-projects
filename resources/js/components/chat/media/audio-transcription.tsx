import {
    Check,
    ChevronDown,
    Clock,
    Copy,
    FileAudio,
    Loader2,
    MicOff,
} from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { toast } from 'sonner';
import { AudioPlayer } from '@/components/chat/media/audio-player';
import type {
    AudioMessageData,
    ChatTranscription,
} from '@/components/chat/media/types';
import { useLiveTranscription } from '@/components/chat/media/use-live-transcription';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Copia al portapapeles con confirmación accesible («Copiada»). */
function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timeout = setTimeout(() => setCopied(false), 2000);

        return () => clearTimeout(timeout);
    }, [copied]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
        } catch {
            toast.error(t('chat_media.transcription.copy_failed'));
        }
    };

    return (
        <>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs"
                aria-label={
                    copied
                        ? t('chat_media.transcription.copied_label')
                        : t('chat_media.transcription.copy_label')
                }
                onClick={() => void copy()}
            >
                {copied ? (
                    <Check aria-hidden="true" className="text-success" />
                ) : (
                    <Copy aria-hidden="true" />
                )}
                {copied
                    ? t('chat_media.transcription.copied')
                    : t('chat_media.transcription.copy')}
            </Button>
            <span className="sr-only" role="status">
                {copied ? t('chat_media.transcription.copied_label') : ''}
            </span>
        </>
    );
}

/**
 * Transcripción bajo el audio (SPEC §12), siempre con icono + texto:
 * - pendiente o en curso: «Transcribiendo…»,
 * - fallida: «Transcripción pendiente» (se reintenta sola),
 * - hecha sin texto: «Sin voz»,
 * - hecha: plegable, con una línea de vista previa, y «Copiar».
 */
export function AudioTranscription({
    transcription,
    defaultOpen = false,
    className,
}: {
    transcription: ChatTranscription | null;
    defaultOpen?: boolean;
    className?: string;
}) {
    const contentId = useId();
    const [open, setOpen] = useState(defaultOpen);
    const status = transcription?.status ?? 'pending';

    if (status === 'pending' || status === 'processing') {
        return (
            <p
                role="status"
                className={cn(
                    'inline-flex items-center gap-1.5 text-xs text-muted-foreground',
                    className,
                )}
                data-test="chat-transcription"
                data-status={status}
            >
                <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                {t('chat_media.transcription.transcribing')}
            </p>
        );
    }

    if (status === 'failed') {
        return (
            <p
                role="status"
                className={cn(
                    'inline-flex items-center gap-1.5 text-xs text-muted-foreground',
                    className,
                )}
                title={t('chat_media.transcription.pending_help')}
                data-test="chat-transcription"
                data-status={status}
            >
                <Clock aria-hidden="true" className="size-3.5 text-warning" />
                {t('chat_media.transcription.pending')}
                <span className="sr-only">
                    {t('chat_media.transcription.pending_help')}
                </span>
            </p>
        );
    }

    const text = transcription?.text ?? '';

    if (text.trim() === '') {
        return (
            <p
                className={cn(
                    'inline-flex items-center gap-1.5 text-xs text-muted-foreground',
                    className,
                )}
                data-test="chat-transcription"
                data-status="empty"
            >
                <MicOff aria-hidden="true" className="size-3.5" />
                {t('chat_media.transcription.no_speech')}
            </p>
        );
    }

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className={cn('grid gap-1', className)}
            data-test="chat-transcription"
            data-status="done"
        >
            <div className="flex min-w-0 items-center gap-1">
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        aria-controls={contentId}
                        className={cn(
                            'flex min-w-0 flex-1 items-center gap-1.5 rounded-[3px] py-0.5 text-left text-xs text-muted-foreground hover:text-foreground',
                            FOCUS_RING,
                        )}
                    >
                        <ChevronDown
                            aria-hidden="true"
                            className={cn(
                                'size-3.5 shrink-0 transition-transform',
                                open && 'rotate-180',
                            )}
                        />
                        <FileAudio
                            aria-hidden="true"
                            className="size-3.5 shrink-0"
                        />
                        <span className="shrink-0">
                            {open
                                ? t('chat_media.transcription.hide')
                                : t('chat_media.transcription.show')}
                        </span>
                        {!open ? (
                            <span
                                className="min-w-0 truncate italic"
                                aria-hidden="true"
                            >
                                {text}
                            </span>
                        ) : null}
                    </button>
                </CollapsibleTrigger>
                {open ? <CopyButton text={text} /> : null}
            </div>
            <CollapsibleContent id={contentId}>
                <p
                    className="rounded-[3px] bg-muted px-2.5 py-2 text-sm whitespace-pre-line text-foreground"
                    lang={transcription?.language ?? undefined}
                >
                    {text}
                </p>
            </CollapsibleContent>
        </Collapsible>
    );
}

/**
 * Mensaje de audio: el reproductor y, debajo, su transcripción, que se actualiza sola.
 * `message` es cualquier mensaje con {id, conversation_id, audio, transcription}
 * (MediaPayload::of en el servidor).
 */
export function AudioMessage({
    message,
    defaultTranscriptOpen = false,
    className,
}: {
    message: AudioMessageData;
    /** Abrir la transcripción (p. ej. al llegar desde un resultado de búsqueda). */
    defaultTranscriptOpen?: boolean;
    className?: string;
}) {
    const { audio, transcription, refreshAudio } =
        useLiveTranscription(message);

    if (audio === null) {
        return (
            <p
                className={cn(
                    'inline-flex items-center gap-1.5 text-sm text-muted-foreground',
                    className,
                )}
            >
                <FileAudio aria-hidden="true" className="size-4" />
                {t('chat_media.audio.unavailable')}
            </p>
        );
    }

    return (
        <div
            className={cn('grid w-full max-w-sm min-w-0 gap-1.5', className)}
            data-test="chat-audio-message"
        >
            <AudioPlayer
                src={audio.url}
                durationMs={audio.duration_ms}
                onSourceExpired={refreshAudio}
            />
            <AudioTranscription
                transcription={transcription}
                defaultOpen={defaultTranscriptOpen}
            />
        </div>
    );
}
