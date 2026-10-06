// @vitest-environment jsdom
import { configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChatConversationItem } from '@/types/chat';

const page = vi.hoisted(() => ({
    url: '/chat',
    props: { chat: { unread: 0 } } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => page,
    router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn() },
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

import { ConversationList } from '@/components/chat/conversation-list';
import { buildChatTree } from '@/components/chat/conversation-tree';
import {
    chatListPrefsKey,
    DEFAULT_CHAT_LIST_PREFS,
    readChatListPrefs,
} from '@/components/chat/use-chat-list-prefs';

configure({ testIdAttribute: 'data-test' });

const hours = (n: number) => new Date(Date.now() - n * 3_600_000).toISOString();

const naranjas = { id: 7, name: 'Naranjas', icon: '🍊', is_active: true };
const hn = { id: 8, name: 'H&N', icon: '🐓', is_active: true };
const old = { id: 9, name: 'Antiguo', icon: null, is_active: false };

function item(
    id: number,
    overrides: Partial<ChatConversationItem> = {},
): ChatConversationItem {
    return {
        id,
        type: 'direct',
        title: `Conversación ${id}`,
        subtitle: null,
        icon: null,
        project: null,
        client: null,
        other_user: null,
        members_count: 2,
        is_participant: true,
        muted: false,
        unread: 0,
        read_only: false,
        last_message: null,
        last_activity_at: hours(id),
        ...overrides,
    };
}

function project(
    id: number,
    client: typeof naranjas | null,
    overrides: Partial<ChatConversationItem> = {},
): ChatConversationItem {
    return item(id, {
        type: 'project',
        title: `Proyecto ${id}`,
        subtitle: `P-${id}`,
        client,
        project: {
            id,
            code: `P-${id}`,
            name: `Proyecto ${id}`,
            color: '#0171FF',
            status: 'active',
        },
        ...overrides,
    });
}

const items: ChatConversationItem[] = [
    item(1, { type: 'team', title: 'Daily', icon: '🔹', unread: 2 }),
    item(5, {
        type: 'team',
        title: 'Innovación',
        icon: '🔺',
        is_participant: false,
    }),
    item(2, {
        type: 'client',
        title: 'Naranjas',
        icon: '🍊',
        client: naranjas,
        unread: 1,
    }),
    project(3, naranjas, { unread: 4 }),
    project(30, naranjas, {
        read_only: true,
        project: {
            id: 30,
            code: 'P-30',
            name: 'Proyecto 30',
            color: '#0171FF',
            status: 'archived',
        },
    }),
    project(4, hn),
    project(6, null, { title: 'General' }),
    project(40, old),
    item(10, { title: 'Luis Gil' }),
    item(11, { type: 'group', title: 'Diseño web' }),
];

beforeEach(() => {
    window.localStorage.clear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('buildChatTree', () => {
    it('reparte en canales, clientes con su canal y proyectos, y directos', () => {
        const tree = buildChatTree(items);

        expect(tree.channels.map((i) => i.id)).toEqual([1, 5]);
        expect(tree.direct.map((i) => i.id)).toEqual([10, 11]);
        // Por última actividad; el interno (sin cliente) y el desactivado, al final.
        expect(tree.clients.map((node) => node.key)).toEqual([
            'client:7',
            'client:8',
            'internal',
            'client:9',
        ]);
        const naranjasNode = tree.clients[0];
        expect(naranjasNode.channel?.id).toBe(2);
        // El archivado, al final de su cliente.
        expect(naranjasNode.projects.map((i) => i.id)).toEqual([3, 30]);
        expect(tree.clients[1].channel).toBeNull();
    });

    it('«Ocultar archivados» esconde proyectos, canales y clientes desactivados y los cuenta', () => {
        const tree = buildChatTree(items, { showArchived: false });

        expect(tree.clients.map((node) => node.key)).toEqual([
            'client:7',
            'client:8',
            'internal',
        ]);
        expect(tree.clients[0].projects.map((i) => i.id)).toEqual([3]);
        expect(tree.hiddenArchived).toBe(2);
    });

    it('«Solo los míos» deja los canales en los que participa y los clientes con algo propio', () => {
        const tree = buildChatTree(
            [
                ...items,
                item(50, {
                    type: 'client',
                    title: 'Ajeno',
                    client: {
                        id: 50,
                        name: 'Ajeno',
                        icon: null,
                        is_active: true,
                    },
                    is_participant: false,
                }),
            ],
            { mineOnly: true },
        );

        expect(tree.channels.map((i) => i.id)).toEqual([1]);
        expect(tree.clients.map((node) => node.key)).not.toContain('client:50');
        expect(tree.clients.map((node) => node.key)).toContain('client:7');
    });

    it('busca sin tildes por el nombre del cliente (con todo lo suyo) o del proyecto', () => {
        expect(
            buildChatTree(items, { query: 'naranjas' }).clients[0].projects.map(
                (i) => i.id,
            ),
        ).toEqual([3, 30]);

        const byProject = buildChatTree(items, { query: 'p-3' });
        expect(byProject.clients.map((node) => node.key)).toEqual(['client:7']);
        // La cabecera del cliente se mantiene aunque solo coincida un proyecto.
        expect(byProject.clients[0].channel?.id).toBe(2);
        expect(byProject.channels).toEqual([]);

        expect(
            buildChatTree(items, { query: 'innovacion' }).channels[0].id,
        ).toBe(5);
    });
});

describe('preferencias de la lista', () => {
    it('se leen por persona y, si el almacenamiento falla, valen las de por defecto', () => {
        window.localStorage.setItem(
            chatListPrefsKey(3),
            JSON.stringify({
                collapsed: { channels: true },
                mineOnly: true,
                hideArchived: 'no',
            }),
        );

        expect(readChatListPrefs(chatListPrefsKey(3))).toEqual({
            collapsed: { channels: true, clients: false, direct: false },
            mineOnly: true,
            hideArchived: false,
        });
        expect(readChatListPrefs(chatListPrefsKey(4))).toEqual(
            DEFAULT_CHAT_LIST_PREFS,
        );

        // Navegador que bloquea el almacenamiento: el propio acceso falla.
        const storage = Object.getOwnPropertyDescriptor(window, 'localStorage');
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            get: () => {
                throw new Error('bloqueado');
            },
        });
        try {
            expect(readChatListPrefs(chatListPrefsKey(3))).toEqual(
                DEFAULT_CHAT_LIST_PREFS,
            );
        } finally {
            if (storage) {
                Object.defineProperty(window, 'localStorage', storage);
            }
        }
    });
});

describe('ConversationList en tres niveles', () => {
    it('pinta los tres niveles plegables con su recuento y sus no leídos', () => {
        render(<ConversationList items={items} activeId={3} />);

        const channels = screen.getByTestId('chat-section-toggle-channels');
        expect(channels.getAttribute('aria-expanded')).toBe('true');
        expect(channels.textContent).toContain('Canales');
        expect(channels.textContent).toContain('2 sin leer');
        expect(
            screen.getByTestId('chat-section-toggle-clients').textContent,
        ).toContain('5 sin leer');
        expect(
            screen.getByTestId('chat-section-toggle-direct').textContent,
        ).not.toContain('sin leer');

        // El canal del cliente encabeza sus proyectos; sin canal, un enlace lo abre (y lo crea).
        const groups = screen.getAllByTestId('chat-client-group');
        expect(
            within(groups[0]).getAllByRole('link')[0].getAttribute('href'),
        ).toBe('/chat/2');
        expect(
            within(groups[0]).getByRole('list', {
                name: 'Proyectos de Naranjas',
            }),
        ).toBeTruthy();
        expect(
            within(groups[1])
                .getByTestId('chat-client-open')
                .getAttribute('href'),
        ).toBe('/chat/clientes/8');
        expect(within(groups[2]).getByText('Proyectos internos')).toBeTruthy();

        // El emoji del canal, sin que el lector de pantalla lo lea como nombre.
        const daily = screen
            .getAllByTestId('chat-conversation-item')
            .find((link) => link.getAttribute('href') === '/chat/1');
        expect(daily?.textContent).toContain('Canal de equipo');
        expect(
            daily
                ?.querySelector('[data-test="chat-conversation-icon"]')
                ?.getAttribute('aria-hidden'),
        ).toBe('true');
    });

    it('plegar un nivel se recuerda por persona en este navegador', async () => {
        const user = userEvent.setup();
        page.props.auth = { user: { id: 12, name: 'Ana', roles: [] } };

        try {
            const { unmount } = render(
                <ConversationList items={items} activeId={null} />,
            );
            await user.click(screen.getByTestId('chat-section-toggle-direct'));

            expect(
                screen
                    .getByTestId('chat-section-toggle-direct')
                    .getAttribute('aria-expanded'),
            ).toBe('false');
            expect(screen.queryByText('Luis Gil')).toBeNull();
            unmount();

            render(<ConversationList items={items} activeId={null} />);
            expect(
                screen
                    .getByTestId('chat-section-toggle-direct')
                    .getAttribute('aria-expanded'),
            ).toBe('false');
            expect(
                JSON.parse(
                    window.localStorage.getItem('audax.chat.list.12') ?? '{}',
                ).collapsed.direct,
            ).toBe(true);
        } finally {
            delete page.props.auth;
        }
    });

    it('«Solo los míos» y «Ocultar archivados» filtran la lista', async () => {
        const user = userEvent.setup();
        render(<ConversationList items={items} activeId={null} />);

        await user.click(screen.getByTestId('chat-filter-mine'));
        expect(
            screen.getByTestId('chat-filter-mine').getAttribute('aria-pressed'),
        ).toBe('true');
        expect(screen.queryByText('Innovación')).toBeNull();

        await user.click(screen.getByTestId('chat-filter-archived'));
        expect(screen.queryByText('Proyecto 30')).toBeNull();
        expect(
            screen.getByTestId('chat-filter-archived').textContent,
        ).toContain('2 ocultos');
    });

    it('el admin crea un canal de equipo desde «Nuevo»', async () => {
        const user = userEvent.setup();
        page.props.auth = { user: { id: 1, name: 'Admin', roles: ['admin'] } };

        try {
            render(<ConversationList items={items} activeId={null} />);
            await user.click(screen.getByTestId('chat-new'));
            await user.click(
                await screen.findByRole('menuitem', {
                    name: 'Canal de equipo',
                }),
            );

            const dialog = await screen.findByRole('dialog', {
                name: 'Nuevo canal de equipo',
            });
            await user.click(
                within(dialog).getByRole('button', { name: 'Crear canal' }),
            );
            expect(
                within(dialog).getByText('Ponle un nombre al canal.'),
            ).toBeTruthy();
        } finally {
            delete page.props.auth;
        }
    });

    it('a quien no es admin no le ofrece crear canales', async () => {
        const user = userEvent.setup();
        render(<ConversationList items={items} activeId={null} />);

        await user.click(screen.getByTestId('chat-new'));
        await screen.findByRole('menuitem', { name: 'Grupo' });
        expect(
            screen.queryByRole('menuitem', { name: 'Canal de equipo' }),
        ).toBeNull();
    });
});
