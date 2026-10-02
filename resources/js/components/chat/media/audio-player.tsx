import { CircleAlert, Loader2, Pause, Play, RotateCcw } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { formatClock, formatRate } from '@/components/chat/media/media-utils';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Velocidades de reproducción, en orden. */
export const PLAYBACK_RATES = [1, 1.5, 2] as const;

/** Paso de las flechas en la barra de progreso (segundos). */
export const SEEK_STEP_SECONDS = 5;

/** Al empezar un audio se paran los demás de la página. */
const PLAY_EVENT = 'chat-media:play';

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

/**
 * Barra de progreso accesible (patrón slider de WAI-ARIA): flechas ±5 s, Re Pág/Av Pág ±10 %,
 * Inicio y Fin; con el ratón o el dedo, se pulsa o se arrastra.
 */
function SeekBar({
    value,
    max,
    disabled,
    onSeek,
}: {
    value: number;
    max: number;
    disabled: boolean;
    onSeek: (seconds: number) => void;
}) {
    const track = useRef<HTMLDivElement>(null);
    const dragging = useRef(false);
    const percent = max > 0 ? clamp((value / max) * 100, 0, 100) : 0;

    const seekToPointer = (event: PointerEvent<HTMLDivElement>) => {
        const rect = track.current?.getBoundingClientRect();

        if (!rect || rect.width === 0 || max <= 0) {
            return;
        }

        onSeek(clamp((event.clientX - rect.left) / rect.width, 0, 1) * max);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const page = Math.max(SEEK_STEP_SECONDS, max / 10);
        const next: Record<string, number> = {
            ArrowRight: value + SEEK_STEP_SECONDS,
            ArrowUp: value + SEEK_STEP_SECONDS,
            ArrowLeft: value - SEEK_STEP_SECONDS,
            ArrowDown: value - SEEK_STEP_SECONDS,
            PageUp: value + page,
            PageDown: value - page,
            Home: 0,
            End: max,
        };

        if (disabled || !(event.key in next)) {
            return;
        }

        event.preventDefault();
        onSeek(clamp(next[event.key], 0, max));
    };

    return (
        <div
            ref={track}
            role="slider"
            tabIndex={disabled ? -1 : 0}
            aria-label={t('chat_media.player.position')}
            aria-valuemin={0}
            aria-valuemax={Math.round(max)}
            aria-valuenow={Math.round(value)}
            aria-valuetext={t('chat_media.player.position_text', {
                current: formatClock(value * 1000),
                total: formatClock(max * 1000),
            })}
            aria-disabled={disabled || undefined}
            className={cn(
                'relative flex h-6 min-w-16 flex-1 cursor-pointer touch-none items-center rounded-[3px]',
                disabled && 'cursor-default opacity-60',
                FOCUS_RING,
            )}
            onKeyDown={onKeyDown}
            onPointerDown={(event) => {
                if (disabled) {
                    return;
                }

                dragging.current = true;
                event.currentTarget.setPointerCapture?.(event.pointerId);
                seekToPointer(event);
            }}
            onPointerMove={(event) => {
                if (dragging.current) {
                    seekToPointer(event);
                }
            }}
            onPointerUp={(event) => {
                dragging.current = false;
                event.currentTarget.releasePointerCapture?.(event.pointerId);
            }}
            onPointerCancel={() => {
                dragging.current = false;
            }}
        >
            <span className="absolute inset-x-0 h-1 rounded-[3px] bg-muted" />
            <span
                className="absolute left-0 h-1 rounded-[3px] bg-primary"
                style={{ width: `${percent}%` }}
            />
            <span
                className="absolute size-3 -translate-x-1/2 rounded-[3px] border-2 border-background bg-primary"
                style={{ left: `${percent}%` }}
            />
        </div>
    );
}

/**
 * Reproductor propio y accesible de los audios del chat (SPEC §12): reproducir y pausar, barra
 * de progreso con teclado, tiempo y duración, y velocidad 1× / 1,5× / 2×. El audio se pide con
 * Range (se puede saltar a cualquier punto). Si la URL firmada ha caducado (la página lleva más
 * de una hora abierta), `onSourceExpired` pide una nueva y se reintenta.
 */
export function AudioPlayer({
    src,
    durationMs,
    label,
    onSourceExpired,
    className,
}: {
    src: string;
    /** La que se conoce (del servidor); si no, la del propio audio. */
    durationMs?: number | null;
    label?: string;
    onSourceExpired?: () => Promise<string | null>;
    className?: string;
}) {
    const id = useId();
    const audio = useRef<HTMLAudioElement>(null);
    const [source, setSource] = useState(src);
    const [playing, setPlaying] = useState(false);
    const [current, setCurrent] = useState(0);
    const [mediaDuration, setMediaDuration] = useState<number | null>(null);
    const [rate, setRate] = useState<number>(PLAYBACK_RATES[0]);
    const [load, setLoad] = useState<LoadState>('idle');
    const refreshed = useRef(false);
    const resumeAfterRefresh = useRef(false);
    // Si quien escucha le ha dado a reproducir (para retomar tras renovar la URL, y solo entonces).
    const wantsToPlay = useRef(false);
    const [previousSrc, setPreviousSrc] = useState(src);
    // URL firmada más reciente que llegó mientras se escuchaba: se usa si la actual falla.
    const [newerSrc, setNewerSrc] = useState<string | null>(null);

    // Una URL nueva desde fuera (p. ej. la consulta periódica trae el mensaje con otra firma) solo
    // sustituye a la actual si el audio no se ha empezado a escuchar (ni se está cargando para
    // reproducirlo) o si la actual ha fallado: cambiar el src corta la reproducción y la posición.
    if (previousSrc !== src) {
        setPreviousSrc(src);

        if (
            load === 'error' ||
            (!playing && load !== 'loading' && current === 0)
        ) {
            setSource(src);
            setNewerSrc(null);
        } else {
            setNewerSrc(src);
        }
    }

    const known =
        durationMs !== null && durationMs !== undefined && durationMs > 0
            ? durationMs / 1000
            : null;
    const duration = known ?? mediaDuration ?? 0;

    useEffect(() => {
        const onOtherPlay = (event: Event) => {
            if ((event as CustomEvent<string>).detail !== id) {
                audio.current?.pause();
            }
        };

        window.addEventListener(PLAY_EVENT, onOtherPlay);

        return () => window.removeEventListener(PLAY_EVENT, onOtherPlay);
    }, [id]);

    useEffect(() => {
        if (audio.current) {
            audio.current.playbackRate = rate;
        }
    }, [rate, source]);

    const play = async () => {
        const element = audio.current;

        if (!element) {
            return;
        }

        wantsToPlay.current = true;
        window.dispatchEvent(new CustomEvent(PLAY_EVENT, { detail: id }));

        if (load === 'idle' || load === 'error') {
            setLoad('loading');
        }

        try {
            await element.play();
        } catch {
            // El error de carga lo trata onError; un play() interrumpido por pause() no es un error.
        }
    };

    const toggle = () => {
        if (playing) {
            wantsToPlay.current = false;
            audio.current?.pause();
        } else {
            void play();
        }
    };

    const seek = (seconds: number) => {
        const element = audio.current;

        if (!element) {
            return;
        }

        element.currentTime = seconds;
        setCurrent(seconds);
    };

    const retry = () => {
        refreshed.current = false;
        setLoad('loading');
        audio.current?.load();
        void play();
    };

    const onError = async () => {
        setPlaying(false);

        // Si ya llegó una URL más reciente, primero esa (sin pedir otra al servidor).
        if (newerSrc !== null && newerSrc !== source) {
            resumeAfterRefresh.current = wantsToPlay.current;
            setSource(newerSrc);
            setNewerSrc(null);

            return;
        }

        if (onSourceExpired && !refreshed.current) {
            refreshed.current = true;
            setLoad('loading');
            const fresh = await onSourceExpired().catch(() => null);

            if (fresh) {
                resumeAfterRefresh.current = wantsToPlay.current;
                setSource(fresh);

                return;
            }
        }

        setLoad('error');
    };

    const nextRate = () => {
        const index = PLAYBACK_RATES.indexOf(
            rate as (typeof PLAYBACK_RATES)[number],
        );
        setRate(PLAYBACK_RATES[(index + 1) % PLAYBACK_RATES.length]);
    };

    return (
        <div
            role="group"
            aria-label={label ?? t('chat_media.player.label')}
            className={cn(
                'flex min-w-0 items-center gap-1.5 rounded-[3px] border bg-card py-1 pr-2 pl-1',
                className,
            )}
            data-test="chat-audio-player"
        >
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8 shrink-0"
                aria-label={
                    playing
                        ? t('chat_media.player.pause')
                        : t('chat_media.player.play')
                }
                onClick={toggle}
            >
                {load === 'loading' && !playing ? (
                    <Loader2 aria-hidden="true" className="animate-spin" />
                ) : playing ? (
                    <Pause aria-hidden="true" />
                ) : (
                    <Play aria-hidden="true" />
                )}
            </Button>

            <SeekBar
                value={current}
                max={duration}
                disabled={duration <= 0 || load === 'error'}
                onSeek={seek}
            />

            <span
                className="tabular shrink-0 text-xs text-muted-foreground"
                aria-hidden="true"
            >
                {formatClock(current * 1000)} / {formatClock(duration * 1000)}
            </span>

            <button
                type="button"
                className={cn(
                    'tabular shrink-0 rounded-[3px] border px-1.5 py-0.5 text-xs text-foreground hover:bg-accent',
                    FOCUS_RING,
                )}
                aria-label={t('chat_media.player.speed', {
                    rate: formatRate(rate),
                })}
                onClick={nextRate}
            >
                {formatRate(rate)}
            </button>

            {load === 'error' ? (
                <span className="inline-flex shrink-0 items-center gap-1 text-xs text-foreground">
                    <CircleAlert
                        aria-hidden="true"
                        className="size-3.5 text-danger"
                    />
                    <span role="alert">{t('chat_media.player.error')}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label={t('chat_media.player.retry')}
                        onClick={retry}
                    >
                        <RotateCcw aria-hidden="true" />
                    </Button>
                </span>
            ) : null}

            <audio
                ref={audio}
                src={source}
                preload="metadata"
                className="hidden"
                onLoadedMetadata={(event) => {
                    const element = event.currentTarget;
                    setLoad(wantsToPlay.current ? 'loading' : 'ready');

                    if (Number.isFinite(element.duration)) {
                        setMediaDuration(element.duration);
                    }

                    element.playbackRate = rate;
                    // Cargó bien: si vuelve a caducar (otra hora más), se podrá renovar otra vez.
                    refreshed.current = false;

                    if (resumeAfterRefresh.current) {
                        resumeAfterRefresh.current = false;
                        element.currentTime = current;
                        void play();
                    }
                }}
                onDurationChange={(event) => {
                    if (Number.isFinite(event.currentTarget.duration)) {
                        setMediaDuration(event.currentTarget.duration);
                    }
                }}
                onCanPlay={() => setLoad('ready')}
                onPlaying={() => setLoad('ready')}
                onWaiting={() => setLoad('loading')}
                onTimeUpdate={(event) =>
                    setCurrent(event.currentTarget.currentTime)
                }
                onPlay={() => setPlaying(true)}
                onPause={() => setPlaying(false)}
                onEnded={(event) => {
                    wantsToPlay.current = false;
                    setPlaying(false);
                    const element = event.currentTarget;

                    // Los webm de MediaRecorder no traen la duración: al acabar ya se sabe.
                    if (mediaDuration === null && known === null) {
                        setMediaDuration(element.currentTime);
                    }

                    element.currentTime = 0;
                    setCurrent(0);
                }}
                onError={() => void onError()}
            />
        </div>
    );
}
