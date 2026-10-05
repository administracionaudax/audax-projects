// @vitest-environment jsdom
import { act, configure, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

configure({ testIdAttribute: 'data-test' });

const page = vi.hoisted(() => ({
    url: '/ia',
    props: {
        auth: {
            user: { id: 7, name: 'Ana Díaz', roles: ['employee'] },
            can: { useWeeklies: true },
        },
        config: { modules: { assistant: true } },
        realtime: null,
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...original, Head: () => null, usePage: () => page };
});

import { mainNavItems } from '@/components/app-sidebar';
import {
    ASSISTANT_POLL_MS,
    historyFor,
    storageKey,
} from '@/components/assistant/use-assistant';
import type { AssistantMessage } from '@/components/assistant/use-assistant';
import Assistant from '@/pages/assistant/index';
import type { Abilities } from '@/types';
import type { AssistantPageProps } from '@/types/weeklies';

const props: AssistantPageProps = {
    suggested_questions: [
        '¿Cuál es el estado actual del cliente «Acme»?',
        '¿Qué bloqueos ha reportado el equipo de Diseño esta semana?',
        'Hazme un resumen de los logros de Pablo Ruiz este mes.',
        '¿Cuándo fue la última vez que tuvimos problemas con la API?',
        '¿Qué clientes están en riesgo según los últimos reportes?',
    ],
    scope: {
        weeklies: true,
        clients: true,
        project_status: true,
        hours: 'own',
        financials: false,
    },
    max_question: 2000,
};

const fetchMock = vi.fn<typeof fetch>();

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

beforeEach(() => {
    window.sessionStorage.clear();
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Asistente IA (/ia, F-146)', () => {
    it('enseña las preguntas sugeridas, lo que puede ver y el aviso de que es IA', () => {
        render(<Assistant {...props} />);

        expect(screen.getByText('¿Qué quieres saber hoy?')).toBeTruthy();
        expect(
            screen.getAllByRole('button', {
                name: /Acme|Diseño|Pablo|API|riesgo/,
            }),
        ).toHaveLength(5);
        expect(screen.getByTestId('assistant-scope').textContent).toContain(
            'que has registrado tú.',
        );
        expect(screen.getByTestId('assistant-scope').textContent).toContain(
            'Sin importes',
        );
        expect(screen.getByText(/La IA puede cometer errores/)).toBeTruthy();
    });

    it('una pregunta sugerida se manda, espera la respuesta en la cola y la enseña', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        fetchMock
            .mockResolvedValueOnce(
                json(
                    {
                        question: {
                            id: 'q-1',
                            state: 'queued',
                            answer: null,
                            error: null,
                        },
                    },
                    202,
                ),
            )
            .mockResolvedValueOnce(
                json({
                    question: {
                        id: 'q-1',
                        state: 'running',
                        answer: null,
                        error: null,
                    },
                }),
            )
            .mockResolvedValueOnce(
                json({
                    question: {
                        id: 'q-1',
                        state: 'done',
                        answer: 'Acme va bien.\n- La web avanza.',
                        error: null,
                    },
                }),
            );
        render(<Assistant {...props} />);

        await user.click(screen.getByTestId('assistant-suggestion-0'));

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/ia/preguntas');
        expect(JSON.parse(String(init?.body))).toEqual({
            question: '¿Cuál es el estado actual del cliente «Acme»?',
            history: [],
        });
        expect(
            screen.getByTestId('assistant-message-user').textContent,
        ).toContain('«Acme»');
        expect(screen.getByTestId('assistant-loading').textContent).toContain(
            'Analizando reportes…',
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(ASSISTANT_POLL_MS);
        });

        expect(fetchMock.mock.calls[1][0]).toBe('/ia/preguntas/q-1');
        expect(
            screen.getByTestId('assistant-message-assistant').textContent,
        ).toContain('Acme va bien.');
        expect(screen.queryByTestId('assistant-loading')).toBeNull();
    });

    it('Intro envía con la conversación anterior; Mayúsculas+Intro, no', async () => {
        const previous: AssistantMessage[] = [
            { id: '1', role: 'user', content: '¿Cómo va Acme?', at: '' },
            { id: '2', role: 'assistant', content: 'Bien.', at: '' },
            {
                id: '3',
                role: 'assistant',
                content: 'Error',
                error: true,
                at: '',
            },
        ];
        window.sessionStorage.setItem(
            storageKey(7),
            JSON.stringify({ messages: previous, pending: null }),
        );
        fetchMock.mockResolvedValue(
            json(
                {
                    question: {
                        id: 'q-2',
                        state: 'queued',
                        answer: null,
                        error: null,
                    },
                },
                202,
            ),
        );
        const user = userEvent.setup();
        render(<Assistant {...props} />);

        // El historial de la sesión vuelve al recargar.
        expect(screen.getAllByTestId(/assistant-message-/)).toHaveLength(3);

        const input = screen.getByTestId('assistant-input');
        await user.type(input, '¿Y la semana pasada?{Shift>}{Enter}{/Shift}');
        expect(fetchMock).not.toHaveBeenCalled();

        await user.type(input, '{Enter}');
        expect(JSON.parse(String(fetchMock.mock.calls[0][1]?.body))).toEqual({
            question: '¿Y la semana pasada?',
            history: [
                { role: 'user', content: '¿Cómo va Acme?' },
                { role: 'assistant', content: 'Bien.' },
            ],
        });
    });

    it('si la petición falla, lo dice con el detalle y deja volver a preguntar', async () => {
        fetchMock.mockResolvedValue(
            json(
                {
                    message: 'Escribe una pregunta.',
                    errors: { question: ['Escribe una pregunta.'] },
                },
                422,
            ),
        );
        const user = userEvent.setup();
        render(<Assistant {...props} />);

        await user.type(screen.getByTestId('assistant-input'), 'Hola{Enter}');

        expect(
            screen.getByTestId('assistant-message-assistant').textContent,
        ).toContain('Detalle: Escribe una pregunta.');
        expect(screen.queryByTestId('assistant-loading')).toBeNull();

        await user.click(screen.getByTestId('assistant-reset'));
        expect(screen.queryAllByTestId(/assistant-message-/)).toHaveLength(0);
        expect(window.sessionStorage.getItem(storageKey(7))).toContain(
            '"messages":[]',
        );
    });

    it('la conversación que se manda: los 6 últimos turnos y sin los errores', () => {
        const messages: AssistantMessage[] = Array.from(
            { length: 9 },
            (_, index) => ({
                id: String(index),
                role: index % 2 === 0 ? 'user' : 'assistant',
                content: `m${index}`,
                error: index === 8 ? true : undefined,
                at: '',
            }),
        );

        expect(historyFor(messages).map((item) => item.content)).toEqual([
            'm2',
            'm3',
            'm4',
            'm5',
            'm6',
            'm7',
        ]);
    });
});

describe('El asistente en la barra lateral (F-006)', () => {
    const can = { useWeeklies: true } as Abilities;

    it('sale para quien usa la Weekly con el módulo encendido', () => {
        const titles = (
            counters: { assistantEnabled?: boolean },
            abilities: Abilities = can,
        ) => mainNavItems(abilities, counters).map((item) => item.title);

        expect(titles({ assistantEnabled: true })).toContain('Asistente IA');
        expect(titles({ assistantEnabled: false })).not.toContain(
            'Asistente IA',
        );
        expect(
            titles({ assistantEnabled: true }, {
                useWeeklies: false,
            } as Abilities),
        ).not.toContain('Asistente IA');
    });
});
