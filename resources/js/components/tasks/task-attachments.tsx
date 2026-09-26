import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    File as FileIcon,
    FileArchive,
    FileImage,
    FileSpreadsheet,
    FileText,
    Paperclip,
    Trash2,
    Upload,
} from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { TASK_RELOAD, toastErrors } from '@/components/tasks/task-requests';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { destroy as destroyAttachment } from '@/routes/attachments';
import { store as storeAttachments } from '@/routes/tasks/attachments';
import type { TaskAttachment } from '@/types';

/** Extensiones admitidas (App\Domain\Tasks\AttachmentStorage::EXTENSIONS, D-037). */
export const ACCEPTED_EXTENSIONS =
    '.jpg,.jpeg,.png,.gif,.webp,.avif,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv,.md,.zip';

/** Archivos por subida (AttachmentStorage::MAX_FILES). */
export const MAX_FILES = 10;

/** 1536 → «1,5 KB»; 5 242 880 → «5 MB». */
export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return t('task_files.bytes', { size: bytes });
    }

    if (bytes < 1024 * 1024) {
        return t('task_files.kilobytes', {
            size: formatNumber(bytes / 1024, 1),
        });
    }

    return t('task_files.megabytes', {
        size: formatNumber(bytes / 1024 / 1024, 1),
    });
}

export function fileIcon(mime: string): LucideIcon {
    if (mime.startsWith('image/')) {
        return FileImage;
    }

    if (
        mime === 'application/pdf' ||
        mime.startsWith('text/') ||
        mime.includes('word') ||
        mime.includes('opendocument.text')
    ) {
        return FileText;
    }

    if (
        mime.includes('sheet') ||
        mime.includes('excel') ||
        mime === 'text/csv'
    ) {
        return FileSpreadsheet;
    }

    if (mime.includes('zip')) {
        return FileArchive;
    }

    return FileIcon;
}

/**
 * Comprueba en el navegador el número y el tamaño de los archivos antes de subirlos (el servidor
 * vuelve a comprobarlo todo, también el tipo real). Devuelve el error o null.
 */
export function checkFiles(files: File[], maxMb: number): string | null {
    if (files.length > MAX_FILES) {
        return t('task_files.too_many', { max: MAX_FILES });
    }

    const tooBig = files.find((file) => file.size > maxMb * 1024 * 1024);

    return tooBig
        ? t('task_files.too_big', { name: tooBig.name, max: maxMb })
        : null;
}

function AttachmentItem({ attachment }: { attachment: TaskAttachment }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const Icon = fileIcon(attachment.mime);

    return (
        <li className="flex items-center gap-3 py-2" data-test="attachment">
            {attachment.thumbnail_url ? (
                <img
                    src={attachment.thumbnail_url}
                    alt=""
                    loading="lazy"
                    className="size-10 shrink-0 rounded-[3px] border object-cover"
                />
            ) : (
                <span className="flex size-10 shrink-0 items-center justify-center rounded-[3px] border bg-muted">
                    <Icon
                        aria-hidden="true"
                        className="size-5 text-muted-foreground"
                    />
                </span>
            )}
            <div className="min-w-0 flex-1">
                <a
                    href={attachment.url}
                    target={attachment.is_image ? '_blank' : undefined}
                    rel="noopener noreferrer"
                    className={cn(
                        'block truncate rounded-[3px] text-sm text-primary-text hover:underline',
                        FOCUS_RING,
                    )}
                    download={
                        attachment.is_image
                            ? undefined
                            : attachment.original_name
                    }
                >
                    {attachment.original_name}
                </a>
                <p className="truncate text-xs text-muted-foreground">
                    {[
                        formatBytes(attachment.size),
                        attachment.uploader?.name,
                        attachment.created_at
                            ? formatDate(attachment.created_at)
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </div>
            {attachment.can_delete ? (
                <ConfirmDialog
                    open={open}
                    onOpenChange={setOpen}
                    trigger={
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={t('task_files.delete', {
                                name: attachment.original_name,
                            })}
                        >
                            <Trash2 aria-hidden="true" />
                        </Button>
                    }
                    title={t('task_files.delete_title')}
                    description={t('task_files.delete_description', {
                        name: attachment.original_name,
                    })}
                    confirmLabel={t('common.delete')}
                    processing={processing}
                    onConfirm={() =>
                        router.delete(destroyAttachment.url(attachment.id), {
                            preserveScroll: true,
                            preserveState: true,
                            only: [
                                ...TASK_RELOAD,
                                'files',
                                'pagination',
                                'tasks',
                            ],
                            onStart: () => setProcessing(true),
                            onFinish: () => {
                                setProcessing(false);
                                setOpen(false);
                            },
                            onError: (errors) => toastErrors(errors),
                        })
                    }
                />
            ) : null}
        </li>
    );
}

export function AttachmentList({
    attachments,
    emptyText,
}: {
    attachments: TaskAttachment[];
    emptyText?: string;
}) {
    if (attachments.length === 0) {
        return emptyText ? (
            <p className="text-sm text-muted-foreground">{emptyText}</p>
        ) : null;
    }

    return (
        <ul className="divide-y" aria-label={t('task_files.list')}>
            {attachments.map((attachment) => (
                <AttachmentItem key={attachment.id} attachment={attachment} />
            ))}
        </ul>
    );
}

/**
 * Selector de archivos accesible (botón + input oculto). Devuelve los archivos elegidos.
 */
export function FilePickerButton({
    onPick,
    disabled,
    label,
    variant = 'outline',
}: {
    onPick: (files: File[]) => void;
    disabled?: boolean;
    label: string;
    variant?: 'outline' | 'ghost';
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
                onChange={(event) => {
                    onPick(Array.from(event.target.files ?? []));
                    event.target.value = '';
                }}
            />
            <Button
                type="button"
                variant={variant}
                size="sm"
                disabled={disabled}
                onClick={() => input.current?.click()}
            >
                <Paperclip aria-hidden="true" />
                {label}
            </Button>
        </>
    );
}

/** Subir adjuntos a la tarea (quien puede editarla). */
export function AttachmentUpload({
    taskId,
    maxMb,
}: {
    taskId: number;
    maxMb: number;
}) {
    const [processing, setProcessing] = useState(false);

    const upload = (files: File[]) => {
        if (files.length === 0) {
            return;
        }

        const error = checkFiles(files, maxMb);

        if (error) {
            toast.error(error);

            return;
        }

        router.post(
            storeAttachments.url(taskId),
            { files },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                only: TASK_RELOAD,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => toastErrors(errors),
                onHttpException: () => {
                    toast.error(t('task_files.upload_failed'));

                    return false;
                },
                onNetworkError: () => {
                    toast.error(t('task_errors.network'));

                    return false;
                },
            },
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-3">
            <FilePickerButton
                onPick={upload}
                disabled={processing}
                label={t('task_files.upload')}
            />
            {processing ? (
                <span
                    role="status"
                    className="inline-flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner />
                    {t('task_files.uploading')}
                </span>
            ) : (
                <span className="text-xs text-muted-foreground">
                    <Upload
                        aria-hidden="true"
                        className="mr-1 inline size-3.5"
                    />
                    {t('task_files.limits', { max: maxMb })}
                </span>
            )}
        </div>
    );
}
