import { router } from '@inertiajs/react';
import { FileText, Paperclip, ShieldCheck, Trash2, Upload } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { formatBytes } from '@/lib/people-register';
import {
    destroy as destroyDocument,
    show as showDocument,
    store as storeDocument,
} from '@/routes/absences/documents';
import type { AbsenceLeave } from '@/types/leave';

/**
 * Justificantes de una ausencia (Fase 11, R3; D-368): la lista con su descarga, subir uno nuevo y
 * borrar. Si quien mira no puede verlos (un responsable en un tipo de salud), solo sabe si se ha
 * entregado. Nunca se enseñan al resto del equipo.
 */
export function AbsenceDocuments({
    absenceId,
    leave,
    accept = '.pdf,.jpg,.jpeg,.png,.webp,.heic',
}: {
    absenceId: number;
    leave: AbsenceLeave;
    accept?: string;
}) {
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const required = leave.type?.requires_document === true;

    if (leave.documents === null) {
        if (leave.documents_count === 0 && !required) {
            return null;
        }

        return (
            <p className="flex items-center gap-1.5 text-muted-foreground">
                <ShieldCheck aria-hidden="true" className="size-4 shrink-0" />
                {leave.documents_count > 0
                    ? t('leave.documents.delivered_private')
                    : t('leave.documents.missing')}
            </p>
        );
    }

    if (leave.documents.length === 0 && !required && !leave.can.upload) {
        return null;
    }

    const upload = (file: File) => {
        router.post(
            storeDocument.url(absenceId),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setUploading(true),
                onError: toastVisitErrors,
                onFinish: () => {
                    setUploading(false);
                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    // Un tipo que no lo pide y sin ninguno: solo un botón discreto para adjuntar uno.
    const optional = leave.documents.length === 0 && !required;

    return (
        <div className="grid gap-1.5" data-test="absence-documents">
            {optional ? null : (
                <p className="flex items-center gap-1.5">
                    <Paperclip
                        aria-hidden="true"
                        className="size-4 shrink-0 text-muted-foreground"
                    />
                    {leave.documents.length === 0
                        ? t('leave.documents.required')
                        : t('leave.documents.title')}
                </p>
            )}
            {leave.documents.length > 0 ? (
                <ul className="grid gap-1">
                    {leave.documents.map((document) => (
                        <li
                            key={document.id}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <a
                                href={showDocument.url(document.id)}
                                className="inline-flex min-w-0 items-center gap-1.5 underline underline-offset-2"
                            >
                                <FileText
                                    aria-hidden="true"
                                    className="size-4 shrink-0"
                                />
                                <span className="break-all">
                                    {document.name}
                                </span>
                            </a>
                            <span className="text-muted-foreground">
                                {formatBytes(document.size)}
                                {document.created_at
                                    ? ` · ${formatDate(document.created_at)}`
                                    : ''}
                            </span>
                            {document.can_delete ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    aria-label={t(
                                        'leave.documents.delete_label',
                                        {
                                            name: document.name,
                                        },
                                    )}
                                    onClick={() =>
                                        router.delete(
                                            destroyDocument.url(document.id),
                                            {
                                                preserveScroll: true,
                                                onError: toastVisitErrors,
                                            },
                                        )
                                    }
                                >
                                    <Trash2 aria-hidden="true" />
                                    {t('leave.documents.delete')}
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
            {leave.can.upload ? (
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        ref={input}
                        id={`${id}-file`}
                        type="file"
                        accept={accept}
                        className="sr-only"
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            if (file) {
                                upload(file);
                            }
                        }}
                        data-test="absence-document-input"
                    />
                    <Button
                        type="button"
                        variant={optional ? 'ghost' : 'outline'}
                        size="sm"
                        disabled={uploading}
                        aria-describedby={`${id}-privacy`}
                        onClick={() => input.current?.click()}
                    >
                        {uploading ? (
                            <Spinner />
                        ) : optional ? (
                            <Paperclip aria-hidden="true" />
                        ) : (
                            <Upload aria-hidden="true" />
                        )}
                        {optional
                            ? t('leave.documents.attach')
                            : t('leave.documents.upload')}
                    </Button>
                    <span
                        id={`${id}-privacy`}
                        className={
                            optional
                                ? 'sr-only'
                                : 'text-xs text-muted-foreground'
                        }
                    >
                        {t('leave.documents.privacy')}
                    </span>
                </div>
            ) : null}
        </div>
    );
}
