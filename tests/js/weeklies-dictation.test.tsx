// @vitest-environment jsdom
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    props: {
        config: { max_audio_seconds: 60 },
        auth: { user: { id: 7 } },
        realtime: null,
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

import { DictationButton } from '@/components/weeklies/dictation-button';
import { DICTATION_POLL_MS } from '@/components/weeklies/use-dictation';

/** MediaRecorder falso: al parar entrega un trozo de audio y dispara «stop». */
class FakeRecorder extends EventTarget {
    static isTypeSupported(type: string): boolean {
        return type.startsWith('audio/webm');
    }

    state: 'inactive' | 'recording' = 'inactive';

    mimeType: string;

    constructor(
        public stream: MediaStream,
        options: { mimeType?: string } = {},
    ) {
        super();
        this.mimeType = options.mimeType ?? 'audio/webm';
    }

    start(): void {
        this.state = 'recording';
    }

    stop(): void {
        this.state = 'inactive';
        this.dispatchEvent(
            Object.assign(new Event('dataavailable'), {
                data: new Blob(['voz de prueba'], { type: this.mimeType }),
            }),
        );
        this.dispatchEvent(new Event('stop'));
    }
}

const track = { stop: vi.fn() };
const stream = { getTracks: () => [track] } as unknown as MediaStream;
const fetchMock = vi.fn<typeof fetch>();

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function dictation(overrides: Record<string, unknown>) {
    return {
        id: 5,
        context: 'weekly_entry',
        status: 'pending',
        client_id: 3,
        task_id: null,
        text: null,
        warning: null,
        created_at: '2026-10-05T08:00:00Z',
        ...overrides,
    };
}

/** Graba 2 s y pulsa «Parar y transcribir»; deja que la subida termine. */
async function recordAndSend() {
    await act(async () => {
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Dictar el apunte de Ferretería Ruiz',
            }),
        );
    });

    act(() => {
        vi.advanceTimersByTime(2_000);
    });

    await act(async () => {
        fireEvent.click(
            screen.getByRole('button', { name: 'Parar y transcribir' }),
        );
    });
}

describe('DictationButton (dictado de la weekly, F-049)', () => {
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
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        for (const target of [window, globalThis]) {
            Object.defineProperty(target, 'MediaRecorder', {
                configurable: true,
                writable: true,
                value: FakeRecorder,
            });
        }
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: { getUserMedia: vi.fn().mockResolvedValue(stream) },
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('graba, sube el audio, muestra «Transcribiendo…» y añade el texto cuando Whisper acaba', async () => {
        const onText = vi.fn();
        fetchMock.mockResolvedValueOnce(
            json({ dictation: dictation({ status: 'pending' }) }, 201),
        );
        render(
            <DictationButton
                cycleId={12}
                clientId={3}
                clientName="Ferretería Ruiz"
                onText={onText}
            />,
        );

        await recordAndSend();

        // La subida: multipart con la semana, el cliente, el audio y la duración.
        const [url, init] = fetchMock.mock.calls[0];
        expect(String(url)).toBe('/mi-espacio/dictados');
        expect(init?.method).toBe('POST');
        const body = init?.body as FormData;
        expect(body.get('context')).toBe('weekly_entry');
        expect(body.get('weekly_cycle_id')).toBe('12');
        expect(body.get('client_id')).toBe('3');
        expect(body.get('audio')).toBeInstanceOf(File);
        expect(Number(body.get('duration_ms'))).toBeGreaterThanOrEqual(2_000);

        expect(
            document.querySelector('[data-test="dictation-transcribing"]')
                ?.textContent,
        ).toBe('Transcribiendo…');
        expect(onText).not.toHaveBeenCalled();

        // Sin tiempo real, se pregunta cada 3 s: primero sigue en proceso, luego está hecho.
        fetchMock.mockResolvedValueOnce(
            json({ dictation: dictation({ status: 'processing' }) }),
        );
        await act(async () => {
            vi.advanceTimersByTime(DICTATION_POLL_MS);
        });
        expect(onText).not.toHaveBeenCalled();

        fetchMock.mockResolvedValueOnce(
            json({
                dictation: dictation({
                    status: 'done',
                    text: 'Revisada la home con el cliente.',
                }),
            }),
        );
        await act(async () => {
            vi.advanceTimersByTime(DICTATION_POLL_MS);
        });

        expect(String(fetchMock.mock.calls[2][0])).toBe(
            '/mi-espacio/dictados/5',
        );
        expect(onText).toHaveBeenCalledWith('Revisada la home con el cliente.');
        expect(
            document.querySelector('[data-test="dictation-transcribing"]'),
        ).toBeNull();
        expect(
            screen.getByRole('button', {
                name: 'Dictar el apunte de Ferretería Ruiz',
            }),
        ).toBeTruthy();
    });

    it('si no se ha oído nada, lo dice y no toca el texto', async () => {
        const onText = vi.fn();
        fetchMock.mockResolvedValueOnce(
            json({ dictation: dictation({ status: 'pending' }) }, 201),
        );
        render(
            <DictationButton
                cycleId={12}
                clientId={3}
                clientName="Ferretería Ruiz"
                onText={onText}
            />,
        );

        await recordAndSend();
        fetchMock.mockResolvedValueOnce(
            json({
                dictation: dictation({
                    status: 'done',
                    text: '',
                    warning: 'no_speech',
                }),
            }),
        );
        await act(async () => {
            vi.advanceTimersByTime(DICTATION_POLL_MS);
        });

        expect(onText).not.toHaveBeenCalled();
        expect(
            screen.getByText(/No se ha oído nada claro en el audio/),
        ).toBeTruthy();
    });

    it('un audio demasiado corto vuelve hecho al momento y avisa (F-050)', async () => {
        const onText = vi.fn();
        fetchMock.mockResolvedValueOnce(
            json(
                {
                    dictation: dictation({
                        status: 'done',
                        text: '',
                        warning: 'too_short',
                    }),
                },
                201,
            ),
        );
        render(
            <DictationButton
                cycleId={12}
                clientId={null}
                clientName="General / Interno"
                onText={onText}
            />,
        );

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Dictar el apunte de General / Interno',
                }),
            );
        });
        act(() => {
            vi.advanceTimersByTime(1_200);
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Parar y transcribir' }),
            );
        });

        expect(
            (fetchMock.mock.calls[0][1]?.body as FormData).has('client_id'),
        ).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(onText).not.toHaveBeenCalled();
        expect(screen.getByText(/demasiado corto/)).toBeTruthy();
    });

    it('si la subida falla, muestra el mensaje del servidor', async () => {
        fetchMock.mockResolvedValueOnce(
            json(
                {
                    message: 'La semana está cerrada.',
                    errors: { weekly_cycle_id: ['La semana está cerrada.'] },
                },
                422,
            ),
        );
        render(
            <DictationButton
                cycleId={12}
                clientId={3}
                clientName="Ferretería Ruiz"
                onText={vi.fn()}
            />,
        );

        await recordAndSend();

        expect(screen.getByRole('alert').textContent).toContain(
            'La semana está cerrada.',
        );
    });

    it('avisa de que está ocupado mientras graba y transcribe', async () => {
        const onBusyChange = vi.fn();
        fetchMock.mockResolvedValueOnce(
            json({ dictation: dictation({ status: 'pending' }) }, 201),
        );
        render(
            <DictationButton
                cycleId={12}
                clientId={3}
                clientName="Ferretería Ruiz"
                onText={vi.fn()}
                onBusyChange={onBusyChange}
            />,
        );

        await recordAndSend();
        expect(onBusyChange).toHaveBeenLastCalledWith(true);

        fetchMock.mockResolvedValueOnce(
            json({ dictation: dictation({ status: 'failed' }) }),
        );
        await act(async () => {
            vi.advanceTimersByTime(DICTATION_POLL_MS);
        });

        expect(onBusyChange).toHaveBeenLastCalledWith(false);
        expect(screen.getByRole('alert').textContent).toContain(
            'No se ha podido transcribir',
        );
    });
});
