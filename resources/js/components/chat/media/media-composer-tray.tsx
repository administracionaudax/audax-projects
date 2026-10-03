import { CircleAlert, RotateCcw, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { formatClock } from '@/components/chat/media/media-utils';
import type { MediaComposer } from '@/components/chat/media/use-media-composer';
import { fileIcon, formatBytes } from '@/components/tasks/task-attachments';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Vista previa de una imagen elegida (URL temporal del navegador, blob:). */
function Preview({ file }: { file: File }) {
    const [url, setUrl] = useState<string | null>(null);
    const Icon = fileIcon(file.type);

    useEffect(() => {
        if (!file.type.startsWith('image/') || file.type === 'image/svg+xml') {
            return;
        }

        const objectUrl = URL.createObjectURL(file);
        setUrl(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [file]);

    return url ? (
        <img
            src={url}
            alt=""
            className="size-10 shrink-0 rounded-md border object-cover"
        />
    ) : (
        <span className="flex size-10 shrink-0 items-center justify-center rounded-md border bg-muted">
            <Icon aria-hidden="true" className="size-5 text-muted-foreground" />
        </span>
    );
}

/**
 * Bandeja del editor: archivos pendientes (con vista previa, tamaño y «Quitar»), el progreso de la
 * subida con «Cancelar», y los errores, con «Reintentar» si lo que falló fue un audio.
 */
export function MediaComposerTray({
    composer,
    className,
}: {
    composer: MediaComposer;
    className?: string;
}) {
    const {
        files,
        removeFile,
        sending,
        progress,
        uploading,
        queued,
        cancel,
        error,
        failedAudio,
        retryAudio,
        discardAudio,
        dismissError,
    } = composer;

    if (files.length === 0 && !sending && error === null) {
        return null;
    }

    return (
        <div
            className={cn('grid gap-2', className)}
            data-test="chat-media-tray"
        >
            {files.length > 0 ? (
                <ul
                    aria-label={t('chat_media.tray.label', {
                        count: files.length,
                    })}
                    className="flex flex-wrap gap-2"
                >
                    {files.map((file, index) => (
                        <li
                            key={`${file.name}-${file.size}-${index}`}
                            className="flex max-w-full min-w-0 items-center gap-2 rounded-md border bg-card py-1 pr-1 pl-1"
                            data-test="chat-pending-file"
                        >
                            <Preview file={file} />
                            <span className="min-w-0">
                                <span className="block max-w-48 truncate text-sm">
                                    {file.name}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {formatBytes(file.size)}
                                </span>
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                disabled={sending}
                                aria-label={t('chat_media.tray.remove', {
                                    name: file.name,
                                })}
                                onClick={() => removeFile(index)}
                            >
                                <X aria-hidden="true" />
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : null}

            {sending ? (
                <div className="flex items-center gap-3" role="status">
                    <div className="grid min-w-0 flex-1 gap-1">
                        <span className="text-xs text-muted-foreground">
                            {t(
                                uploading === 'audio'
                                    ? 'chat_media.tray.sending_audio'
                                    : 'chat_media.tray.sending_files',
                                { progress: formatPercent(progress, 0) },
                            )}
                        </span>
                        <Progress
                            value={Math.round(progress * 100)}
                            aria-label={t('chat_media.tray.progress')}
                        />
                        {queued > 0 ? (
                            <span
                                className="text-xs text-muted-foreground"
                                data-test="chat-media-queued"
                            >
                                {t('chat_media.tray.audio_queued', {
                                    count: queued,
                                })}
                            </span>
                        ) : null}
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={cancel}
                    >
                        <X aria-hidden="true" />
                        {t('chat_media.tray.cancel')}
                    </Button>
                </div>
            ) : null}

            {error !== null && !sending ? (
                <div
                    role="alert"
                    className="flex flex-wrap items-center gap-2 rounded-md bg-danger-soft px-3 py-2 text-sm text-foreground"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="size-4 shrink-0 text-danger"
                    />
                    <span className="min-w-0 flex-1">
                        {failedAudio
                            ? t('chat_media.tray.audio_failed', {
                                  duration: formatClock(failedAudio.durationMs),
                                  error,
                              })
                            : error}
                    </span>
                    {failedAudio ? (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void retryAudio()}
                            >
                                <RotateCcw aria-hidden="true" />
                                {t('chat_media.tray.retry_audio')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={discardAudio}
                            >
                                <Trash2 aria-hidden="true" />
                                {t('chat_media.tray.discard_audio')}
                            </Button>
                        </>
                    ) : (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={t('chat_media.tray.dismiss')}
                            onClick={dismissError}
                        >
                            <X aria-hidden="true" />
                        </Button>
                    )}
                </div>
            ) : null}
        </div>
    );
}
