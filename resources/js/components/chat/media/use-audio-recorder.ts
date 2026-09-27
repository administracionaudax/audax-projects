import { useEffect, useRef, useState } from 'react';
import {
    audioFileName,
    MIN_RECORDING_MS,
} from '@/components/chat/media/media-utils';

/**
 * Grabación de audios con MediaRecorder (SPEC §12):
 * - formato según el navegador: webm/opus en Chrome y Firefox, mp4/aac en Safari,
 * - 64 kbit/s (voz clara y archivos pequeños: 5 minutos ≈ 2,4 MB),
 * - nivel del micrófono en vivo con un AnalyserNode (forma de onda),
 * - parada automática al llegar a la duración máxima (queda lista para enviar o descartar),
 * - libera el micrófono al terminar, al cancelar y al desmontar.
 */

export type RecorderSupport = 'supported' | 'unsupported' | 'insecure';

export type RecorderStatus = 'idle' | 'requesting' | 'recording' | 'stopped';

export type RecorderError =
    | 'denied'
    | 'no_device'
    | 'busy'
    | 'failed'
    | 'too_short';

/** Tipos en orden de preferencia (el primero que admita el navegador). */
export const RECORDING_TYPES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/mp4;codecs=mp4a.40.2',
    'audio/mp4',
    'audio/aac',
];

export const AUDIO_BITS_PER_SECOND = 64_000;

/** Barras de la forma de onda. */
export const WAVEFORM_BARS = 32;

const TICK_MS = 100;

const LEVEL_EVERY_MS = 80;

export function recorderSupport(): RecorderSupport {
    if (typeof window === 'undefined' || typeof MediaRecorder === 'undefined') {
        return 'unsupported';
    }

    if (typeof navigator.mediaDevices?.getUserMedia !== 'function') {
        return window.isSecureContext === false ? 'insecure' : 'unsupported';
    }

    return 'supported';
}

/** Primer tipo que admite este navegador, o '' para que elija él. */
export function pickRecordingType(
    isTypeSupported: (type: string) => boolean = (type) =>
        typeof MediaRecorder !== 'undefined' &&
        typeof MediaRecorder.isTypeSupported === 'function' &&
        MediaRecorder.isTypeSupported(type),
): string {
    return RECORDING_TYPES.find((type) => isTypeSupported(type)) ?? '';
}

export function recorderErrorOf(error: unknown): RecorderError {
    const name =
        error !== null && typeof error === 'object' && 'name' in error
            ? String((error as { name: unknown }).name)
            : '';

    switch (name) {
        case 'NotAllowedError':
        case 'PermissionDeniedError':
        case 'SecurityError':
            return 'denied';
        case 'NotFoundError':
        case 'DevicesNotFoundError':
        case 'OverconstrainedError':
            return 'no_device';
        case 'NotReadableError':
        case 'TrackStartError':
        case 'AbortError':
            return 'busy';
        default:
            return 'failed';
    }
}

type Session = {
    stream: MediaStream;
    recorder: MediaRecorder;
    chunks: Blob[];
    startedAt: number;
    stoppedAt: number | null;
    /** Qué hacer al pararse: entregar el audio, guardarlo para revisar o tirarlo. */
    intent: 'deliver' | 'hold' | 'discard';
    context: AudioContext | null;
    analyser: AnalyserNode | null;
    samples: Uint8Array<ArrayBuffer> | null;
    timer: ReturnType<typeof setInterval> | null;
    lastLevelAt: number;
};

export type AudioRecorderState = {
    support: RecorderSupport;
    status: RecorderStatus;
    error: RecorderError | null;
    /** Tiempo grabado. */
    elapsedMs: number;
    /** Nivel reciente del micrófono (0-1), el más nuevo al final. */
    levels: number[];
    /** Se paró sola al llegar al máximo (status «stopped»). */
    reachedMax: boolean;
    start: () => Promise<void>;
    /** Para y entrega el audio (o lo entrega si ya estaba parado al llegar al máximo). */
    finish: () => void;
    /** Para y tira la grabación. */
    cancel: () => void;
    dismissError: () => void;
};

function stopStream(stream: MediaStream): void {
    for (const track of stream.getTracks()) {
        track.stop();
    }
}

/** Suelta el micrófono, el contexto de audio y el temporizador de una grabación. */
function release(current: Session, active: { current: Session | null }): void {
    if (current.timer !== null) {
        clearInterval(current.timer);
        current.timer = null;
    }

    stopStream(current.stream);
    void current.context?.close().catch(() => undefined);
    current.context = null;

    if (active.current === current) {
        active.current = null;
    }
}

/**
 * @param onRecorded recibe el archivo (con nombre y extensión según el tipo) y su duración
 */
export function useAudioRecorder({
    maxSeconds,
    onRecorded,
    now = () => performance.now(),
}: {
    maxSeconds: number;
    onRecorded: (file: File, durationMs: number) => void;
    now?: () => number;
}): AudioRecorderState {
    const [support] = useState<RecorderSupport>(recorderSupport);
    const [status, setStatus] = useState<RecorderStatus>('idle');
    const [error, setError] = useState<RecorderError | null>(null);
    const [elapsedMs, setElapsedMs] = useState(0);
    const [levels, setLevels] = useState<number[]>([]);
    const [reachedMax, setReachedMax] = useState(false);
    const session = useRef<Session | null>(null);
    const held = useRef<{ file: File; durationMs: number } | null>(null);
    const requestId = useRef(0);
    const maxMs = maxSeconds * 1000;
    const deliver = useRef(onRecorded);

    useEffect(() => {
        deliver.current = onRecorded;
    }, [onRecorded]);

    // Al desmontar: se tira lo que se estuviera grabando y se libera el micrófono.
    useEffect(
        () => () => {
            requestId.current += 1;
            const current = session.current;

            if (current) {
                current.intent = 'discard';

                if (current.recorder.state !== 'inactive') {
                    current.recorder.stop();
                }

                release(current, session);
            }
        },
        [],
    );

    const complete = (current: Session) => {
        const durationMs = Math.min(
            maxMs,
            Math.max(0, (current.stoppedAt ?? now()) - current.startedAt),
        );
        const type =
            current.recorder.mimeType ||
            current.chunks[0]?.type ||
            'audio/webm';
        const intent = current.intent;
        release(current, session);
        setLevels([]);

        if (intent === 'discard') {
            setStatus('idle');
            setElapsedMs(0);

            return;
        }

        if (durationMs < MIN_RECORDING_MS) {
            setStatus('idle');
            setElapsedMs(0);
            setError('too_short');

            return;
        }

        const file = new File(current.chunks, audioFileName(type), {
            type: type.split(';')[0],
        });

        if (intent === 'hold') {
            held.current = { file, durationMs };
            setElapsedMs(durationMs);
            setStatus('stopped');

            return;
        }

        setStatus('idle');
        setElapsedMs(0);
        deliver.current(file, durationMs);
    };

    const stop = (intent: Session['intent']) => {
        const current = session.current;

        if (!current) {
            return;
        }

        current.intent = intent;
        current.stoppedAt ??= now();

        if (current.timer !== null) {
            clearInterval(current.timer);
            current.timer = null;
        }

        if (current.recorder.state === 'inactive') {
            complete(current);
        } else {
            current.recorder.stop();
        }
    };

    const tick = (current: Session) => {
        const elapsed = now() - current.startedAt;
        setElapsedMs(Math.min(elapsed, maxMs));

        if (
            current.analyser &&
            current.samples &&
            elapsed - current.lastLevelAt >= LEVEL_EVERY_MS
        ) {
            current.lastLevelAt = elapsed;
            current.analyser.getByteTimeDomainData(current.samples);
            let sum = 0;

            for (const sample of current.samples) {
                const value = (sample - 128) / 128;
                sum += value * value;
            }

            const level = Math.min(
                1,
                Math.sqrt(sum / current.samples.length) * 4,
            );
            setLevels((previous) => [...previous, level].slice(-WAVEFORM_BARS));
        }

        if (elapsed >= maxMs) {
            setReachedMax(true);
            stop('hold');
        }
    };

    const start = async () => {
        if (support !== 'supported' || session.current !== null) {
            return;
        }

        const request = ++requestId.current;
        held.current = null;
        setError(null);
        setReachedMax(false);
        setElapsedMs(0);
        setLevels([]);
        setStatus('requesting');

        let stream: MediaStream;

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                    channelCount: 1,
                },
            });
        } catch (caught) {
            if (request === requestId.current) {
                setStatus('idle');
                setError(recorderErrorOf(caught));
            }

            return;
        }

        // Se canceló (o se desmontó) mientras el navegador pedía permiso.
        if (request !== requestId.current) {
            stopStream(stream);

            return;
        }

        let recorder: MediaRecorder;

        try {
            const mimeType = pickRecordingType();
            recorder = new MediaRecorder(stream, {
                ...(mimeType ? { mimeType } : {}),
                audioBitsPerSecond: AUDIO_BITS_PER_SECOND,
            });
        } catch (caught) {
            stopStream(stream);
            setStatus('idle');
            setError(recorderErrorOf(caught));

            return;
        }

        const current: Session = {
            stream,
            recorder,
            chunks: [],
            startedAt: now(),
            stoppedAt: null,
            intent: 'deliver',
            context: null,
            analyser: null,
            samples: null,
            timer: null,
            lastLevelAt: -LEVEL_EVERY_MS,
        };

        // La forma de onda es un extra: sin Web Audio se graba igual.
        try {
            const Context =
                window.AudioContext ??
                (
                    window as unknown as {
                        webkitAudioContext?: typeof AudioContext;
                    }
                ).webkitAudioContext;

            if (Context) {
                current.context = new Context();
                // Creado tras el permiso (fuera del clic): algunos navegadores lo dejan suspendido.
                void current.context.resume().catch(() => undefined);
                current.analyser = current.context.createAnalyser();
                current.analyser.fftSize = 1024;
                current.samples = new Uint8Array(current.analyser.fftSize);
                current.context
                    .createMediaStreamSource(stream)
                    .connect(current.analyser);
            }
        } catch {
            current.context = null;
            current.analyser = null;
        }

        recorder.addEventListener('dataavailable', (event: BlobEvent) => {
            if (event.data.size > 0) {
                current.chunks.push(event.data);
            }
        });
        recorder.addEventListener('stop', () => complete(current));
        recorder.addEventListener('error', () => {
            current.intent = 'discard';
            release(current, session);
            setStatus('idle');
            setLevels([]);
            setError('failed');
        });

        session.current = current;
        recorder.start(250);
        current.startedAt = now();
        current.timer = setInterval(() => tick(current), TICK_MS);
        setStatus('recording');
    };

    return {
        support,
        status,
        error,
        elapsedMs,
        levels,
        reachedMax,
        start,
        finish: () => {
            if (status === 'stopped' && held.current) {
                const { file, durationMs } = held.current;
                held.current = null;
                setStatus('idle');
                setElapsedMs(0);
                setReachedMax(false);
                deliver.current(file, durationMs);

                return;
            }

            stop('deliver');
        },
        cancel: () => {
            if (status === 'requesting') {
                requestId.current += 1;
                setStatus('idle');

                return;
            }

            if (status === 'stopped') {
                held.current = null;
                setStatus('idle');
                setElapsedMs(0);
                setReachedMax(false);

                return;
            }

            stop('discard');
        },
        dismissError: () => setError(null),
    };
}
