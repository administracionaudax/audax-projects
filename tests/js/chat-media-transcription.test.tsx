// @vitest-environment jsdom
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const realtime = vi.hoisted(() => ({ enabled: false }));
const channel = vi.hoisted(() => ({
    handlers: new Map<string, (payload: unknown) => void>(),
    name: '',
}));

vi.mock('@/lib/realtime', () => ({
    realtimeEnabled: () => realtime.enabled,
}));

vi.mock('@laravel/echo-react', () => ({
    echoIsConfigured: () => realtime.enabled,
    echo: () => ({
        private: (name: string) => {
            channel.name = name;

            return {
                listen: (event: string, handler: (payload: unknown) => void) =>
                    channel.handlers.set(event, handler),
                stopListening: (event: string) =>
                    channel.handlers.delete(event),
            };
        },
    }),
}));

import {
    AudioMessage,
    AudioTranscription,
} from '@/components/chat/media/audio-transcription';
import type {
    AudioMessageData,
    ChatTranscription,
} from '@/components/chat/media/types';
import {
    AUDIO_TRANSCRIBED_EVENT,
    POLL_MS,
    POLL_MS_WITH_REALTIME,
    transcriptionFromEvent,
} from '@/components/chat/media/use-live-transcription';

function transcription(
    overrides: Partial<ChatTranscription> = {},
): ChatTranscription {
    return {
        id: 7,
        status: 'done',
        text: 'Mañana entregamos el presupuesto',
        language: 'es',
        ...overrides,
    };
}

function message(overrides: Partial<AudioMessageData> = {}): AudioMessageData {
    return {
        id: 41,
        conversation_id: 3,
        audio: {
            attachment_id: 9,
            url: '/chat/audios/9?signature=a',
            mime: 'audio/webm',
            size: 2048,
            duration_ms: 4_000,
            original_name: 'audio.webm',
        },
        transcription: transcription({ status: 'pending', text: null }),
        ...overrides,
    };
}

function pollResponse(
    status: ChatTranscription['status'],
    text: string | null,
) {
    return new Response(
        JSON.stringify({
            messages: [
                {
                    id: 41,
                    audio: message().audio,
                    transcription: transcription({ status, text }),
                },
            ],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
    );
}

describe('AudioTranscription', () => {
    it.each([
        ['pending', 'Transcribiendo…'],
        ['processing', 'Transcribiendo…'],
        ['failed', 'Transcripción pendiente'],
    ] as const)('en estado %s dice «%s» con un icono', (status, label) => {
        render(
            <AudioTranscription
                transcription={transcription({ status, text: null })}
            />,
        );

        const element = screen.getByRole('status');
        expect(element.textContent).toContain(label);
        expect(element.querySelector('svg')).not.toBeNull();
    });

    it('sin transcripción todavía, también «Transcribiendo…»', () => {
        render(<AudioTranscription transcription={null} />);

        expect(screen.getByRole('status').textContent).toBe('Transcribiendo…');
    });

    it('si el audio no tiene voz, dice «Sin voz»', () => {
        render(
            <AudioTranscription transcription={transcription({ text: '' })} />,
        );

        expect(screen.getByText('Sin voz')).toBeTruthy();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('la transcripción se pliega y se despliega, con una línea de vista previa', () => {
        render(<AudioTranscription transcription={transcription()} />);

        const toggle = screen.getByRole('button', {
            name: /Ver la transcripción/,
        });
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(toggle.textContent).toContain(
            'Mañana entregamos el presupuesto',
        );

        fireEvent.click(toggle);

        expect(
            screen
                .getByRole('button', { name: /Ocultar la transcripción/ })
                .getAttribute('aria-expanded'),
        ).toBe('true');
        expect(
            screen.getByText('Mañana entregamos el presupuesto').tagName,
        ).toBe('P');
    });

    it('«Copiar» copia el texto y lo confirma', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });
        render(
            <AudioTranscription transcription={transcription()} defaultOpen />,
        );

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Copiar la transcripción' }),
            );
        });

        expect(writeText).toHaveBeenCalledWith(
            'Mañana entregamos el presupuesto',
        );
        expect(
            screen.getByRole('button', { name: 'Transcripción copiada' })
                .textContent,
        ).toContain('Copiada');
    });
});

describe('AudioMessage', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        realtime.enabled = false;
        channel.handlers.clear();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('sin tiempo real consulta cada 15 s y cambia «Transcribiendo…» por el texto', async () => {
        const fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(pollResponse('processing', null))
            .mockResolvedValueOnce(
                pollResponse('done', 'Nos vemos en la obra'),
            );
        render(<AudioMessage message={message()} />);

        expect(screen.getByRole('status').textContent).toBe('Transcribiendo…');

        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/chat/transcripciones?mensajes=41',
        );
        expect(screen.getByRole('status').textContent).toBe('Transcribiendo…');

        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS);
        });
        expect(
            screen.getByRole('button', { name: /Ver la transcripción/ }),
        ).toBeTruthy();

        // Hecha: ya no se consulta más.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS * 3);
        });
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('varios audios pendientes comparten una sola consulta', async () => {
        const fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValue(new Response(JSON.stringify({ messages: [] })));
        render(
            <>
                <AudioMessage message={message({ id: 41 })} />
                <AudioMessage message={message({ id: 42 })} />
            </>,
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS);
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/chat/transcripciones?mensajes=41%2C42',
        );
    });

    it('con tiempo real, el evento AudioTranscribed trae el texto sin esperar', async () => {
        realtime.enabled = true;
        const fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValue(new Response(JSON.stringify({ messages: [] })));
        render(<AudioMessage message={message()} />);

        expect(channel.name).toBe('conversation.3');
        expect(channel.handlers.has(AUDIO_TRANSCRIBED_EVENT)).toBe(true);

        act(() => {
            channel.handlers.get(AUDIO_TRANSCRIBED_EVENT)?.({
                message_id: 41,
                transcription: transcription({ text: 'Llego tarde' }),
            });
        });

        expect(
            screen.getByRole('button', { name: /Ver la transcripción/ })
                .textContent,
        ).toContain('Llego tarde');
        // Ya hecha: deja de escuchar y no consulta.
        expect(channel.handlers.has(AUDIO_TRANSCRIBED_EVENT)).toBe(false);
        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS_WITH_REALTIME);
        });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('con tiempo real, si se pierde el evento, consulta cada minuto', async () => {
        realtime.enabled = true;
        const fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValue(pollResponse('done', 'Hola'));
        render(<AudioMessage message={message()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS);
        });
        expect(fetchMock).not.toHaveBeenCalled();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS_WITH_REALTIME - POLL_MS);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('deja de consultar un audio que la consulta ya no devuelve (borrado u oculto)', async () => {
        const fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValue(new Response(JSON.stringify({ messages: [] })));
        render(<AudioMessage message={message()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS);
        });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS * 3);
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('un audio ya transcrito no consulta nada', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch');
        render(
            <AudioMessage
                message={message({ transcription: transcription() })}
            />,
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS * 2);
        });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.getByRole('slider')).toBeTruthy();
    });

    it('un mensaje sin audio lo dice', () => {
        render(<AudioMessage message={message({ audio: null })} />);

        expect(
            screen.getByText('El audio ya no está disponible.'),
        ).toBeTruthy();
    });

    it('solo acepta eventos de su mensaje', () => {
        expect(
            transcriptionFromEvent(
                { message_id: 9, transcription: transcription() },
                41,
            ),
        ).toBeNull();
        expect(transcriptionFromEvent(null, 41)).toBeNull();
        expect(
            transcriptionFromEvent(
                { transcription: { ...transcription(), message_id: 41 } },
                41,
            )?.transcription.text,
        ).toBe('Mañana entregamos el presupuesto');
    });
});
