import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    CircleAlert,
    CircleCheck,
    Clock,
    Download,
    FileArchive,
    Hourglass,
    LoaderCircle,
    PackageOpen,
} from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { EmptyState } from '@/components/empty-state';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import type {
    PersonalDataExportRow,
    PersonalDataExportStatus,
} from '@/types/privacy';

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const STATUS: Record<
    PersonalDataExportStatus,
    { tone: Tone; icon: LucideIcon }
> = {
    pending: { tone: 'neutral', icon: Clock },
    processing: { tone: 'info', icon: LoaderCircle },
    ready: { tone: 'success', icon: CircleCheck },
    failed: { tone: 'danger', icon: CircleAlert },
    expired: { tone: 'neutral', icon: Hourglass },
};

/** Cada cuánto se refresca la lista mientras hay una exportación en curso. */
export const EXPORT_POLL_MS = 5000;

export function isInProgress(row: PersonalDataExportRow): boolean {
    return row.status === 'pending' || row.status === 'processing';
}

/** 1536 → «1,5 KB». */
export function formatBytes(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${formatNumber(value, unit === 0 ? 0 : 1)} ${units[unit]}`;
}

export function ExportStatusBadge({ row }: { row: PersonalDataExportRow }) {
    const meta = STATUS[row.status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {row.status_label}
        </StatusBadge>
    );
}

/**
 * Mientras alguna exportación esté en cola o preparándose, recarga cada pocos segundos solo las
 * props indicadas (sin recargar la página ni mover el foco).
 */
export function usePollWhileInProgress(
    rows: PersonalDataExportRow[],
    only: string[],
): void {
    const busy = rows.some(isInProgress);
    const key = only.join(',');

    useEffect(() => {
        if (!busy) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: key.split(',') });
        }, EXPORT_POLL_MS);

        return () => window.clearInterval(timer);
    }, [busy, key]);
}

function Detail({ row }: { row: PersonalDataExportRow }) {
    switch (row.status) {
        case 'ready':
            return (
                <>
                    {row.expires_at
                        ? t('privacy.exports.available_until', {
                              date: formatDateTime(row.expires_at),
                          })
                        : null}
                    {row.downloaded_at
                        ? ` · ${t('privacy.exports.downloaded_at', {
                              date: formatDateTime(row.downloaded_at),
                          })}`
                        : null}
                </>
            );
        case 'failed':
            return <>{t('privacy.exports.failed_help')}</>;
        case 'expired':
            return <>{t('privacy.exports.expired_help')}</>;
        default:
            return <>{t('privacy.exports.in_progress_help')}</>;
    }
}

/**
 * Exportaciones de datos personales de una persona (D-075): estado con icono y texto, fechas,
 * quién la pidió y la descarga (URL firmada) si está lista. La usan /ajustes/mis-datos y la
 * ficha de la persona en la administración.
 */
export function PersonalDataExportList({
    rows,
    emptyTitle,
    emptyDescription,
}: {
    rows: PersonalDataExportRow[];
    emptyTitle: string;
    emptyDescription?: string;
}) {
    if (rows.length === 0) {
        return (
            <EmptyState
                icon={FileArchive}
                title={emptyTitle}
                description={emptyDescription}
            />
        );
    }

    return (
        <ul
            className="divide-y border-y"
            aria-label={t('privacy.exports.list_label')}
        >
            {rows.map((row) => (
                <li
                    key={row.id}
                    className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center"
                    data-test="personal-data-export"
                >
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="flex flex-wrap items-center gap-2 text-sm">
                            <ExportStatusBadge row={row} />
                            <span>
                                {row.created_at
                                    ? t('privacy.exports.requested_at', {
                                          date: formatDateTime(row.created_at),
                                      })
                                    : null}
                            </span>
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {!row.requested_by_subject && row.requester
                                ? `${t('privacy.exports.requested_by', { name: row.requester })} · `
                                : null}
                            <Detail row={row} />
                        </p>
                    </div>
                    {row.download_url ? (
                        <Button asChild variant="outline" size="sm">
                            {/* Descarga de fichero: un enlace normal, no una visita de Inertia. */}
                            <a href={row.download_url} download>
                                <Download aria-hidden="true" />
                                {row.size_bytes !== null
                                    ? t('privacy.exports.download_size', {
                                          size: formatBytes(row.size_bytes),
                                      })
                                    : t('privacy.exports.download')}
                            </a>
                        </Button>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

/**
 * «Preparar mis datos» / «Preparar sus datos»: pide una exportación (POST) y la lista se refresca
 * sola. Desactivado mientras haya una en curso (el servidor también lo impide).
 */
export function RequestExportButton({
    url,
    label,
    disabled,
    disabledHint,
}: {
    url: string;
    label: string;
    disabled: boolean;
    /** Por qué no se puede pedir ahora (se asocia al botón con aria-describedby). */
    disabledHint: string;
}) {
    const [processing, setProcessing] = useState(false);
    const hintId = useId();

    return (
        <div className="flex flex-wrap items-center gap-3">
            <Button
                type="button"
                disabled={disabled || processing}
                aria-describedby={disabled ? hintId : undefined}
                onClick={() =>
                    router.post(
                        url,
                        {},
                        {
                            preserveScroll: true,
                            onError: toastVisitErrors,
                            onStart: () => setProcessing(true),
                            onFinish: () => setProcessing(false),
                        },
                    )
                }
            >
                {processing ? <Spinner /> : <PackageOpen aria-hidden="true" />}
                {label}
            </Button>
            {disabled ? (
                <p id={hintId} className="text-sm text-muted-foreground">
                    {disabledHint}
                </p>
            ) : null}
        </div>
    );
}
