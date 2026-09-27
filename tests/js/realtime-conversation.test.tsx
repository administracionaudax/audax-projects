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
    useConversationChannel,
    VIEWING_HEARTBEAT_MS,
} from '@/hooks/use-realtime';
import type { ConversationChannelHandlers } from '@/hooks/use-realtime';
import { resetRealtimeConnectionForTests } from '@/hooks/use-realtime-connection';
import { resetUnreadForTests } from '@/hooks/use-realtime-unread';

function Conversation({
    id,
    handlers,
}: {
    id: number | null;
    handlers: ConversationChannelHandlers;
}) {
    const { live } = useConversationChannel(id, handlers);

    return <p>{live ? 'en vivo' : 'sin conexión en vivo'}</p>;
}

function calls(fetchMock: ReturnType<typeof vi.spyOn>, method: string) {
    return fetchMock.mock.calls.filter(
        ([, init]) => (init as RequestInit | undefined)?.method === method,
    );
}

describe('useConversationChannel', () => {
    let fetchMock: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mocks.realtime = false;
        mocks.echo = createFakeEcho();
        fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockImplementation(async () => jsonResponse(null, 204));
    });

    afterEach(() => {
        resetRealtimeConnectionForTests();
        resetUnreadForTests();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('sin tiempo real no se suscribe a nada, pero avisa de que la conversación está abierta', async () => {
        const echoSpy = vi.spyOn(mocks.echo as FakeEcho, 'private');
        const { unmount } = render(
            <Conversation id={5} handlers={{ onMessagePosted: vi.fn() }} />,
        );

        expect(screen.getByText('sin conexión en vivo')).toBeTruthy();
        expect(echoSpy).not.toHaveBeenCalled();
        expect(calls(fetchMock, 'POST')).toHaveLength(1);
        expect(urlOf(calls(fetchMock, 'POST')[0][0])).toBe(
            '/tiempo-real/conversaciones/5/viendo',
        );

        await act(async () => {
            vi.advanceTimersByTime(VIEWING_HEARTBEAT_MS);
        });
        expect(calls(fetchMock, 'POST')).toHaveLength(2);

        unmount();
        const deletes = calls(fetchMock, 'DELETE');
        expect(deletes).toHaveLength(1);
        expect(urlOf(deletes[0][0])).toBe(
            '/tiempo-real/conversaciones/5/viendo',
        );
        expect((deletes[0][1] as RequestInit).keepalive).toBe(true);
    });

    it('al ocultar la pestaña retira la marca y la renueva al volver', async () => {
        render(<Conversation id={5} handlers={{}} />);
        const visibility = vi
            .spyOn(document, 'visibilityState', 'get')
            .mockReturnValue('hidden');

        await act(async () => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
        expect(calls(fetchMock, 'DELETE')).toHaveLength(1);

        await act(async () => {
            vi.advanceTimersByTime(VIEWING_HEARTBEAT_MS * 2);
        });
        expect(calls(fetchMock, 'POST')).toHaveLength(1);

        visibility.mockReturnValue('visible');
        await act(async () => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
        expect(calls(fetchMock, 'POST')).toHaveLength(2);
    });

    it('con tiempo real entrega los eventos de la conversación a sus manejadores (los últimos)', () => {
        mocks.realtime = true;
        const first = vi.fn();
        const posted = vi.fn();
        const updated = vi.fn();
        const read = vi.fn();
        const transcribed = vi.fn();
        const { rerender } = render(
            <Conversation id={5} handlers={{ onMessagePosted: first }} />,
        );

        rerender(
            <Conversation
                id={5}
                handlers={{
                    onMessagePosted: posted,
                    onMessageUpdated: updated,
                    onRead: read,
                    onAudioTranscribed: transcribed,
                }}
            />,
        );

        const channel = mocks.echo?.channel('private-conversation.5');
        expect(channel).toBeTruthy();
        expect(screen.getByText('en vivo')).toBeTruthy();

        const event = {
            conversation_id: 5,
            message_id: 10,
            type: 'text',
            user_id: 2,
            parent_id: null,
            created_at: '2026-09-27T10:00:00Z',
        };
        channel?.emit('.message.posted', event);
        channel?.emit('.message.updated', {
            conversation_id: 5,
            message_id: 10,
            change: 'edited',
        });
        channel?.emit('.conversation.read', {
            conversation_id: 5,
            user_id: 2,
            last_read_message_id: 10,
        });
        channel?.emit('.audio.transcribed', {
            conversation_id: 5,
            message_id: 11,
            transcription_id: 3,
            status: 'done',
        });

        expect(first).not.toHaveBeenCalled();
        expect(posted).toHaveBeenCalledWith(event);
        expect(updated).toHaveBeenCalledWith(
            expect.objectContaining({ change: 'edited' }),
        );
        expect(read).toHaveBeenCalledWith(
            expect.objectContaining({ last_read_message_id: 10 }),
        );
        expect(transcribed).toHaveBeenCalledWith(
            expect.objectContaining({ message_id: 11 }),
        );
    });

    it('dos pantallas de la misma conversación comparten una suscripción, que se deja con la última', () => {
        mocks.realtime = true;
        const a = render(<Conversation id={5} handlers={{}} />);
        const b = render(<Conversation id={5} handlers={{}} />);
        const channel = mocks.echo?.channel('private-conversation.5');

        expect(channel?.listenerCount('.message.posted')).toBe(2);

        a.unmount();
        expect(mocks.echo?.left).toEqual([]);
        expect(channel?.listenerCount('.message.posted')).toBe(1);

        b.unmount();
        expect(mocks.echo?.left).toEqual(['private-conversation.5']);
        expect(channel?.listenerCount('.message.posted')).toBe(0);
    });

    it('al cambiar de conversación deja la anterior', () => {
        mocks.realtime = true;
        const { rerender } = render(<Conversation id={5} handlers={{}} />);

        rerender(<Conversation id={6} handlers={{}} />);

        expect(mocks.echo?.left).toEqual(['private-conversation.5']);
        expect(mocks.echo?.channel('private-conversation.6')).toBeTruthy();
    });

    it('si la conexión se corta, deja de estar en vivo y al volver avisa para recargar', async () => {
        mocks.realtime = true;
        const onReconnect = vi.fn();
        render(<Conversation id={5} handlers={{ onReconnect }} />);

        await act(async () => {
            mocks.echo?.setStatus('disconnected');
        });
        expect(screen.getByText('sin conexión en vivo')).toBeTruthy();
        expect(onReconnect).not.toHaveBeenCalled();

        await act(async () => {
            mocks.echo?.setStatus('connected');
        });
        expect(screen.getByText('en vivo')).toBeTruthy();
        expect(onReconnect).toHaveBeenCalledTimes(1);
    });

    it('sin conversación no hace nada', () => {
        mocks.realtime = true;
        render(<Conversation id={null} handlers={{}} />);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(mocks.echo?.channels.size).toBe(0);
    });
});
