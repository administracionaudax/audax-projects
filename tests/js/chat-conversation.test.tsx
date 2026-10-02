// @vitest-environment jsdom
import {
    act,
    configure,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import type {
    ChatConversation,
    ChatMessage,
    ChatMessagesPage,
    ChatPollResponse,
} from '@/types/chat';

const page = vi.hoisted(() => ({
    url: '/chat/1',
    props: {
        auth: {
            user: {
                id: 1,
                name: 'Ana Pérez',
                email: 'ana@audaxstudio.com',
                avatar: null,
                theme_preference: 'system',
                two_factor_enabled: false,
                roles: ['employee'],
                is_client: false,
            },
            can: {},
        },
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => page,
    router: { visit: vi.fn(), post: vi.fn() },
    Link: ({
        href,
        children,
        prefetch: _prefetch,
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

// Convención del proyecto: data-test (como los E2E).
configure({ testIdAttribute: 'data-test' });

const now = new Date();
const minutesAgo = (minutes: number) =>
    new Date(now.getTime() - minutes * 60_000).toISOString();

const ana = { id: 1, name: 'Ana Pérez', avatar: null, is_active: true };
const luis = { id: 2, name: 'Luis Gil', avatar: null, is_active: true };
const marta = { id: 3, name: 'Marta Ruiz', avatar: null, is_active: false };

const can = {
    edit: false,
    delete: false,
    reply: true,
    react: true,
    pin: true,
    moderate: false,
    create_task: false,
};

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
        created_at: minutesAgo(60 - id),
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
        task: null,
        link_preview: null,
        system: null,
        can,
        ...overrides,
    };
}

const conversation: ChatConversation = {
    id: 1,
    type: 'project',
    title: 'Web corporativa',
    subtitle: 'ARR-WEB',
    project: {
        id: 4,
        code: 'ARR-WEB',
        name: 'Web corporativa',
        color: '#0171FF',
        status: 'active',
    },
    other_user: null,
    participants: [
        { ...ana, last_read_message_id: 3 },
        { ...luis, last_read_message_id: 10 },
        { ...marta, last_read_message_id: null },
    ],
    muted: false,
    is_participant: true,
    last_read_message_id: 3,
    can: { post: true, moderate: false, create_task: true, mute: true },
    read_only_reason: null,
};

function pageOf(
    messages: ChatMessage[],
    extra: Partial<ChatMessagesPage> = {},
): ChatMessagesPage {
    return {
        messages,
        users: [ana, luis, marta],
        has_older: false,
        has_newer: false,
        server_time: now.toISOString(),
        ...extra,
    };
}

type Route = (url: URL, init: RequestInit) => unknown;

/** Lo que piden los hooks de tiempo real de C2 (sin Reverb: consultas a sus rutas). */
const REALTIME_ROUTES: Record<string, Route> = {
    'GET /tiempo-real/no-leidos': () => ({
        total: 0,
        conversations: {},
        muted: [],
    }),
    'POST /tiempo-real/conversaciones/1/viendo': () => ({}),
    'DELETE /tiempo-real/conversaciones/1/viendo': () => ({}),
    'GET /tiempo-real/conversaciones/1/leidos': () => ({
        participants: [
            { ...ana, last_read_message_id: 3 },
            { ...luis, last_read_message_id: 10 },
            {
                id: 4,
                name: 'Sara Molina',
                avatar: null,
                is_active: true,
                last_read_message_id: 3,
            },
        ],
    }),
};

function urlOf(input: unknown): URL {
    return new URL(
        typeof input === 'string'
            ? input
            : input instanceof URL
              ? input.href
              : (input as Request).url,
        'http://localhost',
    );
}

/** Peticiones del chat (sin las del tiempo real de C2). */
function chatCalls(
    mock: ReturnType<typeof mockFetch>,
): Parameters<typeof fetch>[] {
    return mock.mock.calls.filter(
        ([input]) => !urlOf(input).pathname.startsWith('/tiempo-real/'),
    );
}

function mockFetch(chatRoutes: Record<string, Route>) {
    const routes = { ...REALTIME_ROUTES, ...chatRoutes };

    return vi
        .spyOn(globalThis, 'fetch')
        .mockImplementation(async (input, init = {}) => {
            const url = new URL(
                typeof input === 'string'
                    ? input
                    : input instanceof URL
                      ? input.href
                      : input.url,
                'http://localhost',
            );
            const key = `${init.method ?? 'GET'} ${url.pathname}`;
            const handler = routes[key];

            if (!handler) {
                return new Response(
                    JSON.stringify({ message: `Sin simular: ${key}` }),
                    { status: 500 },
                );
            }

            return new Response(JSON.stringify(handler(url, init)), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        });
}

function renderView(
    messages: ChatMessage[],
    props: Partial<Parameters<typeof ConversationView>[0]> = {},
    extra: Partial<ChatMessagesPage> = {},
) {
    return render(
        <TooltipProvider>
            <ConversationView
                conversation={conversation}
                initial={pageOf(messages, extra)}
                pinned={[]}
                focus={null}
                {...props}
            />
        </TooltipProvider>,
    );
}

function items(): HTMLElement[] {
    return screen.getAllByTestId('chat-message');
}

beforeEach(() => {
    mockFetch({});
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('ConversationView', () => {
    it('pinta los mensajes agrupados por autor, con cabecera, día y separador de no leídos', () => {
        renderView([
            message(1, {
                author: ana,
                body: 'Hola equipo',
                created_at: minutesAgo(30),
            }),
            message(2, {
                author: ana,
                body: 'Segundo seguido',
                created_at: minutesAgo(29),
            }),
            message(4, {
                author: luis,
                body: 'Respuesta de **Luis**',
                created_at: minutesAgo(10),
                edited_at: minutesAgo(5),
            }),
            message(5, {
                author: marta,
                body: 'Me fui',
                created_at: minutesAgo(9),
            }),
        ]);

        expect(
            screen.getByRole('heading', { level: 2, name: 'Web corporativa' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('heading', { level: 3, name: 'Hoy' }),
        ).toBeTruthy();

        const [first, second, third, fourth] = items();
        expect(within(first).getByText('Ana Pérez')).toBeTruthy();
        expect(within(second).queryByText('Ana Pérez')).toBeNull();
        expect(within(third).getByText('(editado)')).toBeTruthy();
        expect(within(third).getByText('Luis').tagName).toBe('STRONG');
        expect(within(fourth).getByText('inactivo')).toBeTruthy();

        // Leído hasta el 3: el separador va antes del primer mensaje de otra persona sin leer.
        const separator = screen.getByText('Mensajes nuevos');
        expect(separator.closest('li')?.nextElementSibling).toBe(third);
    });

    it('pinta borrados, ocultados, sistema, previsualización, tarea, reacciones y citas', () => {
        renderView([
            message(1, { deleted: true, body: null }),
            message(2, { hidden: true, body: null }),
            message(3, {
                type: 'system',
                author: null,
                body: null,
                system: {
                    key: 'hour_bank.threshold',
                    payload: { bank: 'Bolsa T4', threshold: 90 },
                },
            }),
            message(4, {
                body: 'Mirad https://audaxstudio.com',
                link_preview: {
                    url: 'https://audaxstudio.com',
                    title: 'Audax Studio',
                    description: 'Agencia de UX',
                    domain: 'audaxstudio.com',
                },
                task: { id: 12, title: 'Revisar el menú' },
                reactions: [
                    {
                        emoji: '👍',
                        count: 2,
                        reacted: true,
                        users: ['Ana Pérez', 'Luis Gil'],
                    },
                ],
                parent: {
                    id: 1,
                    type: 'text',
                    author: 'Luis Gil',
                    excerpt: null,
                    deleted: true,
                    hidden: false,
                    system: null,
                },
            }),
        ]);

        expect(screen.getByText('Mensaje eliminado')).toBeTruthy();
        expect(
            screen.getByText('Mensaje ocultado por un administrador'),
        ).toBeTruthy();
        expect(screen.getByTestId('chat-system-message').textContent).toContain(
            'La bolsa «Bolsa T4» ha llegado al 90 % de consumo.',
        );
        expect(
            screen.getByTestId('chat-link-preview').getAttribute('rel'),
        ).toBe('noopener noreferrer nofollow');
        expect(
            screen.getByTestId('chat-message-task').getAttribute('href'),
        ).toBe('/tareas/12');
        expect(
            screen
                .getByRole('button', {
                    name: /Quitar tu reacción 👍 \(2: Ana Pérez, Luis Gil\)/,
                })
                .getAttribute('aria-pressed'),
        ).toBe('true');
        expect(screen.getByTestId('chat-parent-quote').textContent).toContain(
            'Respuesta a un mensaje eliminado',
        );
    });

    it('sin mensajes enseña el estado vacío y el editor', () => {
        renderView([]);

        expect(screen.getByText('Todavía no hay mensajes')).toBeTruthy();
        expect(
            screen.getByRole('combobox', { name: 'Escribe un mensaje' }),
        ).toBeTruthy();
    });

    it('en un proyecto archivado explica que es de solo lectura, sin editor', () => {
        renderView([message(1)], {
            conversation: {
                ...conversation,
                can: { ...conversation.can, post: false },
                read_only_reason: 'archived',
            },
        });

        expect(
            screen.getByText(
                'El proyecto está archivado: su chat es de solo lectura.',
            ),
        ).toBeTruthy();
        expect(screen.queryByRole('combobox')).toBeNull();
    });

    it('responde en hilo desde el menú de acciones y publica al momento', async () => {
        const user = userEvent.setup();
        const fetchMock = mockFetch({
            'POST /chat/1/mensajes': (_url, init) => {
                const data = JSON.parse(init.body as string) as {
                    body: string;
                    parent_id: number;
                };

                return {
                    message: message(20, {
                        author: ana,
                        body: data.body,
                        created_at: new Date().toISOString(),
                        parent: {
                            id: 5,
                            type: 'text',
                            author: 'Luis Gil',
                            excerpt: 'Pregunta',
                            deleted: false,
                            hidden: false,
                            system: null,
                        },
                        can: { ...can, edit: true, delete: true },
                    }),
                    users: [],
                };
            },
        });
        renderView([message(5, { body: 'Pregunta' })]);

        await user.click(
            screen.getByRole('button', {
                name: 'Acciones del mensaje de Luis Gil',
            }),
        );
        await user.click(
            await screen.findByRole('menuitem', { name: 'Responder' }),
        );

        expect(screen.getByText('Respondiendo a Luis Gil')).toBeTruthy();

        await user.type(
            screen.getByRole('combobox', { name: 'Escribe un mensaje' }),
            'Respuesta{Enter}',
        );

        await waitFor(() => expect(chatCalls(fetchMock)).toHaveLength(1));
        const [, init] = chatCalls(fetchMock)[0];
        expect(JSON.parse(init?.body as string)).toEqual({
            body: 'Respuesta',
            parent_id: 5,
        });
        expect(new Headers(init?.headers).get('Accept')).toBe(
            'application/json',
        );

        await waitFor(() => expect(items()).toHaveLength(2));
        expect(
            within(items()[1]).getByTestId('chat-parent-quote').textContent,
        ).toContain('Pregunta');
        expect(screen.queryByText('Enviando…')).toBeNull();
        expect(screen.queryByText('Respondiendo a Luis Gil')).toBeNull();
    });

    it('si el envío falla, deja reintentar o descartar', async () => {
        const user = userEvent.setup();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ message: 'x' }), { status: 500 }),
        );
        renderView([message(5)]);

        await user.type(
            screen.getByRole('combobox', { name: 'Escribe un mensaje' }),
            'No sale{Enter}',
        );

        expect(await screen.findByText('No se ha enviado')).toBeTruthy();
        await user.click(screen.getByRole('button', { name: 'Descartar' }));
        expect(screen.queryByText('No sale')).toBeNull();
    });

    it('reacciona al pulsar una reacción (al momento) y aplica la respuesta', async () => {
        const user = userEvent.setup();
        const fetchMock = mockFetch({
            'POST /chat/mensajes/5/reacciones': () => ({
                message: message(5, {
                    reactions: [
                        {
                            emoji: '🎉',
                            count: 2,
                            reacted: true,
                            users: ['Luis Gil', 'Ana Pérez'],
                        },
                    ],
                }),
                users: [],
            }),
        });
        renderView([
            message(5, {
                reactions: [
                    {
                        emoji: '🎉',
                        count: 1,
                        reacted: false,
                        users: ['Luis Gil'],
                    },
                ],
            }),
        ]);

        await user.click(
            screen.getByRole('button', { name: /Reaccionar con 🎉/ }),
        );

        expect(
            screen.getByRole('button', { name: /Quitar tu reacción 🎉/ }),
        ).toBeTruthy();
        await waitFor(() => expect(chatCalls(fetchMock)).toHaveLength(1));
        expect(JSON.parse(chatCalls(fetchMock)[0][1]?.body as string)).toEqual({
            emoji: '🎉',
        });
    });

    it('carga los mensajes anteriores con el botón (paginación hacia atrás)', async () => {
        const user = userEvent.setup();
        const fetchMock = mockFetch({
            'GET /chat/1/mensajes': (url) => {
                expect(url.searchParams.get('antes')).toBe('10');

                return pageOf(
                    [
                        message(8, { body: 'Más antiguo' }),
                        message(9, { body: 'Anterior' }),
                    ],
                    {
                        has_older: false,
                    },
                );
            },
        });
        renderView(
            [message(10, { body: 'Reciente' })],
            {},
            { has_older: true },
        );

        await user.click(
            screen.getByRole('button', { name: 'Cargar mensajes anteriores' }),
        );

        await waitFor(() => expect(items()).toHaveLength(3));
        expect(items().map((item) => item.textContent)).toEqual([
            expect.stringContaining('Más antiguo'),
            expect.stringContaining('Anterior'),
            expect.stringContaining('Reciente'),
        ]);
        expect(screen.getByText('Aquí empieza la conversación.')).toBeTruthy();
        expect(chatCalls(fetchMock)).toHaveLength(1);
    });

    it('borra un mensaje propio tras confirmarlo', async () => {
        const user = userEvent.setup();
        mockFetch({
            'DELETE /chat/mensajes/5': () => ({
                message: message(5, {
                    author: ana,
                    body: null,
                    deleted: true,
                    can: { ...can, reply: false, react: false, pin: false },
                }),
                users: [],
            }),
        });
        renderView([
            message(5, {
                author: ana,
                body: 'Me equivoqué',
                can: { ...can, edit: true, delete: true },
            }),
        ]);

        await user.click(
            screen.getByRole('button', {
                name: 'Acciones del mensaje de Ana Pérez',
            }),
        );
        await user.click(
            await screen.findByRole('menuitem', { name: 'Eliminar' }),
        );
        await user.click(await screen.findByTestId('chat-delete-confirm'));

        expect(await screen.findByText('Mensaje eliminado')).toBeTruthy();
        expect(screen.queryByText('Me equivoqué')).toBeNull();
    });

    it('edita un mensaje propio en su sitio', async () => {
        const user = userEvent.setup();
        mockFetch({
            'PATCH /chat/mensajes/5': (_url, init) => ({
                message: message(5, {
                    author: ana,
                    body: (JSON.parse(init.body as string) as { body: string })
                        .body,
                    edited_at: new Date().toISOString(),
                    can: { ...can, edit: true, delete: true },
                }),
                users: [],
            }),
        });
        renderView([
            message(5, {
                author: ana,
                body: 'Primera versión',
                can: { ...can, edit: true, delete: true },
            }),
        ]);

        await user.click(
            screen.getByRole('button', {
                name: 'Acciones del mensaje de Ana Pérez',
            }),
        );
        await user.click(
            await screen.findByRole('menuitem', { name: 'Editar' }),
        );

        const editor = screen.getByRole('combobox', {
            name: 'Edita el mensaje',
        });
        await user.clear(editor);
        await user.type(editor, 'Segunda versión{Enter}');

        expect(await screen.findByText('Segunda versión')).toBeTruthy();
        expect(screen.getByText('(editado)')).toBeTruthy();
        expect(
            screen.queryByRole('combobox', { name: 'Edita el mensaje' }),
        ).toBeNull();
    });

    it('anuncia los mensajes nuevos de otras personas que llegan con la consulta periódica', async () => {
        const poll: ChatPollResponse = {
            messages: [
                message(11, {
                    author: luis,
                    body: '¿Lo ves, <@1>?',
                    created_at: new Date().toISOString(),
                }),
            ],
            updated: [],
            users: [ana],
            has_more: false,
            pinned: [],
            read_state: [{ user_id: 2, last_read_message_id: 11 }],
            server_time: new Date().toISOString(),
        };
        const fetchMock = mockFetch({ 'GET /chat/1/novedades': () => poll });
        renderView([message(10)]);

        act(() => {
            window.dispatchEvent(new Event('focus'));
        });

        await waitFor(() => expect(items()).toHaveLength(2));
        const url = urlOf(chatCalls(fetchMock)[0][0]);
        expect(url.searchParams.get('despues')).toBe('10');
        expect(url.searchParams.get('desde')).toBe('10');
        expect(url.searchParams.get('cambios')).toBeTruthy();
        expect(
            screen.getByText('Nuevo mensaje de Luis Gil: ¿Lo ves, @Ana Pérez?'),
        ).toBeTruthy();
    });

    it('enseña quién ha leído el último mensaje propio (ReadBy de C2)', async () => {
        renderView([message(10, { author: ana, body: 'Listo' })]);

        expect(
            (await screen.findByTestId('chat-read-receipt')).textContent,
        ).toContain('Leído por Luis Gil');
    });

    it('un mensaje propio que llega también por el tiempo real no se duplica', async () => {
        const user = userEvent.setup();
        const fetchMock = mockFetch({
            'POST /chat/1/mensajes': () => ({
                message: message(20, {
                    author: ana,
                    body: 'Solo una vez',
                    created_at: new Date().toISOString(),
                }),
                users: [],
            }),
            'GET /chat/1/novedades': () => ({
                messages: [
                    message(20, {
                        author: ana,
                        body: 'Solo una vez',
                        created_at: new Date().toISOString(),
                    }),
                ],
                updated: [],
                users: [],
                has_more: false,
                pinned: [],
                read_state: [],
                server_time: new Date().toISOString(),
            }),
        });
        renderView([message(10)]);

        await user.type(
            screen.getByRole('combobox', { name: 'Escribe un mensaje' }),
            'Solo una vez{Enter}',
        );
        await waitFor(() => expect(items()).toHaveLength(2));

        // La consulta periódica (o el aviso del tiempo real) trae el mismo mensaje: se mezcla por id.
        act(() => {
            window.dispatchEvent(new Event('focus'));
        });
        await waitFor(() =>
            expect(
                chatCalls(fetchMock).some(([input]) =>
                    urlOf(input).pathname.endsWith('/novedades'),
                ),
            ).toBe(true),
        );

        expect(items()).toHaveLength(2);
        expect(screen.getAllByText('Solo una vez')).toHaveLength(1);
    });
});
