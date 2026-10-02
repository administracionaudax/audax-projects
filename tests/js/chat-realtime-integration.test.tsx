// @vitest-environment jsdom
import {
    act,
    configure,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createFakeEcho, jsonResponse, urlOf } from './realtime-fake-echo';
import type { FakeEcho } from './realtime-fake-echo';

/**
 * Integración del chat (C1) con el tiempo real (C2): con Reverb, los avisos del canal
 * conversation.{id} solo llevan ids y llegan también a la pestaña que hizo el cambio, así que el
 * chat deduplica por id y pide lo que le falta a sus rutas.
 */

const mocks = vi.hoisted(() => ({
    echo: null as FakeEcho | null,
    page: {
        url: '/chat/1',
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Ana Pérez',
                    email: 'ana@audaxstudio.com',
                    avatar: null,
                    roles: ['employee'],
                    is_client: false,
                },
                can: {},
            },
        },
    },
}));

vi.mock('@laravel/echo-react', () => ({ echo: () => mocks.echo }));
vi.mock('@/lib/realtime', () => ({
    realtimeEnabled: () => true,
    configureRealtime: () => undefined,
}));
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => mocks.page,
    router: { visit: vi.fn(), post: vi.fn() },
    Link: ({
        href,
        children,
        only: _only,
        preserveState: _preserveState,
        preserveScroll: _preserveScroll,
        ...rest
    }: {
        href: string | { url: string };
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

import { ConversationView } from '@/components/chat/conversation-view';
import { TooltipProvider } from '@/components/ui/tooltip';
import { resetPresenceForTests } from '@/hooks/use-presence';
import { resetRealtimeConnectionForTests } from '@/hooks/use-realtime-connection';
import { resetUnreadForTests } from '@/hooks/use-realtime-unread';
import type { ChatConversation, ChatMessage } from '@/types/chat';

configure({ testIdAttribute: 'data-test' });

const ana = { id: 1, name: 'Ana Pérez', avatar: null, is_active: true };
const luis = { id: 2, name: 'Luis Gil', avatar: null, is_active: true };

function message(
    id: number,
    overrides: Partial<ChatMessage> = {},
): ChatMessage {
    return {
        id,
        conversation_id: 1,
        type: 'text',
        author: luis,
        body: `Mensaje ${id}`,
        created_at: new Date(Date.now() - (60 - id) * 60_000).toISOString(),
        edited_at: null,
        deleted: false,
        hidden: false,
        hidden_by: null,
        pinned: false,
        pinned_by: null,
        parent: null,
        reactions: [],
        attachments: [],
        audio: null,
        transcription: null,
        task: null,
        link_preview: null,
        system: null,
        can: {
            edit: false,
            delete: false,
            reply: true,
            react: true,
            pin: true,
            moderate: false,
            create_task: false,
        },
        ...overrides,
    };
}

const conversation: ChatConversation = {
    id: 1,
    type: 'direct',
    title: 'Luis Gil',
    subtitle: null,
    project: null,
    other_user: luis,
    participants: [
        { ...ana, last_read_message_id: 10 },
        { ...luis, last_read_message_id: 10 },
    ],
    muted: false,
    is_participant: true,
    last_read_message_id: 10,
    can: { post: true, moderate: false, create_task: false, mute: true },
    read_only_reason: null,
};

function poll(messages: ChatMessage[]) {
    return {
        messages,
        updated: [],
        users: [],
        has_more: false,
        pinned: [],
        read_state: [],
        server_time: new Date().toISOString(),
    };
}

type Handler = (url: URL, init: RequestInit) => unknown;

function mockFetch(routes: Record<string, Handler>) {
    return vi
        .spyOn(globalThis, 'fetch')
        .mockImplementation(async (input, init = {}) => {
            const url = new URL(urlOf(input), 'http://localhost');
            const handler = routes[`${init.method ?? 'GET'} ${url.pathname}`];

            if (url.pathname === '/tiempo-real/no-leidos') {
                return jsonResponse({ total: 0, conversations: {}, muted: [] });
            }

            if (url.pathname.startsWith('/tiempo-real/')) {
                return url.pathname.endsWith('/leidos')
                    ? jsonResponse({ participants: [] })
                    : jsonResponse(null, 204);
            }

            return handler
                ? jsonResponse(handler(url, init))
                : jsonResponse({ message: 'Sin simular' }, 500);
        });
}

function chatCalls(mock: ReturnType<typeof mockFetch>, path: string) {
    return mock.mock.calls.filter(([input]) =>
        new URL(urlOf(input), 'http://localhost').pathname.endsWith(path),
    );
}

function renderView(messages: ChatMessage[]) {
    return render(
        <TooltipProvider>
            <ConversationView
                conversation={conversation}
                initial={{
                    messages,
                    users: [ana, luis],
                    has_older: false,
                    has_newer: false,
                    server_time: new Date().toISOString(),
                }}
                pinned={[]}
                focus={null}
            />
        </TooltipProvider>,
    );
}

function channel() {
    const found = mocks.echo?.channel('private-conversation.1');

    if (!found) {
        throw new Error('El chat no se ha suscrito a conversation.1');
    }

    return found;
}

describe('chat con tiempo real (C1 + C2)', () => {
    beforeEach(() => {
        mocks.echo = createFakeEcho();
    });

    afterEach(() => {
        resetRealtimeConnectionForTests();
        resetUnreadForTests();
        resetPresenceForTests();
        vi.restoreAllMocks();
    });

    it('el aviso de un mensaje propio ya recibido no duplica ni vuelve a pedir nada', async () => {
        const user = userEvent.setup();
        const sent = message(20, { author: ana, body: 'Hola Luis' });
        const fetchMock = mockFetch({
            'POST /chat/1/mensajes': () => ({ message: sent, users: [] }),
            'GET /chat/1/novedades': () => poll([sent]),
        });
        renderView([message(10)]);

        await user.type(
            screen.getByRole('textbox', { name: 'Escribe un mensaje' }),
            'Hola Luis{Enter}',
        );
        await waitFor(() => expect(screen.queryByText('Enviando…')).toBeNull());

        act(() => {
            channel().emit('.message.posted', {
                conversation_id: 1,
                message_id: 20,
                type: 'text',
                user_id: 1,
                parent_id: null,
                created_at: sent.created_at,
            });
        });

        expect(screen.getAllByTestId('chat-message')).toHaveLength(2);
        expect(chatCalls(fetchMock, '/novedades')).toHaveLength(0);
    });

    it('el aviso de un mensaje ajeno trae las novedades una vez, aunque llegue dos veces', async () => {
        const incoming = message(21, { body: '¿Lo ves?' });
        const fetchMock = mockFetch({
            'GET /chat/1/novedades': () => poll([incoming]),
            'POST /chat/1/leido': () => ({ unread: 0, unread_total: 0 }),
        });
        renderView([message(10)]);

        const event = {
            conversation_id: 1,
            message_id: 21,
            type: 'text',
            user_id: 2,
            parent_id: null,
            created_at: incoming.created_at,
        };

        act(() => channel().emit('.message.posted', event));
        await waitFor(() => expect(screen.getByText('¿Lo ves?')).toBeTruthy());

        act(() => channel().emit('.message.posted', event));

        expect(screen.getAllByTestId('chat-message')).toHaveLength(2);
        expect(chatCalls(fetchMock, '/novedades')).toHaveLength(1);
    });

    it('el aviso de un audio transcrito vuelve a pedir ese mensaje', async () => {
        const fetchMock = mockFetch({
            'GET /chat/mensajes/10': () => ({
                message: message(10, { body: 'Actualizado' }),
                users: [],
            }),
        });
        renderView([message(10)]);

        act(() =>
            channel().emit('.audio.transcribed', {
                conversation_id: 1,
                message_id: 10,
                transcription_id: 3,
                status: 'done',
            }),
        );

        await waitFor(() =>
            expect(screen.getByText('Actualizado')).toBeTruthy(),
        );
        expect(chatCalls(fetchMock, '/chat/mensajes/10')).toHaveLength(1);
    });
});
