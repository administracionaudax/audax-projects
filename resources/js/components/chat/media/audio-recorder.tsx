import {
    CircleAlert,
    Loader2,
    Mic,
    MicOff,
    Send,
    Square,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import {
    formatClock,
    useMediaLimits,
} from '@/components/chat/media/media-utils';
import type { RecorderError } from '@/components/chat/media/use-audio-recorder';
import {
    useAudioRecorder,
    WAVEFORM_BARS,
} from '@/components/chat/media/use-audio-recorder';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const ERRORS: Record<RecorderError, TranslationKey> = {
    denied: 'chat_media.recorder.error.denied',
    no_device: 'chat_media.recorder.error.no_device',
    busy: 'chat_media.recorder.error.busy',
    failed: 'chat_media.recorder.error.failed',
    too_short: 'chat_media.recorder.error.too_short',
};

/** Forma de onda en vivo: el nivel reciente del micrófono en barras (decorativa). */
export function LiveWaveform({
    levels,
    className,
}: {
    levels: number[];
    className?: string;
}) {
    const bars = [
        ...Array.from(
            { length: Math.max(0, WAVEFORM_BARS - levels.length) },
            () => 0,
        ),
        ...levels.slice(-WAVEFORM_BARS),
    ];

    return (
        <svg
            aria-hidden="true"
            viewBox={`0 0 ${WAVEFORM_BARS * 4} 24`}
            preserveAspectRatio="none"
            className={cn('h-6 w-full text-primary', className)}
            data-test="chat-recorder-waveform"
        >
            {bars.map((level, index) => {
                const height = Math.max(2, Math.round(level * 22));

                return (
                    <rect
                        key={index}
                        x={index * 4 + 1}
                        y={(24 - height) / 2}
                        width={2}
                        height={height}
                        rx={1}
                        fill="currentColor"
                    />
                );
            })}
        </svg>
    );
}

/**
 * Grabar un audio (SPEC §12): pide el micrófono, muestra la forma de onda en vivo y el contador,
 * se para sola al llegar a la duración máxima y permite cancelar o enviar. Todo con teclado
 * (Escape cancela). Sin MediaRecorder (o fuera de HTTPS) no hay botón: se explica por qué.
 *
 * `onRecorded(file, durationMs)` recibe el archivo (webm, ogg o m4a, con nombre) y su duración:
 * C1 lo envía con useChatMediaComposer().sendAudio(file, durationMs).
 */
export function AudioRecorder({
    onRecorded,
    maxSeconds,
    disabled = false,
    className,
}: {
    onRecorded: (file: File, durationMs: number) => void;
    /** Por defecto, el ajuste max_audio_seconds de la prop compartida `config`. */
    maxSeconds?: number;
    disabled?: boolean;
    className?: string;
}) {
    const limits = useMediaLimits();
    const max = maxSeconds ?? limits.maxAudioSeconds;
    const recorder = useAudioRecorder({ maxSeconds: max, onRecorded });
    const startButton = useRef<HTMLButtonElement>(null);
    const primaryButton = useRef<HTMLButtonElement>(null);
    const { status, error, support } = recorder;
    const active = status !== 'idle';
    const wasActive = useRef(false);

    // El foco sigue a los controles que aparecen y desaparecen.
    useEffect(() => {
        if (status === 'recording' || status === 'stopped') {
            primaryButton.current?.focus();
        } else if (status === 'idle' && wasActive.current) {
            startButton.current?.focus();
        }

        wasActive.current = status !== 'idle';
    }, [status]);

    if (support !== 'supported') {
        return (
            <p
                className={cn(
                    'inline-flex items-center gap-1.5 text-xs text-muted-foreground',
                    className,
                )}
                data-test="chat-recorder-unsupported"
            >
                <MicOff aria-hidden="true" className="size-4 shrink-0" />
                {t(
                    support === 'insecure'
                        ? 'chat_media.recorder.insecure'
                        : 'chat_media.recorder.unsupported',
                )}
            </p>
        );
    }

    const announcement =
        status === 'recording'
            ? t('chat_media.recorder.recording_started')
            : status === 'stopped'
              ? t('chat_media.recorder.reached_max', {
                    max: formatClock(max * 1000),
                })
              : '';

    return (
        <div className={cn('min-w-0', className)} data-test="chat-recorder">
            <p className="sr-only" role="status" aria-live="polite">
                {announcement}
            </p>

            {!active ? (
                <Button
                    ref={startButton}
                    type="button"
                    variant="ghost"
                    size="icon"
                    disabled={disabled}
                    aria-label={t('chat_media.recorder.start')}
                    title={t('chat_media.recorder.start_hint', {
                        max: formatClock(max * 1000),
                    })}
                    onClick={() => void recorder.start()}
                >
                    <Mic aria-hidden="true" />
                </Button>
            ) : (
                <div
                    role="group"
                    aria-label={t('chat_media.recorder.group')}
                    className="flex min-w-0 flex-wrap items-center gap-2 rounded-[3px] border bg-card px-2 py-1"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            recorder.cancel();
                        }
                    }}
                >
                    {status === 'requesting' ? (
                        <span
                            className="inline-flex items-center gap-2 text-sm text-muted-foreground"
                            role="status"
                        >
                            <Loader2
                                aria-hidden="true"
                                className="size-4 animate-spin"
                            />
                            {t('chat_media.recorder.requesting')}
                        </span>
                    ) : (
                        <>
                            <span
                                className="inline-flex items-center gap-1.5 text-sm"
                                aria-hidden="true"
                            >
                                {status === 'recording' ? (
                                    <span className="size-2.5 animate-pulse rounded-[3px] bg-danger" />
                                ) : (
                                    <Square className="size-3 text-muted-foreground" />
                                )}
                                {status === 'recording'
                                    ? t('chat_media.recorder.recording')
                                    : t('chat_media.recorder.stopped')}
                            </span>
                            <LiveWaveform
                                levels={recorder.levels}
                                className="min-w-16 flex-1 basis-24"
                            />
                            <span
                                role="timer"
                                aria-label={t('chat_media.recorder.elapsed')}
                                className="tabular text-sm text-muted-foreground"
                            >
                                {formatClock(recorder.elapsedMs)} /{' '}
                                {formatClock(max * 1000)}
                            </span>
                        </>
                    )}

                    <div className="ml-auto flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={recorder.cancel}
                        >
                            {status === 'stopped' ? (
                                <Trash2 aria-hidden="true" />
                            ) : (
                                <X aria-hidden="true" />
                            )}
                            {status === 'stopped'
                                ? t('chat_media.recorder.discard')
                                : t('chat_media.recorder.cancel')}
                        </Button>
                        {status !== 'requesting' ? (
                            <Button
                                ref={primaryButton}
                                type="button"
                                size="sm"
                                onClick={recorder.finish}
                            >
                                <Send aria-hidden="true" />
                                {t('chat_media.recorder.send')}
                            </Button>
                        ) : null}
                    </div>

                    {status === 'stopped' ? (
                        <p className="w-full text-xs text-muted-foreground">
                            {t('chat_media.recorder.reached_max', {
                                max: formatClock(max * 1000),
                            })}
                        </p>
                    ) : null}
                </div>
            )}

            {error ? (
                <p
                    role="alert"
                    className="mt-1 flex items-start gap-1.5 text-xs text-foreground"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0 text-danger"
                    />
                    <span className="min-w-0 flex-1">{t(ERRORS[error])}</span>
                    <button
                        type="button"
                        className={cn(
                            'rounded-[3px] text-primary-text underline',
                            FOCUS_RING,
                        )}
                        onClick={recorder.dismissError}
                    >
                        {t('chat_media.recorder.dismiss')}
                    </button>
                </p>
            ) : null}
        </div>
    );
}
