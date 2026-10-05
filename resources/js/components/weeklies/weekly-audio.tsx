import { Gauge, Loader2, Pause, Play, RotateCcw } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { formatClock } from '@/components/chat/media/media-utils';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { WeeklyAudioSection } from '@/types/weeklies';

/**
 * Audio del informe de la weekly (F-085 y F-086), port de ReportView, InlineAudioPlayer y
 * weeklyAudioSync de WeeklySync:
 * - al empezar un audio se paran los demás de la página (solo suena uno a la vez),
 * - el reproductor principal lleva una marca por cliente para saltar a su parte, velocidad y
 *   «volver a empezar»,
 * - cada tarjeta de cliente lleva su propio reproductor con su sección.
 * Los dos son accesibles con el teclado (patrón slider de WAI-ARIA en la barra) y el menú de
 * velocidad se cierra con Escape.
 */

/** Evento con el que un reproductor avisa a los demás de que empieza (weeklyAudioSync). */
export const WEEKLY_AUDIO_PLAY_EVENT = 'audax:weekly-audio-play';

export function dispatchWeeklyAudioPlay(playerId: string): void {
    window.dispatchEvent(
        new CustomEvent(WEEKLY_AUDIO_PLAY_EVENT, { detail: { playerId } }),
    );
}

/** Velocidades del reproductor principal y de los de cada cliente (las del original). */
export const MAIN_RATES = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
export const INLINE_RATES = [0.75, 1, 1.25, 1.5, 2];

/** «x1,25», como el original. */
export function formatPlaybackRate(rate: number): string {
    return `x${formatNumber(rate, 2)}`;
}

/** Una sección colocada en la línea de tiempo: dónde empieza y cuánto dura (en segundos). */
export type TimedSection = WeeklyAudioSection & {
    start: number;
    duration: number;
};

/**
 * Coloca las secciones una tras otra con su duración (las que no la tienen, 0). La posición en el
 * audio completo se reparte en proporción, como hacía el original.
 */
export function timeSections(sections: WeeklyAudioSection[]): TimedSection[] {
    let start = 0;

    return sections.map((section) => {
        const duration = (section.duration_ms ?? 0) / 1000;
        const timed = { ...section, start, duration };
        start += duration;

        return timed;
    });
}

/** Segundo del audio completo donde empieza una sección (en proporción a las duraciones). */
export function sectionStart(
    section: TimedSection,
    total: number,
    mediaDuration: number,
): number {
    return total > 0 ? (section.start / total) * mediaDuration : section.start;
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

/** Para un audio cuando empieza otro de la página. */
function useOnlyOnePlaying(
    playerId: string,
    audio: React.RefObject<HTMLAudioElement | null>,
) {
    useEffect(() => {
        const onOther = (event: Event) => {
            const detail = (event as CustomEvent<{ playerId?: string }>).detail;

            if (detail?.playerId !== playerId) {
                audio.current?.pause();
            }
        };

        window.addEventListener(WEEKLY_AUDIO_PLAY_EVENT, onOther);

        return () =>
            window.removeEventListener(WEEKLY_AUDIO_PLAY_EVENT, onOther);
    }, [playerId, audio]);
}

/** Barra de progreso accesible: flechas ±5 s, Re Pág/Av Pág ±10 %, Inicio y Fin. */
function SeekBar({
    value,
    max,
    label,
    onSeek,
    children,
}: {
    value: number;
    max: number;
    label: string;
    onSeek: (seconds: number) => void;
    children?: React.ReactNode;
}) {
    const track = useRef<HTMLDivElement>(null);
    const dragging = useRef(false);
    const disabled = max <= 0;
    const percent = max > 0 ? clamp((value / max) * 100, 0, 100) : 0;

    const toPointer = (event: PointerEvent<HTMLDivElement>) => {
        const rect = track.current?.getBoundingClientRect();

        if (!rect || rect.width === 0 || max <= 0) {
            return;
        }

        onSeek(clamp((event.clientX - rect.left) / rect.width, 0, 1) * max);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const page = Math.max(5, max / 10);
        const next: Record<string, number> = {
            ArrowRight: value + 5,
            ArrowUp: value + 5,
            ArrowLeft: value - 5,
            ArrowDown: value - 5,
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
        <div className="relative flex min-w-24 flex-1 items-center">
            <div
                ref={track}
                role="slider"
                tabIndex={disabled ? -1 : 0}
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={Math.round(max)}
                aria-valuenow={Math.round(value)}
                aria-valuetext={t('weeklies.audio.position', {
                    current: formatClock(value * 1000),
                    total: formatClock(max * 1000),
                })}
                aria-disabled={disabled || undefined}
                className={cn(
                    'relative flex h-6 w-full cursor-pointer touch-none items-center',
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
                    toPointer(event);
                }}
                onPointerMove={(event) => {
                    if (dragging.current) {
                        toPointer(event);
                    }
                }}
                onPointerUp={(event) => {
                    dragging.current = false;
                    event.currentTarget.releasePointerCapture?.(
                        event.pointerId,
                    );
                }}
                onPointerCancel={() => {
                    dragging.current = false;
                }}
            >
                <span className="absolute inset-x-0 h-1 bg-muted" />
                <span
                    className="absolute left-0 h-1 bg-primary"
                    style={{ width: `${percent}%` }}
                />
                <span
                    className="absolute size-3 -translate-x-1/2 border-2 border-background bg-primary"
                    style={{ left: `${percent}%` }}
                />
            </div>
            {children}
        </div>
    );
}

function SpeedMenu({
    rate,
    rates,
    onChange,
}: {
    rate: number;
    rates: number[];
    onChange: (rate: number) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 shrink-0 gap-1 px-2 text-xs"
                    aria-label={t('weeklies.audio.speed', {
                        rate: formatPlaybackRate(rate),
                    })}
                    data-test="weekly-audio-speed"
                >
                    <Gauge aria-hidden="true" className="size-3.5" />
                    <span className="tabular">{formatPlaybackRate(rate)}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuRadioGroup
                    value={String(rate)}
                    onValueChange={(value) => onChange(Number(value))}
                >
                    {rates.map((option) => (
                        <DropdownMenuRadioItem
                            key={option}
                            value={String(option)}
                        >
                            {formatPlaybackRate(option)}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Estado común de un reproductor: reproducir, pausar, posición, duración y velocidad. */
function usePlayer(playerId: string, onPlayStart?: () => void) {
    const audio = useRef<HTMLAudioElement>(null);
    const [playing, setPlaying] = useState(false);
    const [loading, setLoading] = useState(false);
    const [current, setCurrent] = useState(0);
    const [duration, setDuration] = useState(0);
    const [rate, setRate] = useState(1);

    useOnlyOnePlaying(playerId, audio);

    useEffect(() => {
        if (audio.current) {
            audio.current.playbackRate = rate;
            audio.current.defaultPlaybackRate = rate;
        }
    }, [rate]);

    const play = async () => {
        const element = audio.current;

        if (!element) {
            return;
        }

        dispatchWeeklyAudioPlay(playerId);
        onPlayStart?.();
        setLoading(true);

        try {
            await element.play();
        } catch {
            setPlaying(false);
        } finally {
            setLoading(false);
        }
    };

    const toggle = () => {
        if (playing) {
            audio.current?.pause();
        } else {
            void play();
        }
    };

    const seek = (seconds: number) => {
        if (audio.current) {
            audio.current.currentTime = seconds;
        }

        setCurrent(seconds);
    };

    const events = {
        onPlay: () => setPlaying(true),
        onPause: () => setPlaying(false),
        onEnded: () => setPlaying(false),
        onTimeUpdate: (event: React.SyntheticEvent<HTMLAudioElement>) =>
            setCurrent(event.currentTarget.currentTime),
        onSeeked: (event: React.SyntheticEvent<HTMLAudioElement>) =>
            setCurrent(event.currentTarget.currentTime),
        onLoadedMetadata: (event: React.SyntheticEvent<HTMLAudioElement>) => {
            const value = event.currentTarget.duration;
            setDuration(Number.isFinite(value) ? value : 0);
            event.currentTarget.playbackRate = rate;
        },
        onDurationChange: (event: React.SyntheticEvent<HTMLAudioElement>) => {
            const value = event.currentTarget.duration;

            if (Number.isFinite(value)) {
                setDuration(value);
            }
        },
    };

    return {
        audio,
        playing,
        loading,
        current,
        duration,
        rate,
        setRate,
        play,
        toggle,
        seek,
        events,
    };
}

function PlayButton({
    playing,
    loading,
    onClick,
    label,
}: {
    playing: boolean;
    loading: boolean;
    onClick: () => void;
    label: string;
}) {
    return (
        <Button
            type="button"
            size="icon"
            className="size-8 shrink-0"
            aria-label={
                playing
                    ? t('weeklies.audio.pause_named', { name: label })
                    : t('weeklies.audio.play_named', { name: label })
            }
            onClick={onClick}
            data-test="weekly-audio-play"
        >
            {loading && !playing ? (
                <Loader2 aria-hidden="true" className="animate-spin" />
            ) : playing ? (
                <Pause aria-hidden="true" />
            ) : (
                <Play aria-hidden="true" />
            )}
        </Button>
    );
}

/**
 * Reproductor principal del informe (F-085): el audio completo, con una marca por cliente para ir a
 * su parte (la marca de la parte que suena se resalta), velocidad y volver a empezar.
 */
export function ReportAudioPlayer({
    src,
    playerId,
    sections,
    labels,
}: {
    src: string;
    playerId: string;
    sections: WeeklyAudioSection[];
    /** Nombre del cliente de cada sección (por su clave). */
    labels: Record<string, string>;
}) {
    const player = usePlayer(playerId);
    const timed = timeSections(sections);
    const total = timed.reduce((sum, section) => sum + section.duration, 0);
    const clients = timed.filter(
        (section) => section.kind === 'client' && section.duration > 0,
    );
    const active = clients.find((section) => {
        if (player.duration <= 0) {
            return false;
        }

        const from = sectionStart(section, total, player.duration);
        const to = sectionStart(
            { ...section, start: section.start + section.duration },
            total,
            player.duration,
        );

        return player.current >= from && player.current < to;
    });

    const restart = () => {
        player.seek(0);

        if (!player.playing) {
            void player.play();
        }
    };

    return (
        <div
            role="group"
            aria-label={t('weeklies.audio.main')}
            className="flex flex-wrap items-center gap-2 border bg-card p-2 sm:gap-3"
            data-test="weekly-audio-main"
        >
            <PlayButton
                playing={player.playing}
                loading={player.loading}
                onClick={player.toggle}
                label={t('weeklies.audio.weekly')}
            />
            <SeekBar
                value={player.current}
                max={player.duration}
                label={t('weeklies.audio.position_label')}
                onSeek={player.seek}
            >
                {player.duration > 0
                    ? clients.map((section) => {
                          const left =
                              total > 0 ? (section.start / total) * 100 : 0;
                          const name = labels[section.key] ?? '';

                          return (
                              <button
                                  key={section.key}
                                  type="button"
                                  title={name}
                                  aria-label={t('weeklies.audio.go_to', {
                                      name,
                                  })}
                                  aria-current={
                                      active?.key === section.key
                                          ? 'true'
                                          : undefined
                                  }
                                  className={cn(
                                      'absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 border border-background',
                                      active?.key === section.key
                                          ? 'bg-primary'
                                          : 'bg-muted-foreground',
                                      FOCUS_RING,
                                  )}
                                  style={{ left: `${clamp(left, 0, 100)}%` }}
                                  onClick={() =>
                                      player.seek(
                                          sectionStart(
                                              section,
                                              total,
                                              player.duration,
                                          ),
                                      )
                                  }
                                  data-test="weekly-audio-marker"
                              />
                          );
                      })
                    : null}
            </SeekBar>
            <span
                className="tabular hidden shrink-0 text-xs text-muted-foreground sm:inline"
                aria-hidden="true"
            >
                {formatClock(player.current * 1000)} /{' '}
                {formatClock(player.duration * 1000)}
            </span>
            <SpeedMenu
                rate={player.rate}
                rates={MAIN_RATES}
                onChange={player.setRate}
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8 shrink-0"
                aria-label={t('weeklies.audio.restart')}
                onClick={restart}
                data-test="weekly-audio-restart"
            >
                <RotateCcw aria-hidden="true" />
            </Button>
            <audio
                ref={player.audio}
                src={src}
                preload="metadata"
                className="hidden"
                {...player.events}
            />
        </div>
    );
}

/** El reproductor de la tarjeta de un cliente (F-086): su sección del audio. */
export function InlineAudioPlayer({
    src,
    name,
    playerId,
}: {
    src: string;
    name: string;
    playerId?: string;
}) {
    const fallback = useId();
    const player = usePlayer(playerId ?? fallback);

    return (
        <div
            role="group"
            aria-label={t('weeklies.audio.client', { name })}
            className="flex w-full min-w-0 items-center gap-2 sm:w-72"
            data-test="weekly-audio-inline"
        >
            <PlayButton
                playing={player.playing}
                loading={player.loading}
                onClick={player.toggle}
                label={name}
            />
            <SeekBar
                value={player.current}
                max={player.duration}
                label={t('weeklies.audio.position_label')}
                onSeek={player.seek}
            />
            <span
                className="tabular shrink-0 text-xs text-muted-foreground"
                aria-hidden="true"
            >
                {formatClock(
                    (player.playing || player.current > 0
                        ? player.current
                        : player.duration) * 1000,
                )}
            </span>
            <SpeedMenu
                rate={player.rate}
                rates={INLINE_RATES}
                onChange={player.setRate}
            />
            <audio
                ref={player.audio}
                src={src}
                preload="metadata"
                className="hidden"
                {...player.events}
            />
        </div>
    );
}
