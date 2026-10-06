import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    CircleCheck,
    CircleDot,
    Clock,
    FlaskConical,
    Hammer,
    Lightbulb,
    Paperclip,
    ThumbsUp,
    X,
} from 'lucide-react';
import { useId, useState } from 'react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import { formatMegabytes } from '@/lib/help-center';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { vote as voteRoute } from '@/routes/suggestions';
import type { SuggestionAttachment, SuggestionStatus } from '@/types/weeklies';

const STATUS_META: Record<
    SuggestionStatus,
    { tone: 'neutral' | 'info' | 'warning' | 'success'; icon: LucideIcon }
> = {
    open: { tone: 'neutral', icon: CircleDot },
    future: { tone: 'neutral', icon: Lightbulb },
    planned: { tone: 'info', icon: Clock },
    building_now: { tone: 'warning', icon: Hammer },
    beta: { tone: 'info', icon: FlaskConical },
    completed: { tone: 'success', icon: CircleCheck },
};

export function statusLabel(status: SuggestionStatus): string {
    return t(`suggestions.status.${status}`);
}

export function SuggestionStatusBadge({
    status,
    className,
}: {
    status: SuggestionStatus;
    className?: string;
}) {
    const meta = STATUS_META[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon} className={className}>
            {statusLabel(status)}
        </StatusBadge>
    );
}

/**
 * Votar alternando (F-163): un voto por persona. El número cambia al momento y vuelve si el servidor
 * lo rechaza.
 */
export function VoteButton({
    postId,
    title,
    count,
    voted,
    compact = false,
    disabled = false,
}: {
    postId: number;
    title: string;
    count: number;
    voted: boolean;
    compact?: boolean;
    /** Tablero oculto (D-226): se ve el recuento, pero no se vota. */
    disabled?: boolean;
}) {
    const [optimistic, setOptimistic] = useState<{
        voted: boolean;
        count: number;
    } | null>(null);
    const [source, setSource] = useState({ count, voted });

    if (source.count !== count || source.voted !== voted) {
        setSource({ count, voted });
        setOptimistic(null);
    }

    const shown = optimistic ?? { voted, count };

    return (
        <button
            type="button"
            disabled={disabled}
            aria-pressed={shown.voted}
            aria-label={t(
                shown.voted ? 'suggestions.unvote' : 'suggestions.vote',
                { title, count: shown.count },
            )}
            onClick={(event) => {
                event.stopPropagation();
                setOptimistic({
                    voted: !shown.voted,
                    count: shown.count + (shown.voted ? -1 : 1),
                });
                router.post(
                    voteRoute.url(postId),
                    {},
                    {
                        preserveScroll: true,
                        preserveState: true,
                        only: ['suggestions'],
                        onError: () => setOptimistic(null),
                        onHttpException: () => {
                            setOptimistic(null);

                            return false;
                        },
                    },
                );
            }}
            className={cn(
                'inline-flex shrink-0 flex-col items-center justify-center border text-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                compact ? 'min-w-11 px-2 py-1' : 'min-w-14 px-2.5 py-2',
                shown.voted
                    ? 'border-primary bg-accent text-primary-text'
                    : 'bg-background hover:bg-accent',
            )}
            data-test="suggestion-vote"
        >
            <ThumbsUp
                aria-hidden="true"
                className={cn('size-4', shown.voted && 'fill-current')}
            />
            <span className="tabular font-medium">{shown.count}</span>
        </button>
    );
}

/** Adjuntos: las imágenes con su miniatura y el resto como enlace con su tamaño. */
export function AttachmentList({
    attachments,
    onRemove,
    removed = [],
}: {
    attachments: SuggestionAttachment[];
    onRemove?: (id: number) => void;
    removed?: number[];
}) {
    const shown = attachments.filter((item) => !removed.includes(item.id));

    if (shown.length === 0) {
        return null;
    }

    return (
        <ul
            className="flex flex-wrap gap-2"
            aria-label={t('suggestions.attachments')}
        >
            {shown.map((attachment) => (
                <li
                    key={attachment.id}
                    className="flex max-w-full items-center gap-2 border p-1.5 text-xs"
                >
                    {attachment.is_image && attachment.thumbnail_url ? (
                        <a
                            href={attachment.url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <img
                                src={attachment.thumbnail_url}
                                alt=""
                                className="size-12 object-cover"
                            />
                        </a>
                    ) : (
                        <Paperclip
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                    )}
                    <a
                        href={attachment.url}
                        target="_blank"
                        rel="noreferrer"
                        className="min-w-0 truncate text-primary-text underline"
                    >
                        {attachment.name}
                    </a>
                    <span className="tabular text-muted-foreground">
                        {formatMegabytes(attachment.size)}
                    </span>
                    {onRemove ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-6"
                            onClick={() => onRemove(attachment.id)}
                            aria-label={t('suggestions.remove_attachment', {
                                name: attachment.name,
                            })}
                        >
                            <X aria-hidden="true" />
                        </Button>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

/**
 * Ficheros por subir (F-161 y F-165): elegirlos o pegarlos en el texto (lo hace quien escribe), con
 * el límite de tamaño de los adjuntos, y quitarlos antes de enviar.
 */
export function PendingFiles({
    files,
    onChange,
    maxMegabytes,
    disabled = false,
}: {
    files: File[];
    onChange: (files: File[]) => void;
    maxMegabytes: number;
    disabled?: boolean;
}) {
    const id = useId();
    const [error, setError] = useState<string | null>(null);

    return (
        <div className="grid gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <label
                    htmlFor={`${id}-files`}
                    className={cn(
                        'inline-flex h-8 cursor-pointer items-center gap-1.5 border px-3 text-sm focus-within:ring-2 focus-within:ring-ring hover:bg-accent',
                        disabled && 'pointer-events-none opacity-50',
                    )}
                >
                    <Paperclip aria-hidden="true" className="size-4" />
                    {t('suggestions.attach')}
                    <input
                        id={`${id}-files`}
                        type="file"
                        multiple
                        className="sr-only"
                        disabled={disabled}
                        onChange={(event) => {
                            const picked = Array.from(event.target.files ?? []);
                            event.target.value = '';
                            const tooBig = picked.filter(
                                (file) =>
                                    file.size > maxMegabytes * 1024 * 1024,
                            );
                            setError(
                                tooBig.length > 0
                                    ? t('suggestions.file_too_big', {
                                          name: tooBig[0].name,
                                          max: maxMegabytes,
                                      })
                                    : null,
                            );
                            onChange([
                                ...files,
                                ...picked.filter(
                                    (file) =>
                                        file.size <= maxMegabytes * 1024 * 1024,
                                ),
                            ]);
                        }}
                    />
                </label>
                <span className="text-xs text-muted-foreground">
                    {t('suggestions.attach_help', { max: maxMegabytes })}
                </span>
            </div>
            {error ? (
                <p className="text-sm text-destructive" role="alert">
                    {error}
                </p>
            ) : null}
            {files.length > 0 ? (
                <ul className="flex flex-wrap gap-2">
                    {files.map((file, index) => (
                        <li
                            key={`${file.name}-${file.size}-${file.lastModified}`}
                            className="flex items-center gap-1.5 border px-2 py-1 text-xs"
                        >
                            <span className="max-w-48 truncate">
                                {file.name}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-6"
                                disabled={disabled}
                                onClick={() =>
                                    onChange(
                                        files.filter((_, i) => i !== index),
                                    )
                                }
                                aria-label={t('suggestions.remove_file', {
                                    name: file.name,
                                })}
                            >
                                <X aria-hidden="true" />
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
