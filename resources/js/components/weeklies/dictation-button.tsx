import {
    CircleAlert,
    Info,
    Loader2,
    Mic,
    MicOff,
    Square,
    X,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { LiveWaveform } from '@/components/chat/media/audio-recorder';
import {
    formatClock,
    useMediaLimits,
} from '@/components/chat/media/media-utils';
import type { RecorderError } from '@/components/chat/media/use-audio-recorder';
import { useAudioRecorder } from '@/components/chat/media/use-audio-recorder';
import { Button } from '@/components/ui/button';
import type { DictationNotice } from '@/components/weeklies/use-dictation';
import { useDictation } from '@/components/weeklies/use-dictation';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const RECORDER_ERRORS: Record<RecorderError, TranslationKey> = {
    denied: 'chat_media.recorder.error.denied',
    no_device: 'chat_media.recorder.error.no_device',
    busy: 'chat_media.recorder.error.busy',
    failed: 'chat_media.recorder.error.failed',
    too_short: 'weeklies.dictation.notice.too_short',
};

const NOTICES: Record<DictationNotice, TranslationKey> = {
    no_speech: 'weeklies.dictation.notice.no_speech',
    too_short: 'weeklies.dictation.notice.too_short',
    failed: 'weeklies.dictation.notice.failed',
    upload: 'weeklies.dictation.notice.upload',
    timeout: 'weeklies.dictation.notice.timeout',
};

/**
 * Dictar un apunte (F-049): graba en el navegador con la grabadora del chat (MediaRecorder,
 * forma de onda y límite de duración), lo sube y muestra «Transcribiendo…» hasta que el Whisper del
 * servidor devuelve el texto, que se añade al apunte con `onText`. Si no se ha oído nada (F-050 y
 * F-171) lo dice sin tocar el texto. Escape cancela la grabación.
 */
export function DictationButton({
    cycleId,
    clientId,
    clientName,
    onText,
    onBusyChange,
    disabled = false,
}: {
    cycleId: number;
    clientId: number | null;
    /** Para el nombre accesible: «Dictar el apunte de Ferretería Ruiz». */
    clientName: string;
    onText: (text: string) => void;
    onBusyChange?: (busy: boolean) => void;
    disabled?: boolean;
}) {
    const limits = useMediaLimits();
    const dictation = useDictation({ cycleId, clientId, onText });
    const recorder = useAudioRecorder({
        maxSeconds: limits.maxAudioSeconds,
        onRecorded: (file, durationMs) => void dictation.send(file, durationMs),
    });
    const startButton = useRef<HTMLButtonElement>(null);
    const stopButton = useRef<HTMLButtonElement>(null);
    const recording = recorder.status !== 'idle';
    const working = dictation.phase !== 'idle';
    const busy = recording || working;
    const busyChange = useRef(onBusyChange);

    useEffect(() => {
        busyChange.current = onBusyChange;
    });

    useEffect(() => {
        busyChange.current?.(busy);
    }, [busy]);

    useEffect(() => {
        if (recorder.status === 'recording' || recorder.status === 'stopped') {
            stopButton.current?.focus();
        }
    }, [recorder.status]);

    if (recorder.support !== 'supported') {
        return (
            <p
                className="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                data-test="dictation-unsupported"
            >
                <MicOff aria-hidden="true" className="size-4 shrink-0" />
                {t(
                    recorder.support === 'insecure'
                        ? 'chat_media.recorder.insecure'
                        : 'chat_media.recorder.unsupported',
                )}
            </p>
        );
    }

    const error = recorder.error ? t(RECORDER_ERRORS[recorder.error]) : null;
    const notice = dictation.notice
        ? (dictation.notice === 'upload' && dictation.message) ||
          t(NOTICES[dictation.notice])
        : null;
    const isWarning =
        dictation.notice === 'no_speech' || dictation.notice === 'too_short';

    return (
        <div className="grid min-w-0 gap-1.5" data-test="dictation">
            <p className="sr-only" role="status" aria-live="polite">
                {recorder.status === 'recording'
                    ? t('chat_media.recorder.recording_started')
                    : working
                      ? t('weeklies.dictation.transcribing')
                      : ''}
            </p>

            {recording ? (
                <div
                    role="group"
                    aria-label={t('weeklies.dictation.group', {
                        client: clientName,
                    })}
                    className="flex min-w-0 flex-wrap items-center gap-2 border bg-card px-2 py-1"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            recorder.cancel();
                            startButton.current?.focus();
                        }
                    }}
                >
                    {recorder.status === 'requesting' ? (
                        <span className="inline-flex items-center gap-2 text-sm text-muted-foreground">
                            <Loader2
                                aria-hidden="true"
                                className="size-4 animate-spin"
                            />
                            {t('chat_media.recorder.requesting')}
                        </span>
                    ) : (
                        <>
                            <span
                                aria-hidden="true"
                                className="size-2.5 animate-pulse rounded-full bg-danger"
                            />
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
                                {formatClock(limits.maxAudioSeconds * 1000)}
                            </span>
                        </>
                    )}
                    <div className="ml-auto flex items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                recorder.cancel();
                                startButton.current?.focus();
                            }}
                        >
                            <X aria-hidden="true" />
                            {t('chat_media.recorder.cancel')}
                        </Button>
                        {recorder.status !== 'requesting' ? (
                            <Button
                                ref={stopButton}
                                type="button"
                                size="sm"
                                onClick={recorder.finish}
                                data-test="dictation-stop"
                            >
                                <Square
                                    aria-hidden="true"
                                    className="fill-current"
                                />
                                {t('weeklies.dictation.stop')}
                            </Button>
                        ) : null}
                    </div>
                </div>
            ) : working ? (
                <p
                    className="inline-flex items-center gap-2 text-sm text-primary-text"
                    data-test="dictation-transcribing"
                >
                    <Loader2
                        aria-hidden="true"
                        className="size-4 animate-spin"
                    />
                    {t(
                        dictation.phase === 'uploading'
                            ? 'weeklies.dictation.uploading'
                            : 'weeklies.dictation.transcribing',
                    )}
                </p>
            ) : (
                <Button
                    ref={startButton}
                    type="button"
                    variant="outline"
                    size="sm"
                    className="justify-self-start"
                    disabled={disabled}
                    aria-label={t('weeklies.dictation.start_for', {
                        client: clientName,
                    })}
                    title={t('chat_media.recorder.start_hint', {
                        max: formatClock(limits.maxAudioSeconds * 1000),
                    })}
                    onClick={() => void recorder.start()}
                    data-test="dictation-start"
                >
                    <Mic aria-hidden="true" />
                    {t('weeklies.dictation.start')}
                </Button>
            )}

            {error || notice ? (
                <p
                    role={isWarning && !error ? 'status' : 'alert'}
                    className="flex items-start gap-1.5 text-xs text-foreground"
                    data-test="dictation-notice"
                >
                    {isWarning && !error ? (
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0 text-info"
                        />
                    ) : (
                        <CircleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0 text-danger"
                        />
                    )}
                    <span className="min-w-0 flex-1">{error ?? notice}</span>
                    <button
                        type="button"
                        className={cn(
                            'text-primary-text underline',
                            FOCUS_RING,
                        )}
                        onClick={() => {
                            recorder.dismissError();
                            dictation.dismiss();
                        }}
                    >
                        {t('chat_media.recorder.dismiss')}
                    </button>
                </p>
            ) : null}
        </div>
    );
}
