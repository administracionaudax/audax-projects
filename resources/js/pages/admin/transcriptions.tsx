import { Head, Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    BellRing,
    CircleAlert,
    CircleCheck,
    Clock,
    FileAudio,
    Loader,
    RefreshCw,
    RotateCcw,
    TriangleAlert,
} from 'lucide-react';
import { useId, useState } from 'react';
import { formatClock, formatSpan } from '@/components/chat/media/media-utils';
import type {
    AdminTranscriptionFilter,
    AdminTranscriptionRow,
    AdminTranscriptionsPageProps,
    TranscriptionStatus,
} from '@/components/chat/media/types';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import {
    index as transcriptionsIndex,
    retry as retryRoute,
    retryFailed as retryFailedRoute,
} from '@/routes/admin/transcriptions';

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const STATUS: Record<
    TranscriptionStatus,
    { tone: Tone; icon: LucideIcon; label: TranslationKey }
> = {
    pending: {
        tone: 'neutral',
        icon: Clock,
        label: 'chat_media.admin.status.pending',
    },
    processing: {
        tone: 'info',
        icon: Loader,
        label: 'chat_media.admin.status.processing',
    },
    failed: {
        tone: 'danger',
        icon: CircleAlert,
        label: 'chat_media.admin.status.failed',
    },
    done: {
        tone: 'success',
        icon: CircleCheck,
        label: 'chat_media.admin.status.done',
    },
};

const FILTERS: {
    value: AdminTranscriptionFilter | null;
    status: TranscriptionStatus | null;
    label: TranslationKey;
}[] = [
    { value: null, status: null, label: 'chat_media.admin.filter.all' },
    {
        value: 'pendientes',
        status: 'pending',
        label: 'chat_media.admin.filter.pending',
    },
    {
        value: 'en-curso',
        status: 'processing',
        label: 'chat_media.admin.filter.processing',
    },
    {
        value: 'fallidas',
        status: 'failed',
        label: 'chat_media.admin.filter.failed',
    },
    { value: 'hechas', status: 'done', label: 'chat_media.admin.filter.done' },
];

const RELOAD = ['transcriptions', 'counts', 'pagination'];

function RetryButton({ row }: { row: AdminTranscriptionRow }) {
    const [processing, setProcessing] = useState(false);

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={processing}
            aria-label={t('chat_media.admin.retry_one', { id: row.message_id })}
            onClick={() =>
                router.post(
                    retryRoute.url(row.id),
                    {},
                    {
                        preserveScroll: true,
                        preserveState: true,
                        only: RELOAD,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                    },
                )
            }
        >
            {processing ? <Spinner /> : <RotateCcw aria-hidden="true" />}
            {t('chat_media.admin.retry')}
        </Button>
    );
}

function Conversation({ row }: { row: AdminTranscriptionRow }) {
    const label =
        row.conversation.type === 'direct'
            ? t('chat_media.admin.direct')
            : (row.conversation.label ?? t('chat_media.admin.untitled'));

    return (
        <div className="grid min-w-0 gap-0.5">
            <span className="truncate text-sm">
                {row.url ? (
                    <Link
                        href={row.url}
                        className={cn(
                            'rounded-[3px] text-primary-text hover:underline',
                            FOCUS_RING,
                        )}
                    >
                        {label}
                    </Link>
                ) : (
                    label
                )}
            </span>
            <span className="text-xs text-muted-foreground">
                {[
                    t('chat_media.admin.message', { id: row.message_id }),
                    row.author,
                    row.message_deleted
                        ? t('chat_media.admin.deleted')
                        : row.message_hidden
                          ? t('chat_media.admin.hidden')
                          : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </span>
        </div>
    );
}

/**
 * Transcripciones de los audios del chat (SPEC §12, D-070), solo admin: estado de cada una,
 * intentos, último error, duración y tiempo de proceso, y «Relanzar» (una o todas las fallidas).
 */
export default function AdminTranscriptions({
    filters,
    counts,
    maxAudioSeconds,
    transcriptions,
    pagination,
}: AdminTranscriptionsPageProps) {
    const captionId = useId();
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const total =
        counts.pending + counts.processing + counts.failed + counts.done;
    const current = FILTERS.find((filter) => filter.value === filters.status);

    return (
        <>
            <Head title={t('chat_media.admin.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <h1 className="text-2xl font-normal tracking-tight">
                            {t('chat_media.admin.heading')}
                        </h1>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            {t('chat_media.admin.description', {
                                max: formatClock(maxAudioSeconds * 1000),
                            })}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={refreshing}
                            onClick={() =>
                                router.reload({
                                    only: RELOAD,
                                    onStart: () => setRefreshing(true),
                                    onFinish: () => setRefreshing(false),
                                })
                            }
                        >
                            {refreshing ? (
                                <Spinner />
                            ) : (
                                <RefreshCw aria-hidden="true" />
                            )}
                            {t('chat_media.admin.refresh')}
                        </Button>
                        <ConfirmDialog
                            open={confirmOpen}
                            onOpenChange={setConfirmOpen}
                            destructive={false}
                            trigger={
                                <Button
                                    type="button"
                                    disabled={counts.failed === 0}
                                >
                                    <RotateCcw aria-hidden="true" />
                                    {t('chat_media.admin.retry_failed', {
                                        count: counts.failed,
                                    })}
                                </Button>
                            }
                            title={t('chat_media.admin.retry_failed_title')}
                            description={t(
                                'chat_media.admin.retry_failed_description',
                                { count: counts.failed },
                            )}
                            confirmLabel={t(
                                'chat_media.admin.retry_failed_confirm',
                            )}
                            processing={retrying}
                            onConfirm={() =>
                                router.post(
                                    retryFailedRoute.url(),
                                    {},
                                    {
                                        preserveScroll: true,
                                        only: RELOAD,
                                        onStart: () => setRetrying(true),
                                        onFinish: () => {
                                            setRetrying(false);
                                            setConfirmOpen(false);
                                        },
                                    },
                                )
                            }
                        />
                    </div>
                </header>

                <nav
                    aria-label={t('chat_media.admin.filter.label')}
                    className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
                >
                    <ul className="flex min-w-max gap-1">
                        {FILTERS.map((filter) => {
                            const active = filter.value === filters.status;
                            const count =
                                filter.status === null
                                    ? total
                                    : counts[filter.status];
                            const Icon =
                                filter.status === null
                                    ? FileAudio
                                    : STATUS[filter.status].icon;

                            return (
                                <li key={filter.value ?? 'all'}>
                                    <Link
                                        href={transcriptionsIndex.url({
                                            query: filter.value
                                                ? { estado: filter.value }
                                                : {},
                                        })}
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        preserveScroll
                                        className={cn(
                                            '-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm',
                                            active
                                                ? 'border-primary font-medium text-foreground'
                                                : 'border-transparent text-muted-foreground hover:text-foreground',
                                            FOCUS_RING,
                                        )}
                                    >
                                        <Icon
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        {t(filter.label)}
                                        <span className="tabular rounded-[3px] bg-muted px-1.5 text-xs text-muted-foreground">
                                            {count}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                {transcriptions.length === 0 ? (
                    <EmptyState
                        icon={FileAudio}
                        title={
                            filters.status
                                ? t('chat_media.admin.empty_filtered', {
                                      filter: t(
                                          current?.label ??
                                              'chat_media.admin.filter.all',
                                      ).toLowerCase(),
                                  })
                                : t('chat_media.admin.empty')
                        }
                        description={t('chat_media.admin.empty_help')}
                    />
                ) : (
                    <div
                        className={cn(
                            'overflow-x-auto rounded-[3px] border',
                            FOCUS_RING,
                        )}
                        role="region"
                        aria-labelledby={captionId}
                        tabIndex={0}
                    >
                        <table className="w-full min-w-[60rem] text-sm">
                            <caption id={captionId} className="sr-only">
                                {t('chat_media.admin.caption')}
                            </caption>
                            <thead>
                                <tr className="border-b text-left text-xs text-muted-foreground">
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('chat_media.admin.column.audio')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('chat_media.admin.column.status')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t('chat_media.admin.column.attempts')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('chat_media.admin.column.error')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t('chat_media.admin.column.duration')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        {t(
                                            'chat_media.admin.column.processing',
                                        )}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 font-medium"
                                    >
                                        {t('chat_media.admin.column.sent')}
                                    </th>
                                    <th scope="col" className="w-32 px-3 py-2">
                                        <span className="sr-only">
                                            {t('common.actions')}
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {transcriptions.map((row) => {
                                    const status = STATUS[row.status];

                                    return (
                                        <tr
                                            key={row.id}
                                            className="border-b align-top last:border-b-0"
                                            data-test="transcription-row"
                                        >
                                            <th
                                                scope="row"
                                                className="max-w-72 px-3 py-2 text-left font-normal"
                                            >
                                                <Conversation row={row} />
                                            </th>
                                            <td className="px-3 py-2">
                                                <div className="flex flex-wrap gap-1">
                                                    <StatusBadge
                                                        tone={status.tone}
                                                        icon={status.icon}
                                                    >
                                                        {t(status.label)}
                                                    </StatusBadge>
                                                    {row.over_limit ? (
                                                        <StatusBadge
                                                            tone="warning"
                                                            icon={TriangleAlert}
                                                        >
                                                            {t(
                                                                'chat_media.admin.over_limit',
                                                            )}
                                                        </StatusBadge>
                                                    ) : null}
                                                    {row.admin_notified ? (
                                                        <StatusBadge
                                                            tone="neutral"
                                                            icon={BellRing}
                                                        >
                                                            {t(
                                                                'chat_media.admin.notified',
                                                            )}
                                                        </StatusBadge>
                                                    ) : null}
                                                </div>
                                            </td>
                                            <td className="tabular px-3 py-2 text-right">
                                                {row.attempts}
                                            </td>
                                            <td className="max-w-72 px-3 py-2 text-xs text-muted-foreground">
                                                {row.last_error ? (
                                                    <details>
                                                        <summary
                                                            className={cn(
                                                                'cursor-pointer truncate rounded-[3px]',
                                                                FOCUS_RING,
                                                            )}
                                                        >
                                                            {row.last_error}
                                                        </summary>
                                                        <p className="mt-1 break-words whitespace-pre-line text-foreground">
                                                            {row.last_error}
                                                        </p>
                                                    </details>
                                                ) : (
                                                    <span
                                                        aria-label={t(
                                                            'chat_media.admin.no_error',
                                                        )}
                                                    >
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                                {row.audio_duration_ms !== null
                                                    ? formatClock(
                                                          row.audio_duration_ms,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                                {row.processing_ms !== null
                                                    ? formatSpan(
                                                          row.processing_ms,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground">
                                                {formatDateTime(row.created_at)}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {row.can_retry ? (
                                                    <RetryButton row={row} />
                                                ) : null}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {pagination.last_page > 1 ? (
                    <nav
                        aria-label={t('chat_media.admin.pagination')}
                        className="flex items-center justify-between gap-3 text-sm"
                    >
                        {pagination.prev_url ? (
                            <Link
                                href={pagination.prev_url}
                                preserveScroll
                                className={cn(
                                    'rounded-[3px] text-primary-text underline',
                                    FOCUS_RING,
                                )}
                            >
                                {t('chat_media.admin.previous')}
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span className="text-muted-foreground">
                            {t('chat_media.admin.page', {
                                page: pagination.current_page,
                                pages: pagination.last_page,
                            })}
                        </span>
                        {pagination.next_url ? (
                            <Link
                                href={pagination.next_url}
                                preserveScroll
                                className={cn(
                                    'rounded-[3px] text-primary-text underline',
                                    FOCUS_RING,
                                )}
                            >
                                {t('chat_media.admin.next')}
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                ) : null}
            </div>
        </>
    );
}

AdminTranscriptions.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('chat_media.admin.title'), href: transcriptionsIndex() },
    ],
};
