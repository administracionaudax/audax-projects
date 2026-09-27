import { Paperclip, Upload } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { ClipboardEvent, DragEvent, ReactNode } from 'react';
import { namePastedFile } from '@/components/chat/media/media-utils';
import { ACCEPTED_EXTENSIONS } from '@/components/tasks/task-attachments';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

function carriesFiles(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

/** Archivos del portapapeles (capturas de pantalla, archivos copiados). */
export function filesFromClipboard(data: DataTransfer | null): File[] {
    if (!data) {
        return [];
    }

    const files = Array.from(data.files ?? []);

    if (files.length > 0) {
        return files.map((file) => namePastedFile(file));
    }

    return Array.from(data.items ?? [])
        .filter((item) => item.kind === 'file')
        .map((item) => item.getAsFile())
        .filter((file): file is File => file !== null)
        .map((file) => namePastedFile(file));
}

/**
 * Zona de la conversación donde se pueden soltar archivos (varios a la vez) y pegar desde el
 * portapapeles (Ctrl/Cmd + V en el editor). Entrega los archivos a `onFiles` sin comprobarlos:
 * useChatMediaComposer().addFiles los comprueba y explica los que no valen.
 */
export function AttachmentDropzone({
    onFiles,
    disabled = false,
    children,
    className,
}: {
    onFiles: (files: File[]) => void;
    disabled?: boolean;
    children: ReactNode;
    className?: string;
}) {
    const [dragging, setDragging] = useState(false);
    const depth = useRef(0);

    const reset = () => {
        depth.current = 0;
        setDragging(false);
    };

    return (
        <div
            className={cn('relative', className)}
            data-test="chat-dropzone"
            onDragEnter={(event) => {
                if (disabled || !carriesFiles(event)) {
                    return;
                }

                event.preventDefault();
                depth.current += 1;
                setDragging(true);
            }}
            onDragOver={(event) => {
                if (disabled || !carriesFiles(event)) {
                    return;
                }

                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
            }}
            onDragLeave={() => {
                if (depth.current === 0) {
                    return;
                }

                depth.current -= 1;

                if (depth.current === 0) {
                    setDragging(false);
                }
            }}
            onDrop={(event) => {
                if (disabled || !carriesFiles(event)) {
                    return;
                }

                event.preventDefault();
                reset();
                const files = Array.from(event.dataTransfer.files ?? []);

                if (files.length > 0) {
                    onFiles(files);
                }
            }}
            onPaste={(event: ClipboardEvent) => {
                if (disabled) {
                    return;
                }

                const files = filesFromClipboard(event.clipboardData);

                if (files.length === 0) {
                    return;
                }

                // Que la imagen no se pegue además como texto o HTML en el editor.
                event.preventDefault();
                onFiles(files);
            }}
        >
            {children}
            {dragging ? (
                <div
                    role="status"
                    className="pointer-events-none absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 rounded-[3px] border-2 border-dashed border-primary bg-background/95 p-4 text-center text-sm text-foreground"
                >
                    <Upload
                        aria-hidden="true"
                        className="size-6 text-primary-text"
                        strokeWidth={1.5}
                    />
                    {t('chat_media.dropzone.drop')}
                </div>
            ) : null}
        </div>
    );
}

/** Botón «Adjuntar» con el selector de archivos del sistema (varios a la vez). */
export function AttachFilesButton({
    onFiles,
    disabled = false,
    label,
    className,
}: {
    onFiles: (files: File[]) => void;
    disabled?: boolean;
    /** Por defecto, solo el icono con «Adjuntar archivos» como nombre accesible. */
    label?: string;
    className?: string;
}) {
    const inputId = useId();
    const input = useRef<HTMLInputElement>(null);

    return (
        <>
            <input
                ref={input}
                id={inputId}
                type="file"
                multiple
                accept={ACCEPTED_EXTENSIONS}
                className="sr-only"
                tabIndex={-1}
                aria-hidden="true"
                data-test="chat-attach-input"
                onChange={(event) => {
                    const files = Array.from(event.target.files ?? []);
                    event.target.value = '';

                    if (files.length > 0) {
                        onFiles(files);
                    }
                }}
            />
            <Button
                type="button"
                variant="ghost"
                size={label ? 'sm' : 'icon'}
                className={className}
                disabled={disabled}
                aria-label={label ? undefined : t('chat_media.files.attach')}
                title={label ? undefined : t('chat_media.files.attach_hint')}
                onClick={() => input.current?.click()}
            >
                <Paperclip aria-hidden="true" />
                {label}
            </Button>
        </>
    );
}
