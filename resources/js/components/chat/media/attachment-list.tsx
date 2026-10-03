import { ChevronLeft, ChevronRight, Download, FileImage } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChatAttachment } from '@/components/chat/media/types';
import { fileIcon, formatBytes } from '@/components/tasks/task-attachments';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Tipo legible del archivo para la tarjeta («PDF», «ZIP»…): su extensión. */
function extensionLabel(name: string): string {
    const extension = name.includes('.') ? name.split('.').pop() : '';

    return (extension ?? '').toUpperCase();
}

/**
 * Visor de imágenes accesible: diálogo con foco atrapado, Escape para cerrar y flechas (o los
 * botones) para pasar de una a otra; con «Descargar». Al cerrar, el foco vuelve a la miniatura de
 * la imagen que se estaba viendo (`returnFocus`), no a la primera que se abrió.
 */
export function ImageViewer({
    images,
    index,
    onIndexChange,
    returnFocus,
}: {
    images: ChatAttachment[];
    index: number | null;
    onIndexChange: (index: number | null) => void;
    returnFocus?: (index: number) => HTMLElement | null | undefined;
}) {
    // La última vista: el contenido sigue montado mientras se cierra (y sabe a dónde volver).
    const [last, setLast] = useState<number | null>(index);

    if (index !== null && index !== last) {
        setLast(index);
    }

    const shownIndex = index ?? last;
    const image = shownIndex === null ? null : (images[shownIndex] ?? null);
    const many = images.length > 1;

    const go = (delta: number) => {
        if (index === null || !many) {
            return;
        }

        onIndexChange((index + delta + images.length) % images.length);
    };

    return (
        <Dialog
            open={index !== null && image !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onIndexChange(null);
                }
            }}
        >
            {image ? (
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        const target =
                            last === null ? null : returnFocus?.(last);

                        if (target && target.isConnected) {
                            event.preventDefault();
                            target.focus();
                        }
                    }}
                    className="grid max-h-[calc(100dvh-2rem)] gap-3 p-3 sm:max-w-4xl sm:p-4"
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowRight') {
                            event.preventDefault();
                            go(1);
                        } else if (event.key === 'ArrowLeft') {
                            event.preventDefault();
                            go(-1);
                        }
                    }}
                    data-test="chat-image-viewer"
                >
                    <DialogTitle className="truncate pr-8 text-base font-normal">
                        {image.original_name}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {many
                            ? t('chat_media.viewer.help_many')
                            : t('chat_media.viewer.help')}
                    </DialogDescription>
                    <div className="flex min-h-0 items-center justify-center overflow-hidden rounded-md bg-muted">
                        <img
                            key={image.id}
                            src={image.url}
                            alt={image.original_name}
                            className="max-h-[65dvh] w-auto max-w-full object-contain"
                        />
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            {many ? (
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        aria-label={t(
                                            'chat_media.viewer.previous',
                                        )}
                                        onClick={() => go(-1)}
                                    >
                                        <ChevronLeft aria-hidden="true" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        aria-label={t('chat_media.viewer.next')}
                                        onClick={() => go(1)}
                                    >
                                        <ChevronRight aria-hidden="true" />
                                    </Button>
                                </>
                            ) : null}
                            <p
                                className="tabular text-sm text-muted-foreground"
                                aria-live="polite"
                            >
                                {many
                                    ? t('chat_media.viewer.position', {
                                          current: (shownIndex ?? 0) + 1,
                                          total: images.length,
                                      })
                                    : formatBytes(image.size)}
                            </p>
                        </div>
                        <Button asChild variant="outline" size="sm">
                            <a href={image.url} download={image.original_name}>
                                <Download aria-hidden="true" />
                                {t('chat_media.viewer.download')}
                            </a>
                        </Button>
                    </div>
                </DialogContent>
            ) : null}
        </Dialog>
    );
}

/** Tarjeta de un archivo que no es imagen (o un SVG): icono, nombre, tamaño y descarga. */
function FileCard({ file }: { file: ChatAttachment }) {
    const Icon = fileIcon(file.mime);

    return (
        <li className="min-w-0">
            <a
                href={file.url}
                download={file.original_name}
                aria-label={t('chat_media.attachments.download', {
                    name: file.original_name,
                    size: formatBytes(file.size),
                })}
                className={cn(
                    'flex max-w-sm min-w-0 items-center gap-3 rounded-md border bg-card p-2 hover:bg-accent',
                    FOCUS_RING,
                )}
                data-test="chat-attachment"
            >
                <span className="flex size-10 shrink-0 items-center justify-center rounded-md border bg-muted">
                    <Icon
                        aria-hidden="true"
                        className="size-5 text-muted-foreground"
                    />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm text-foreground">
                        {file.original_name}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {[
                            extensionLabel(file.original_name),
                            formatBytes(file.size),
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                </span>
                <Download
                    aria-hidden="true"
                    className="size-4 shrink-0 text-muted-foreground"
                />
            </a>
        </li>
    );
}

/**
 * Adjuntos de un mensaje (SPEC §12): las imágenes, en miniatura (el job de la F1; hasta que
 * termina, el icono) y se abren en el visor; los PDF y demás, como tarjeta con icono, nombre y
 * tamaño que se descarga; los SVG, siempre como descarga (D-037).
 */
export function AttachmentList({
    attachments,
    className,
}: {
    attachments: ChatAttachment[];
    className?: string;
}) {
    const [viewer, setViewer] = useState<number | null>(null);
    const thumbnails = useRef<Array<HTMLButtonElement | null>>([]);
    const images = attachments.filter((item) => item.kind === 'image');
    const files = attachments.filter((item) => item.kind !== 'image');

    if (attachments.length === 0) {
        return null;
    }

    return (
        <div className={cn('grid min-w-0 gap-2', className)}>
            {images.length > 0 ? (
                <ul
                    className="flex flex-wrap gap-2"
                    aria-label={t('chat_media.attachments.images', {
                        count: images.length,
                    })}
                >
                    {images.map((image, index) => (
                        <li key={image.id}>
                            <button
                                ref={(element) => {
                                    thumbnails.current[index] = element;
                                }}
                                type="button"
                                className={cn(
                                    'flex size-24 items-center justify-center overflow-hidden rounded-md border bg-muted sm:size-28',
                                    FOCUS_RING,
                                )}
                                aria-label={t('chat_media.attachments.open', {
                                    name: image.original_name,
                                })}
                                onClick={() => setViewer(index)}
                                data-test="chat-attachment-image"
                            >
                                {image.thumbnail_url ? (
                                    <img
                                        src={image.thumbnail_url}
                                        alt=""
                                        loading="lazy"
                                        className="size-full object-cover"
                                    />
                                ) : (
                                    <FileImage
                                        aria-hidden="true"
                                        className="size-6 text-muted-foreground"
                                    />
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}

            {files.length > 0 ? (
                <ul
                    className="grid gap-2"
                    aria-label={t('chat_media.attachments.files', {
                        count: files.length,
                    })}
                >
                    {files.map((file) => (
                        <FileCard key={file.id} file={file} />
                    ))}
                </ul>
            ) : null}

            <ImageViewer
                images={images}
                index={viewer}
                onIndexChange={setViewer}
                returnFocus={(index) => thumbnails.current[index]}
            />
        </div>
    );
}
