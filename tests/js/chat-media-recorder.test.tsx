// @vitest-environment jsdom
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    props: { config: { max_audio_seconds: 5 } } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

import { AudioRecorder } from '@/components/chat/media/audio-recorder';
import {
    pickRecordingType,
    recorderErrorOf,
} from '@/components/chat/media/use-audio-recorder';

/** MediaRecorder falso: al parar entrega un trozo de audio y dispara «stop». */
class FakeRecorder extends EventTarget {
    static supported = (type: string) => type.startsWith('audio/webm');

    static isTypeSupported(type: string): boolean {
        return FakeRecorder.supported(type);
    }

    static last: FakeRecorder | null = null;

    state: 'inactive' | 'recording' = 'inactive';

    mimeType: string;

    options: { mimeType?: string; audioBitsPerSecond?: number };

    constructor(
        public stream: MediaStream,
        options: { mimeType?: string; audioBitsPerSecond?: number } = {},
    ) {
        super();
        this.options = options;
        this.mimeType = options.mimeType ?? 'audio/webm';
        FakeRecorder.last = this;
    }

    start(): void {
        this.state = 'recording';
    }

    stop(): void {
        this.state = 'inactive';
        const data = Object.assign(new Event('dataavailable'), {
            data: new Blob(['voz'], { type: this.mimeType }),
        });
        this.dispatchEvent(data);
        this.dispatchEvent(new Event('stop'));
    }
}

const track = { stop: vi.fn() };
const stream = { getTracks: () => [track] } as unknown as MediaStream;
const getUserMedia = vi.fn<(constraints: unknown) => Promise<MediaStream>>();

function install({ mediaRecorder = true, devices = true, secure = true } = {}) {
    Object.defineProperty(window, 'MediaRecorder', {
        configurable: true,
        writable: true,
        value: mediaRecorder ? FakeRecorder : undefined,
    });
    Object.defineProperty(globalThis, 'MediaRecorder', {
        configurable: true,
        writable: true,
        value: mediaRecorder ? FakeRecorder : undefined,
    });
    Object.defineProperty(navigator, 'mediaDevices', {
        configurable: true,
        value: devices ? { getUserMedia } : undefined,
    });
    Object.defineProperty(window, 'isSecureContext', {
        configurable: true,
        value: secure,
    });
}

async function startRecording() {
    await act(async () => {
        fireEvent.click(
            screen.getByRole('button', { name: 'Grabar un audio' }),
        );
    });
}

describe('AudioRecorder', () => {
    beforeEach(() => {
        vi.useFakeTimers({
            toFake: [
                'setInterval',
                'clearInterval',
                'setTimeout',
                'clearTimeout',
                'performance',
                'Date',
            ],
        });
        page.props = { config: { max_audio_seconds: 5 } };
        track.stop.mockClear();
        getUserMedia.mockReset();
        getUserMedia.mockResolvedValue(stream);
        FakeRecorder.supported = (type) => type.startsWith('audio/webm');
        FakeRecorder.last = null;
        install();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('sin MediaRecorder no hay botón y se explica por qué', () => {
        install({ mediaRecorder: false });
        render(<AudioRecorder onRecorded={vi.fn()} />);

        expect(
            screen.queryByRole('button', { name: 'Grabar un audio' }),
        ).toBeNull();
        expect(
            screen.getByText(/Este navegador no permite grabar audios/),
        ).toBeTruthy();
    });

    it('fuera de HTTPS explica que hace falta una conexión segura', () => {
        install({ devices: false, secure: false });
        render(<AudioRecorder onRecorded={vi.fn()} />);

        expect(
            screen.queryByRole('button', { name: 'Grabar un audio' }),
        ).toBeNull();
        expect(screen.getByText(/conexión segura/)).toBeTruthy();
    });

    it('graba con el contador y la forma de onda, y al enviar entrega el archivo y su duración', async () => {
        const onRecorded = vi.fn();
        render(<AudioRecorder onRecorded={onRecorded} />);

        await startRecording();

        expect(getUserMedia).toHaveBeenCalledWith({
            audio: expect.objectContaining({ echoCancellation: true }),
        });
        expect(FakeRecorder.last?.options).toEqual({
            mimeType: 'audio/webm;codecs=opus',
            audioBitsPerSecond: 64_000,
        });
        expect(
            screen.getByRole('group', { name: 'Grabación de audio' }),
        ).toBeTruthy();
        expect(
            document.querySelector('[data-test="chat-recorder-waveform"]'),
        ).not.toBeNull();

        const send = screen.getByRole('button', { name: 'Enviar audio' });
        expect(document.activeElement).toBe(send);

        act(() => {
            vi.advanceTimersByTime(2_300);
        });
        expect(screen.getByRole('timer').textContent).toBe('0:02 / 0:05');

        act(() => {
            fireEvent.click(send);
        });

        expect(onRecorded).toHaveBeenCalledTimes(1);
        const [file, durationMs] = onRecorded.mock.calls[0] as [File, number];
        expect(file.name).toMatch(/^audio-\d{8}-\d{6}\.webm$/);
        expect(file.type).toBe('audio/webm');
        expect(durationMs).toBeGreaterThanOrEqual(2_300);
        expect(durationMs).toBeLessThan(2_500);
        expect(track.stop).toHaveBeenCalled();
        expect(document.activeElement).toBe(
            screen.getByRole('button', { name: 'Grabar un audio' }),
        );
    });

    it('cancelar tira la grabación y suelta el micrófono', async () => {
        const onRecorded = vi.fn();
        render(<AudioRecorder onRecorded={onRecorded} />);
        await startRecording();

        act(() => {
            vi.advanceTimersByTime(1_500);
            fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));
        });

        expect(onRecorded).not.toHaveBeenCalled();
        expect(track.stop).toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Grabar un audio' }),
        ).toBeTruthy();
    });

    it('Escape también cancela', async () => {
        const onRecorded = vi.fn();
        render(<AudioRecorder onRecorded={onRecorded} />);
        await startRecording();

        act(() => {
            vi.advanceTimersByTime(1_500);
            fireEvent.keyDown(
                screen.getByRole('button', { name: 'Enviar audio' }),
                {
                    key: 'Escape',
                },
            );
        });

        expect(onRecorded).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Grabar un audio' }),
        ).toBeTruthy();
    });

    it('se para sola al llegar al máximo y queda lista para enviar o descartar', async () => {
        const onRecorded = vi.fn();
        render(<AudioRecorder onRecorded={onRecorded} />);
        await startRecording();

        act(() => {
            vi.advanceTimersByTime(6_000);
        });

        expect(FakeRecorder.last?.state).toBe('inactive');
        expect(track.stop).toHaveBeenCalled();
        expect(onRecorded).not.toHaveBeenCalled();
        expect(
            screen.getAllByText(/Has llegado a la duración máxima \(0:05\)/)
                .length,
        ).toBeGreaterThan(0);

        act(() => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Enviar audio' }),
            );
        });

        expect(onRecorded).toHaveBeenCalledTimes(1);
        expect(onRecorded.mock.calls[0][1]).toBe(5_000);
    });

    it('al llegar al máximo también se puede descartar', async () => {
        const onRecorded = vi.fn();
        render(<AudioRecorder onRecorded={onRecorded} maxSeconds={3} />);
        await startRecording();

        act(() => {
            vi.advanceTimersByTime(4_000);
        });
        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
        });

        expect(onRecorded).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Grabar un audio' }),
        ).toBeTruthy();
    });

    it('un audio de menos de un segundo no se envía y se explica', async () => {
        const onRecorded = vi.fn();
        render(<AudioRecorder onRecorded={onRecorded} />);
        await startRecording();

        act(() => {
            vi.advanceTimersByTime(400);
            fireEvent.click(
                screen.getByRole('button', { name: 'Enviar audio' }),
            );
        });

        expect(onRecorded).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toContain(
            'demasiado corto',
        );
    });

    it('si se niega el micrófono lo explica y deja volver a intentarlo', async () => {
        getUserMedia.mockRejectedValue(
            Object.assign(new Error('denied'), { name: 'NotAllowedError' }),
        );
        render(<AudioRecorder onRecorded={vi.fn()} />);

        await startRecording();

        expect(screen.getByRole('alert').textContent).toContain(
            'Permite el acceso al micrófono',
        );
        expect(
            screen.getByRole('button', { name: 'Grabar un audio' }),
        ).toBeTruthy();
    });

    it('elige el formato que admite el navegador (Safari graba en mp4)', () => {
        expect(pickRecordingType((type) => type.startsWith('audio/webm'))).toBe(
            'audio/webm;codecs=opus',
        );
        expect(pickRecordingType((type) => type.startsWith('audio/mp4'))).toBe(
            'audio/mp4;codecs=mp4a.40.2',
        );
        expect(pickRecordingType(() => false)).toBe('');
    });

    it('traduce los errores del micrófono', () => {
        expect(recorderErrorOf({ name: 'NotAllowedError' })).toBe('denied');
        expect(recorderErrorOf({ name: 'NotFoundError' })).toBe('no_device');
        expect(recorderErrorOf({ name: 'NotReadableError' })).toBe('busy');
        expect(recorderErrorOf(new Error('x'))).toBe('failed');
    });

    it('con Safari el archivo es un m4a', async () => {
        FakeRecorder.supported = (type) => type.startsWith('audio/mp4');
        const onRecorded = vi.fn();
        render(<AudioRecorder onRecorded={onRecorded} />);
        await startRecording();

        act(() => {
            vi.advanceTimersByTime(1_200);
            fireEvent.click(
                screen.getByRole('button', { name: 'Enviar audio' }),
            );
        });

        const [file] = onRecorded.mock.calls[0] as [File, number];
        expect(file.name).toMatch(/\.m4a$/);
        expect(file.type).toBe('audio/mp4');
    });
});
