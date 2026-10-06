// @vitest-environment jsdom
import { act, configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ChatConversationItem } from '@/types/chat';

const page = vi.hoisted(() => ({
    url: '/chat',
    props: { chat: { unread: 4 } } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => page,
    router: { post: vi.fn(), visit: vi.fn() },
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

import { pinnedNavItems } from '@/components/app-sidebar';
import {
    ConversationList,
    previewText,
} from '@/components/chat/conversation-list';
import { useChatUnreadTotal } from '@/components/chat/use-chat-unread';
import { resetUnreadForTests } from '@/hooks/use-realtime-unread';

configure({ testIdAttribute: 'data-test' });

const today = new Date().toISOString();

function item(
    id: number,
    overrides: Partial<ChatConversationItem> = {},
): ChatConversationItem {
    return {
        id,
        type: 'direct',
        title: `Conversación ${id}`,
        subtitle: null,
        project: null,
        other_user: null,
        members_count: 2,
        muted: false,
        unread: 0,
        read_only: false,
        last_message: null,
        last_activity_at: today,
        ...overrides,
    };
}

const items: ChatConversationItem[] = [
    item(1, {
        title: 'Luis Gil',
        other_user: { id: 2, name: 'Luis Gil', avatar: null, is_active: true },
        unread: 3,
        last_message: {
            id: 10,
            kind: 'text',
            author: 'Luis Gil',
            is_mine: false,
            preview: '¿Lo tienes?',
            system: null,
            created_at: today,
        },
    }),
    item(2, {
        type: 'project',
        title: 'Web corporativa',
        subtitle: 'ARR-WEB',
        project: {
            id: 4,
            code: 'ARR-WEB',
            name: 'Web corporativa',
            color: '#0171FF',
            status: 'archived',
        },
        read_only: true,
        muted: true,
        unread: 120,
        last_message: {
            id: 11,
            kind: 'system',
            author: null,
            is_mine: false,
            preview: '',
            system: {
                key: 'milestone.completed',
                payload: { task: 'Entrega' },
            },
            created_at: today,
        },
    }),
    item(3, {
        type: 'group',
        title: 'Diseño',
        last_message: {
            id: 12,
            kind: 'audio',
            author: 'Marta Ruiz',
            is_mine: false,
            preview: '',
            system: null,
            created_at: today,
        },
    }),
    item(4, {
        type: 'group',
        title: 'Álvaro y Ana',
        last_message: {
            id: 13,
            kind: 'text',
            author: 'Ana Pérez',
            is_mine: true,
            preview: 'Voy',
            system: null,
            created_at: today,
        },
    }),
];

afterEach(() => {
    vi.restoreAllMocks();
});

describe('ConversationList', () => {
    it('pinta cada conversación con su vista previa, hora, no leídos y estados', () => {
        render(<ConversationList items={items} activeId={1} />);

        const nav = screen.getByRole('navigation', { name: 'Conversaciones' });
        const links = within(nav).getAllByRole('link');

        expect(links).toHaveLength(4);
        expect(links[0].getAttribute('href')).toBe('/chat/1');
        expect(links[0].getAttribute('aria-current')).toBe('page');
        expect(links[0].textContent).toContain('¿Lo tienes?');
        expect(links[0].textContent).toContain('3 sin leer');

        // Silenciada, archivada, con más de 99 y un aviso del sistema.
        expect(links[1].textContent).toContain('99+');
        expect(links[1].textContent).toContain('Silenciada');
        expect(links[1].textContent).toContain('Proyecto archivado');
        expect(links[1].textContent).toContain('Hito completado: «Entrega».');

        expect(links[2].textContent).toContain('Marta: Audio');
        expect(links[3].textContent).toContain('Tú: Voy');
    });

    it('con los contadores de C2, cada fila lleva su no leídos en vivo', async () => {
        page.props.auth = { user: { id: 1, name: 'Ana' } };
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    total: 5,
                    conversations: { 1: 5, 2: 1 },
                    muted: [2],
                }),
                { headers: { 'Content-Type': 'application/json' } },
            ),
        );

        try {
            render(<ConversationList items={items} activeId={null} />);
            const nav = screen.getByRole('navigation', {
                name: 'Conversaciones',
            });

            expect(
                await within(nav).findByText('5 mensajes sin leer'),
            ).toBeTruthy();
            expect(
                within(nav).getByText(
                    '1 mensaje sin leer (conversación silenciada)',
                ),
            ).toBeTruthy();
            expect(within(nav).queryByText('99+')).toBeNull();
        } finally {
            resetUnreadForTests();
            delete page.props.auth;
        }
    });

    it('lleva a la búsqueda del chat y a los avisos de este navegador', () => {
        render(<ConversationList items={items} activeId={null} />);

        expect(
            screen
                .getByRole('link', { name: 'Buscar en el chat' })
                .getAttribute('href'),
        ).toBe('/chat/buscar');
        expect(
            screen.getByRole('button', {
                name: 'Activar avisos en este navegador',
            }),
        ).toBeTruthy();
    });

    it('busca por nombre sin tildes ni mayúsculas', async () => {
        const user = userEvent.setup();
        render(<ConversationList items={items} activeId={null} />);

        await user.type(
            screen.getByRole('searchbox', { name: 'Buscar conversación' }),
            'alvaro',
        );
        expect(screen.getAllByTestId('chat-conversation-item')).toHaveLength(1);

        await user.clear(screen.getByRole('searchbox'));
        await user.type(screen.getByRole('searchbox'), 'arr-web');
        expect(
            screen.getAllByTestId('chat-conversation-item')[0].textContent,
        ).toContain('Web corporativa');

        await user.clear(screen.getByRole('searchbox'));
        await user.type(screen.getByRole('searchbox'), 'nada');
        expect(
            screen.getByText('No hay conversaciones que coincidan con «nada».'),
        ).toBeTruthy();
    });

    it('sin conversaciones invita a empezar una', () => {
        render(<ConversationList items={[]} activeId={null} />);

        expect(screen.getByText('Aún no tienes conversaciones')).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Mensaje directo' }),
        ).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Grupo' })).toBeTruthy();
        expect(screen.queryByRole('searchbox')).toBeNull();
    });

    it('a un colaborador externo no le ofrece directas ni grupos (D-134)', () => {
        const previous = page.props;
        page.props = {
            ...previous,
            auth: {
                user: {
                    id: 30,
                    roles: ['collaborator'],
                    is_collaborator: true,
                },
            },
        };

        try {
            render(<ConversationList items={[]} activeId={null} />);

            expect(
                screen.getByText('Aún no tienes conversaciones'),
            ).toBeTruthy();
            expect(
                screen.getByText(/Ábrelo desde la pestaña «Chat» del proyecto/),
            ).toBeTruthy();
            expect(
                screen.queryByRole('button', { name: 'Mensaje directo' }),
            ).toBeNull();
            expect(screen.queryByRole('button', { name: 'Grupo' })).toBeNull();
            expect(document.querySelector('[data-test="chat-new"]')).toBeNull();
        } finally {
            page.props = previous;
        }
    });

    it('«Nuevo mensaje directo» carga las personas y las deja buscar', async () => {
        const user = userEvent.setup();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    people: [
                        {
                            id: 2,
                            name: 'Luis Gil',
                            avatar: null,
                            is_active: true,
                            department: 'Diseño',
                        },
                        {
                            id: 3,
                            name: 'Marta Ruiz',
                            avatar: null,
                            is_active: true,
                            department: null,
                        },
                    ],
                }),
                {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        );
        render(<ConversationList items={items} activeId={null} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Nuevo: mensaje directo o grupo',
            }),
        );
        await user.click(
            await screen.findByRole('menuitem', { name: 'Mensaje directo' }),
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Nuevo mensaje directo',
        });
        expect(await within(dialog).findByText('Luis Gil')).toBeTruthy();
        expect(within(dialog).getByText('Marta Ruiz')).toBeTruthy();
    });

    it('«Nuevo grupo» pide nombre y al menos una persona', async () => {
        const user = userEvent.setup();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    people: [
                        {
                            id: 2,
                            name: 'Luis Gil',
                            avatar: null,
                            is_active: true,
                            department: null,
                        },
                    ],
                }),
                {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        );
        render(<ConversationList items={[]} activeId={null} />);

        await user.click(screen.getByRole('button', { name: 'Grupo' }));
        const dialog = await screen.findByRole('dialog', {
            name: 'Nuevo grupo',
        });
        await within(dialog).findByText('Luis Gil');
        await user.click(
            within(dialog).getByRole('button', { name: 'Crear grupo' }),
        );

        expect(
            within(dialog).getByText('Ponle un nombre al grupo.'),
        ).toBeTruthy();
        expect(
            within(dialog).getByText('Elige al menos a una persona.'),
        ).toBeTruthy();
    });
});

describe('vista previa', () => {
    it('sin mensajes, ocultado y archivo', () => {
        expect(previewText(item(9))).toBe('Sin mensajes todavía');
        expect(
            previewText(
                item(9, {
                    last_message: {
                        id: 1,
                        kind: 'hidden',
                        author: 'Luis',
                        is_mine: false,
                        preview: '',
                        system: null,
                        created_at: today,
                    },
                }),
            ),
        ).toBe('Mensaje ocultado');
        expect(
            previewText(
                item(9, {
                    last_message: {
                        id: 1,
                        kind: 'file',
                        author: 'Luis',
                        is_mine: false,
                        preview: '',
                        system: null,
                        created_at: today,
                    },
                }),
            ),
        ).toBe('Archivo');
    });
});

describe('total sin leer de la navegación', () => {
    function Badge() {
        return <span data-test="total">{useChatUnreadTotal()}</span>;
    }

    afterEach(() => {
        resetUnreadForTests();
        delete page.props.auth;
    });

    it('parte de las props compartidas y, en cuanto llega, manda el contador de C2', async () => {
        page.props.auth = { user: { id: 1, name: 'Ana' } };
        let resolve: (response: Response) => void = () => undefined;
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockReturnValue(
            new Promise<Response>((done) => {
                resolve = done;
            }),
        );

        render(<Badge />);
        expect(screen.getByTestId('total').textContent).toBe('4');
        expect(fetchMock.mock.calls[0][0]).toBe('/tiempo-real/no-leidos');

        await act(async () => {
            resolve(
                new Response(
                    JSON.stringify({
                        total: 1,
                        conversations: { 3: 1 },
                        muted: [],
                    }),
                    { headers: { 'Content-Type': 'application/json' } },
                ),
            );
        });
        expect(screen.getByTestId('total').textContent).toBe('1');
    });

    it('la entrada Chat lleva el contador con su texto accesible', () => {
        const chat = pinnedNavItems({ chatUnread: 5 }).find(
            (entry) => entry.title === 'Chat',
        );

        expect(chat?.badge).toEqual({ count: 5, label: '5 mensajes sin leer' });
        expect(
            pinnedNavItems().find((entry) => entry.title === 'Chat')?.badge,
        ).toBeUndefined();
    });
});
