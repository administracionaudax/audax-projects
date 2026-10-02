// @vitest-environment jsdom
import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createFakeEcho, jsonResponse, urlOf } from './realtime-fake-echo';
import type { FakeEcho } from './realtime-fake-echo';

const mocks = vi.hoisted(() => ({
    realtime: false,
    echo: null as FakeEcho | null,
    page: { props: { auth: { user: { id: 1, name: 'Ana' } } } },
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => mocks.page }));
vi.mock('@laravel/echo-react', () => ({ echo: () => mocks.echo }));
vi.mock('@/lib/realtime', () => ({
    realtimeEnabled: () => mocks.realtime,
    configureRealtime: () => undefined,
}));

import {
    ChatUnreadBadge,
    ConversationUnreadBadge,
} from '@/components/realtime/unread-badge';
import { useConversationChannel } from '@/hooks/use-realtime';
import { resetRealtimeConnectionForTests } from '@/hooks/use-realtime-connection';
import {
    resetUnreadForTests,
    UNREAD_POLL_MS,
    useUnreadCounter,
} from '@/hooks/use-realtime-unread';

function Counter() {
    const { total, count, ready, markRead } = useUnreadCounter();

    return (
        <div>
            <p>{ready ? `total ${total}` : 'cargando'}</p>
            <p>conversación 5: {count(5)}</p>
            <p>conversación 7: {count(7)}</p>
            <button type="button" onClick={() => markRead(5)}>
                leer 5
            </button>
        </div>
    );
}

function OpenConversation({ id }: { id: number }) {
    useConversationChannel(id, {});

    return null;
}

const unreadCalls = (fetchMock: ReturnType<typeof vi.spyOn>) =>
    fetchMock.mock.calls.filter(([url]) =>
        urlOf(url).startsWith('/tiempo-real/no-leidos'),
    );

describe('useUnreadCounter', () => {
    let fetchMock: ReturnType<typeof vi.spyOn>;
    let response: {
        total: number;
        conversations: Record<string, number>;
        muted: number[];
        latest_message_id?: number | null;
    };

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mocks.realtime = false;
        mocks.echo = createFakeEcho();
        response = { total: 3, conversations: { '5': 2, '7': 1 }, muted: [9] };
        fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockImplementation(async (input) =>
                urlOf(input).startsWith('/tiempo-real/no-leidos')
                    ? jsonResponse(response)
                    : jsonResponse(null, 204),
            );
    });

    afterEach(() => {
        resetUnreadForTests();
        resetRealtimeConnectionForTests();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('pide los contadores una vez aunque los usen varios componentes', async () => {
        render(
            <>
                <Counter />
                <ChatUnreadBadge />
                <ConversationUnreadBadge conversationId={5} />
            </>,
        );

        expect(await screen.findByText('total 3')).toBeTruthy();
        expect(screen.getByText('conversación 5: 2')).toBeTruthy();
        expect(screen.getByText('3 mensajes sin leer')).toBeTruthy();
        expect(screen.getByText('2 mensajes sin leer')).toBeTruthy();
        expect(unreadCalls(fetchMock)).toHaveLength(1);
    });

    it('sin tiempo real consulta cada 30 segundos', async () => {
        render(<Counter />);
        await screen.findByText('total 3');

        response = { total: 5, conversations: { '5': 4, '7': 1 }, muted: [] };
        await act(async () => {
            vi.advanceTimersByTime(UNREAD_POLL_MS);
        });

        expect(await screen.findByText('total 5')).toBeTruthy();
        expect(unreadCalls(fetchMock)).toHaveLength(2);
    });

    it('en vivo suma los mensajes ajenos sin consultar (y no los propios ni los de conversaciones abiertas o silenciadas)', async () => {
        mocks.realtime = true;
        render(
            <>
                <Counter />
                <OpenConversation id={8} />
            </>,
        );
        await screen.findByText('total 3');
        const channel = mocks.echo?.channel('private-App.Models.User.1');

        await act(async () => {
            channel?.emit('.chat.activity', {
                conversation_id: 7,
                message_id: 20,
                user_id: 2,
            });
            channel?.emit('.chat.activity', {
                conversation_id: 7,
                message_id: 21,
                user_id: 1,
            });
            channel?.emit('.chat.activity', {
                conversation_id: 8,
                message_id: 22,
                user_id: 2,
            });
            channel?.emit('.chat.activity', {
                conversation_id: 9,
                message_id: 23,
                user_id: 2,
            });
        });

        expect(screen.getByText('total 4')).toBeTruthy();
        expect(screen.getByText('conversación 7: 2')).toBeTruthy();

        // En vivo no se consulta cada 30 s.
        await act(async () => {
            vi.advanceTimersByTime(UNREAD_POLL_MS * 2);
        });
        expect(unreadCalls(fetchMock)).toHaveLength(1);
    });

    it('al leer en otra pestaña o dispositivo, vuelve a pedir los contadores', async () => {
        mocks.realtime = true;
        render(<Counter />);
        await screen.findByText('total 3');

        response = { total: 1, conversations: { '7': 1 }, muted: [] };
        await act(async () => {
            mocks.echo
                ?.channel('private-App.Models.User.1')
                ?.emit('.conversation.read', {
                    conversation_id: 5,
                    user_id: 1,
                    last_read_message_id: 30,
                });
            vi.advanceTimersByTime(500);
        });

        expect(await screen.findByText('total 1')).toBeTruthy();
    });

    it('markRead pone una conversación a cero al momento', async () => {
        render(<Counter />);
        await screen.findByText('total 3');

        await act(async () => {
            screen.getByRole('button', { name: 'leer 5' }).click();
        });

        expect(screen.getByText('total 1')).toBeTruthy();
        expect(screen.getByText('conversación 5: 0')).toBeTruthy();
    });

    it('un recuento pedido antes de un +1 en vivo no lo pisa (y no lo cuenta dos veces)', async () => {
        mocks.realtime = true;
        let release: (value: Response) => void = () => {};
        response = {
            total: 3,
            conversations: { '5': 2, '7': 1 },
            muted: [9],
            latest_message_id: 19,
        };
        render(<Counter />);
        await screen.findByText('total 3');
        const channel = mocks.echo?.channel('private-App.Models.User.1');

        // Un recuento empieza (p. ej. al volver a la pestaña) y tarda en responder…
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    release = resolve;
                }),
        );
        await act(async () => {
            mocks.echo?.setStatus('disconnected');
            mocks.echo?.setStatus('connected');
        });

        // …mientras llega en vivo un mensaje nuevo (el 20) y otro que ya contaba (el 18).
        await act(async () => {
            channel?.emit('.chat.activity', {
                conversation_id: 7,
                message_id: 20,
                user_id: 2,
            });
        });
        expect(screen.getByText('total 4')).toBeTruthy();

        // El servidor respondió con lo que había hasta el mensaje 19: el 20 se vuelve a sumar.
        await act(async () => {
            release(
                jsonResponse({
                    total: 3,
                    conversations: { '5': 2, '7': 1 },
                    muted: [9],
                    latest_message_id: 19,
                }),
            );
        });
        expect(await screen.findByText('total 4')).toBeTruthy();
        expect(screen.getByText('conversación 7: 2')).toBeTruthy();

        // Uno que ya incluye el 20 no lo suma otra vez.
        response = {
            total: 4,
            conversations: { '5': 2, '7': 2 },
            muted: [9],
            latest_message_id: 20,
        };
        await act(async () => {
            mocks.echo?.setStatus('disconnected');
            mocks.echo?.setStatus('connected');
        });
        expect(await screen.findByText('total 4')).toBeTruthy();
        expect(screen.getByText('conversación 7: 2')).toBeTruthy();

        // El mismo aviso repetido no suma dos veces.
        await act(async () => {
            channel?.emit('.chat.activity', {
                conversation_id: 7,
                message_id: 20,
                user_id: 2,
            });
        });
        expect(screen.getByText('total 4')).toBeTruthy();
    });

    it('al reconectar se ponen al día', async () => {
        mocks.realtime = true;
        render(<Counter />);
        await screen.findByText('total 3');

        response = { total: 6, conversations: { '5': 6 }, muted: [] };
        await act(async () => {
            mocks.echo?.setStatus('disconnected');
            mocks.echo?.setStatus('connected');
        });

        expect(await screen.findByText('total 6')).toBeTruthy();
    });

    it('la insignia de una conversación silenciada lo dice y no suma al total', async () => {
        response = { total: 0, conversations: { '9': 4 }, muted: [9] };
        render(
            <>
                <ChatUnreadBadge />
                <ConversationUnreadBadge conversationId={9} />
            </>,
        );

        expect(
            await screen.findByText(
                '4 mensajes sin leer (conversación silenciada)',
            ),
        ).toBeTruthy();
        expect(screen.queryByText('0 mensajes sin leer')).toBeNull();
    });

    it('sin sesión no consulta nada', () => {
        const previous = mocks.page;
        mocks.page = {
            props: { auth: { user: null } },
        } as unknown as typeof previous;
        render(<Counter />);

        expect(fetchMock).not.toHaveBeenCalled();
        mocks.page = previous;
    });
});
