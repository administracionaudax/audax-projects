// @vitest-environment jsdom
import {
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
    ChatConversationItem,
    ChatMessage,
    ChatMessagesPage,
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

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => page,
    router: inertia,
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
import { ConversationView } from '@/components/chat/conversation-view';
import { AttachmentList } from '@/components/chat/media/attachment-list';
import type { ChatAttachment } from '@/components/chat/media/types';
import { systemText } from '@/components/chat/system-notice';
import { resetUnreadForTests } from '@/hooks/use-realtime-unread';

/**
 * Revisión global de la Fase 6 (D-121): foco gestionado (WCAG 2.4.3) al cerrar diálogos y
 * popovers abiertos desde un menú, tras borrar, editar, saltar a un fijado y abrir una
 * conversación en el móvil; el editor como combobox; «Responder» sin volver a montar el editor;
 * estados con icono y texto; el nombre accesible de «Nuevo»; la gestión de grupos y la
 * moderación del admin (D-119).
 */

configure({ testIdAttribute: 'data-test' });

const now = new Date();
const ana = { id: 1, name: 'Ana Pérez', avatar: null, is_active: true };
const luis = { id: 2, name: 'Luis Gil', avatar: null, is_active: true };

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
        created_at: new Date(now.getTime() - (60 - id) * 60_000).toISOString(),
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
    type: 'group',
    title: 'Diseño',
    subtitle: null,
    project: null,
    other_user: null,
    participants: [
        { ...ana, last_read_message_id: 50 },
        { ...luis, last_read_message_id: 50 },
    ],
    muted: false,
    is_participant: true,
    last_read_message_id: 50,
    can: {
        post: true,
        moderate: false,
        create_task: false,
        mute: true,
        manage: true,
        leave: true,
    },
    read_only_reason: null,
};

function pageOf(messages: ChatMessage[]): ChatMessagesPage {
    return {
        messages,
        users: [ana, luis],
        has_older: false,
        has_newer: false,
        server_time: now.toISOString(),
    };
}

type Route = (url: URL, init: RequestInit) => unknown;

const BASE_ROUTES: Record<string, Route> = {
    'GET /tiempo-real/no-leidos': () => ({
        total: 0,
        conversations: {},
        muted: [],
    }),
    'POST /tiempo-real/conversaciones/1/viendo': () => ({}),
    'DELETE /tiempo-real/conversaciones/1/viendo': () => ({}),
    'GET /tiempo-real/conversaciones/1/leidos': () => ({ participants: [] }),
    'GET /chat/1/novedades': () => ({
        messages: [],
        updated: [],
        users: [],
        has_more: false,
        pinned: [],
        read_state: [],
        server_time: now.toISOString(),
    }),
};

function mockFetch(routes: Record<string, Route> = {}) {
    const all = { ...BASE_ROUTES, ...routes };

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
            const handler = all[key];

            return new Response(
                JSON.stringify(handler ? handler(url, init) : { message: key }),
                {
                    status: handler ? 200 : 500,
                    headers: { 'Content-Type': 'application/json' },
                },
            );
        });
}

function renderView(
    messages: ChatMessage[],
    props: Partial<Parameters<typeof ConversationView>[0]> = {},
) {
    return render(
        <TooltipProvider>
            <ConversationView
                conversation={conversation}
                initial={pageOf(messages)}
                pinned={[]}
                focus={null}
                {...props}
            />
        </TooltipProvider>,
    );
}

function article(id: number): HTMLElement {
    const element = document.querySelector<HTMLElement>(
        `#mensaje-${id} [data-message-focus]`,
    );

    if (!element) {
        throw new Error(`Sin el mensaje ${id}`);
    }

    return element;
}

function actionsOf(name: string): HTMLElement {
    return screen.getByRole('button', {
        name: `Acciones del mensaje de ${name}`,
    });
}

beforeEach(() => {
    mockFetch();
    inertia.visit.mockReset();
    inertia.reload.mockReset();
});

afterEach(() => {
    resetUnreadForTests();
    vi.restoreAllMocks();
});

describe('el editor como combobox de menciones', () => {
    it('lleva role, aria-expanded, aria-haspopup, aria-controls y aria-activedescendant', async () => {
        const user = userEvent.setup();
        renderView([message(5)]);
        const input = screen.getByRole('combobox', {
            name: 'Escribe un mensaje',
        });

        expect(input.tagName).toBe('TEXTAREA');
        expect(input.getAttribute('aria-haspopup')).toBe('listbox');
        expect(input.getAttribute('aria-expanded')).toBe('false');
        expect(input.getAttribute('aria-autocomplete')).toBe('list');
        const listId = input.getAttribute('aria-controls') ?? '';
        // La lista existe siempre (oculta sin sugerencias): aria-controls nunca apunta al vacío.
        const list = document.getElementById(listId);
        expect(list?.getAttribute('role')).toBe('listbox');
        expect(list?.hidden).toBe(true);
        expect(input.hasAttribute('aria-activedescendant')).toBe(false);

        await user.type(input, '@Lu');

        expect(input.getAttribute('aria-expanded')).toBe('true');
        expect(list?.hidden).toBe(false);
        const active = input.getAttribute('aria-activedescendant') ?? '';
        expect(document.getElementById(active)?.textContent).toContain(
            'Luis Gil',
        );
        expect(
            document.getElementById(active)?.getAttribute('aria-selected'),
        ).toBe('true');

        await user.keyboard('{Escape}');
        expect(input.getAttribute('aria-expanded')).toBe('false');
    });
});

describe('«Responder»', () => {
    it('no vuelve a montar el editor: conserva el borrador y la grabación y le da el foco', async () => {
        const user = userEvent.setup();
        renderView([message(5, { body: 'Pregunta' })]);
        const input = screen.getByRole('combobox', {
            name: 'Escribe un mensaje',
        });
        const recorder = screen.getByTestId(/^chat-recorder/);

        await user.type(input, 'A medio escribir');
        await user.click(actionsOf('Luis Gil'));
        await user.click(
            await screen.findByRole('menuitem', { name: 'Responder' }),
        );

        expect(screen.getByText('Respondiendo a Luis Gil')).toBeTruthy();
        const after = screen.getByRole('combobox', {
            name: 'Escribe un mensaje',
        });
        expect(after).toBe(input);
        expect((after as HTMLTextAreaElement).value).toBe('A medio escribir');
        expect(screen.getByTestId(/^chat-recorder/)).toBe(recorder);
        await waitFor(() => expect(document.activeElement).toBe(input));
    });
});

describe('foco al cerrar lo que se abre desde el menú de un mensaje', () => {
    it('borrar: al confirmar, el foco va al mensaje («Mensaje eliminado»); al cancelar, a su menú', async () => {
        const user = userEvent.setup();
        mockFetch({
            'DELETE /chat/mensajes/5': () => ({
                message: message(5, {
                    author: ana,
                    body: null,
                    deleted: true,
                }),
                users: [],
            }),
        });
        renderView([
            message(5, { author: ana, can: { ...can, delete: true } }),
        ]);

        await user.click(actionsOf('Ana Pérez'));
        await user.click(
            await screen.findByRole('menuitem', { name: 'Eliminar' }),
        );
        await user.click(
            within(await screen.findByRole('dialog')).getByRole('button', {
                name: 'Cancelar',
            }),
        );
        await waitFor(() =>
            expect(document.activeElement).toBe(actionsOf('Ana Pérez')),
        );

        await user.click(actionsOf('Ana Pérez'));
        await user.click(
            await screen.findByRole('menuitem', { name: 'Eliminar' }),
        );
        await user.click(await screen.findByTestId('chat-delete-confirm'));

        expect(await screen.findByText('Mensaje eliminado')).toBeTruthy();
        await waitFor(() => expect(document.activeElement).toBe(article(5)));
    });

    it('editar: al guardar, el foco va al mensaje; al cancelar con Esc, a su menú; con ↑, vuelve al editor', async () => {
        const user = userEvent.setup();
        mockFetch({
            'PATCH /chat/mensajes/5': (_url, init) => ({
                message: message(5, {
                    author: ana,
                    body: (JSON.parse(init.body as string) as { body: string })
                        .body,
                    edited_at: new Date().toISOString(),
                    can: { ...can, edit: true },
                }),
                users: [],
            }),
        });
        renderView([
            message(5, {
                author: ana,
                body: 'Primera',
                can: { ...can, edit: true },
            }),
        ]);

        await user.click(actionsOf('Ana Pérez'));
        await user.click(
            await screen.findByRole('menuitem', { name: 'Editar' }),
        );
        await user.keyboard('{Escape}');
        await waitFor(() =>
            expect(document.activeElement).toBe(actionsOf('Ana Pérez')),
        );

        await user.click(actionsOf('Ana Pérez'));
        await user.click(
            await screen.findByRole('menuitem', { name: 'Editar' }),
        );
        const editor = screen.getByRole('combobox', {
            name: 'Edita el mensaje',
        });
        await user.clear(editor);
        await user.type(editor, 'Segunda{Enter}');
        expect(await screen.findByText('Segunda')).toBeTruthy();
        await waitFor(() => expect(document.activeElement).toBe(article(5)));

        // Con ↑ desde el editor vacío, al terminar vuelve al editor.
        const input = screen.getByRole('combobox', {
            name: 'Escribe un mensaje',
        });
        await user.click(input);
        await user.keyboard('{ArrowUp}');
        await user.keyboard('{Escape}');
        await waitFor(() => expect(document.activeElement).toBe(input));
    });

    it('reaccionar desde el menú: al cerrar el selector, el foco vuelve al botón del menú', async () => {
        const user = userEvent.setup();
        renderView([message(5)]);

        await user.click(actionsOf('Luis Gil'));
        await user.click(
            await screen.findByRole('menuitem', { name: 'Reaccionar' }),
        );
        expect(
            await screen.findByRole('group', { name: /Reacciones rápidas/ }),
        ).toBeTruthy();

        await user.keyboard('{Escape}');
        await waitFor(() =>
            expect(document.activeElement).toBe(actionsOf('Luis Gil')),
        );
    });

    it('crear tarea: al cerrar el diálogo, el foco vuelve al menú del mensaje', async () => {
        const user = userEvent.setup();
        mockFetch({
            'GET /chat/mensajes/5/tarea': () => ({
                title: 'Mensaje 5',
                project: {
                    id: 4,
                    code: 'ARR-WEB',
                    name: 'Web',
                    color: '#0171FF',
                    status: 'active',
                    uses_hour_banks: false,
                },
                banks: [],
                people: [],
            }),
        });
        renderView([message(5, { can: { ...can, create_task: true } })], {
            conversation: {
                ...conversation,
                type: 'project',
                can: { ...conversation.can, create_task: true },
            },
        });

        await user.click(actionsOf('Luis Gil'));
        await user.click(
            await screen.findByRole('menuitem', { name: 'Crear tarea' }),
        );
        expect(
            await screen.findByRole('dialog', {
                name: 'Crear tarea desde el mensaje',
            }),
        ).toBeTruthy();

        await user.keyboard('{Escape}');
        await waitFor(() =>
            expect(document.activeElement).toBe(actionsOf('Luis Gil')),
        );
    });
});

describe('saltar a un mensaje fijado', () => {
    it('lleva el foco al mensaje (no se pierde al plegar la barra)', async () => {
        const user = userEvent.setup();
        Element.prototype.scrollIntoView = vi.fn();
        renderView([message(5, { pinned: true }), message(6)], {
            pinned: [
                {
                    id: 5,
                    type: 'text',
                    excerpt: 'Mensaje 5',
                    author: 'Luis Gil',
                    system: null,
                    hidden: false,
                    pinned_at: now.toISOString(),
                    pinned_by: 'Ana Pérez',
                },
            ],
        });

        await user.click(
            screen.getByRole('button', { name: /1 mensaje fijado/ }),
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Ir al mensaje fijado: Mensaje 5',
            }),
        );

        await waitFor(() => expect(document.activeElement).toBe(article(5)));
    });
});

describe('abrir una conversación en el móvil', () => {
    it('el foco va a su título (la lista ya no está)', async () => {
        const original = window.matchMedia;
        window.matchMedia = ((query: string) => ({
            matches: query.includes('max-width'),
            media: query,
            onchange: null,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            addListener: () => undefined,
            removeListener: () => undefined,
            dispatchEvent: () => false,
        })) as typeof window.matchMedia;
        vi.resetModules();

        try {
            const { ConversationView: MobileView } =
                await import('@/components/chat/conversation-view');
            render(
                <TooltipProvider>
                    <MobileView
                        conversation={conversation}
                        initial={pageOf([message(5)])}
                        pinned={[]}
                        focus={null}
                    />
                </TooltipProvider>,
            );

            await waitFor(() =>
                expect(document.activeElement).toBe(
                    screen.getByTestId('chat-conversation-title'),
                ),
            );
        } finally {
            window.matchMedia = original;
            vi.resetModules();
        }
    });
});

describe('visor de imágenes', () => {
    it('al cerrarlo, el foco vuelve a la miniatura de la imagen que se estaba viendo', async () => {
        const user = userEvent.setup();
        const image = (id: number): ChatAttachment => ({
            id,
            kind: 'image',
            is_image: true,
            original_name: `foto-${id}.png`,
            mime: 'image/png',
            size: 1000,
            url: `/adjuntos/${id}`,
            thumbnail_url: null,
        });
        render(<AttachmentList attachments={[image(1), image(2), image(3)]} />);

        await user.click(
            screen.getByRole('button', { name: 'Ver la imagen «foto-1.png»' }),
        );
        await user.keyboard('{ArrowRight}');
        expect(await screen.findByText('Imagen 2 de 3')).toBeTruthy();
        await user.keyboard('{Escape}');

        await waitFor(() =>
            expect(document.activeElement).toBe(
                screen.getByRole('button', {
                    name: 'Ver la imagen «foto-2.png»',
                }),
            ),
        );
    });
});

function listItem(
    id: number,
    overrides: Partial<ChatConversationItem> = {},
): ChatConversationItem {
    return {
        id,
        type: 'group',
        title: `Grupo ${id}`,
        subtitle: null,
        project: null,
        other_user: null,
        members_count: 3,
        muted: false,
        unread: 0,
        read_only: false,
        last_message: null,
        last_activity_at: now.toISOString(),
        ...overrides,
    };
}

describe('lista de conversaciones', () => {
    it('«silenciada» y «solo lectura» se ven con icono y texto, y «Nuevo» se llama como se ve', () => {
        render(
            <ConversationList
                items={[
                    listItem(1, { muted: true }),
                    listItem(2, { type: 'project', read_only: true }),
                ]}
                activeId={null}
            />,
        );

        const muted = screen.getByTestId('chat-item-muted');
        expect(muted.textContent).toBe('Silenciada');
        expect(muted.querySelector('svg[aria-hidden="true"]')).not.toBeNull();
        const readOnly = screen.getByTestId('chat-item-read-only');
        expect(readOnly.textContent).toBe('Solo lectura');
        expect(
            readOnly.querySelector('svg[aria-hidden="true"]'),
        ).not.toBeNull();

        // WCAG 2.5.3: el nombre accesible empieza por el texto visible.
        const button = screen.getByTestId('chat-new');
        expect(button.textContent?.trim()).toBe('Nuevo');
        expect(button.getAttribute('aria-label')?.startsWith('Nuevo')).toBe(
            true,
        );
    });

    it('al cerrar «Nuevo mensaje directo» o «Nuevo grupo», el foco vuelve a «Nuevo»', async () => {
        const user = userEvent.setup();
        mockFetch({ 'GET /chat/personas': () => ({ people: [] }) });
        render(<ConversationList items={[listItem(1)]} activeId={null} />);
        const button = screen.getByTestId('chat-new');

        await user.click(button);
        await user.click(
            await screen.findByRole('menuitem', { name: 'Mensaje directo' }),
        );
        expect(
            await screen.findByRole('dialog', {
                name: 'Nuevo mensaje directo',
            }),
        ).toBeTruthy();
        await user.keyboard('{Escape}');
        await waitFor(() => expect(document.activeElement).toBe(button));

        await user.click(button);
        await user.click(
            await screen.findByRole('menuitem', { name: 'Grupo' }),
        );
        expect(
            await screen.findByRole('dialog', { name: 'Nuevo grupo' }),
        ).toBeTruthy();
        await user.keyboard('{Escape}');
        await waitFor(() => expect(document.activeElement).toBe(button));
    });

    it('solo el admin ve «Moderar conversaciones», que lista lo que modera y lo abre', async () => {
        const user = userEvent.setup();
        const { unmount } = render(
            <ConversationList items={[listItem(1)]} activeId={null} />,
        );
        expect(screen.queryByTestId('chat-moderation')).toBeNull();
        unmount();

        const auth = page.props.auth as { user: { roles: string[] } };
        auth.user.roles = ['admin'];
        mockFetch({
            'GET /chat/moderar': () => ({
                conversations: [
                    {
                        id: 40,
                        type: 'project',
                        title: 'Web corporativa',
                        subtitle: 'ARR-WEB',
                        members_count: 5,
                        read_only: false,
                        last_activity_at: now.toISOString(),
                    },
                ],
            }),
        });

        try {
            render(<ConversationList items={[listItem(1)]} activeId={null} />);
            const open = screen.getByTestId('chat-moderation');
            expect(open.getAttribute('aria-label')).toBe(
                'Moderar conversaciones',
            );

            await user.click(open);
            const option = await screen.findByTestId('chat-moderation-item');
            expect(option.textContent).toContain('Web corporativa');
            expect(option.textContent).toContain('ARR-WEB');
            await user.click(option);

            expect(inertia.visit).toHaveBeenCalledWith(
                '/chat/40',
                expect.objectContaining({ preserveState: true }),
            );
        } finally {
            auth.user.roles = ['employee'];
        }
    });
});

describe('gestión del grupo (D-119)', () => {
    it('renombra, quita personas y sale, y al cerrar el foco vuelve a su botón', async () => {
        const user = userEvent.setup();
        const calls: string[] = [];
        mockFetch({
            'PATCH /chat/1/grupo': (_url, init) => {
                calls.push(`renombrar ${init.body as string}`);

                return { conversation };
            },
            'DELETE /chat/1/participantes/2': () => {
                calls.push('quitar 2');

                return { conversation };
            },
            'POST /chat/1/salir': () => {
                calls.push('salir');

                return { left: true, url: '/chat' };
            },
            'GET /chat/personas': () => ({ people: [] }),
        });
        renderView([message(5)]);

        const open = screen.getByTestId('chat-group-settings-open');
        await user.click(open);
        const dialog = await screen.findByRole('dialog', { name: 'Grupo' });

        const name = within(dialog).getByLabelText('Nombre del grupo');
        await user.clear(name);
        await user.type(name, 'Diseño web');
        await user.click(
            within(dialog).getByRole('button', { name: 'Guardar nombre' }),
        );
        await waitFor(() =>
            expect(calls).toContain('renombrar {"name":"Diseño web"}'),
        );
        expect(inertia.reload).toHaveBeenCalledWith({
            only: ['conversation', 'conversations'],
        });

        // «Quitar» se llama como se ve («Quitar…»), y nunca a una misma.
        expect(
            within(dialog).queryByRole('button', {
                name: 'Quitar a Ana Pérez del grupo',
            }),
        ).toBeNull();
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Quitar a Luis Gil del grupo',
            }),
        );
        await waitFor(() => expect(calls).toContain('quitar 2'));

        await user.click(within(dialog).getByTestId('chat-group-leave'));
        await user.click(
            within(dialog).getByTestId('chat-group-leave-confirm'),
        );
        await waitFor(() => expect(calls).toContain('salir'));
        expect(inertia.visit).toHaveBeenCalledWith('/chat');
        await waitFor(() => expect(document.activeElement).toBe(open));
    });

    it('quien no gestiona el grupo solo ve las personas y «Salir»', async () => {
        const user = userEvent.setup();
        renderView([message(5)], {
            conversation: {
                ...conversation,
                can: { ...conversation.can, manage: false },
            },
        });

        await user.click(screen.getByTestId('chat-group-settings-open'));
        const dialog = await screen.findByRole('dialog', { name: 'Grupo' });

        expect(within(dialog).queryByLabelText('Nombre del grupo')).toBeNull();
        expect(
            within(dialog).queryByRole('button', { name: /^Quitar/ }),
        ).toBeNull();
        expect(within(dialog).getByTestId('chat-group-leave')).toBeTruthy();
    });

    it('los cambios del grupo se escriben como mensajes de sistema', () => {
        expect(
            systemText({
                key: 'group.renamed',
                payload: { by: 'Ana', name: 'Diseño web' },
            }),
        ).toBe('Ana ha cambiado el nombre del grupo a «Diseño web».');
        expect(
            systemText({
                key: 'group.added',
                payload: { by: 'Ana', users: ['Eva', 'Luis'] },
            }),
        ).toBe('Ana ha añadido al grupo a Eva, Luis.');
        expect(
            systemText({
                key: 'group.removed',
                payload: { by: 'Ana', user: 'Luis' },
            }),
        ).toBe('Ana ha quitado del grupo a Luis.');
        expect(
            systemText({ key: 'group.left', payload: { user: 'Luis' } }),
        ).toBe('Luis ha salido del grupo.');
    });
});

describe('textos sin cadenas sueltas', () => {
    it('la captura pegada se nombra con el texto del fichero de idioma', async () => {
        const { namePastedFile } =
            await import('@/components/chat/media/media-utils');
        const named = namePastedFile(
            new File(['png'], 'image.png', { type: 'image/png' }),
            new Date(2026, 9, 2, 10, 5, 7),
        );

        expect(named.name).toBe('captura-20261002-100507.png');
    });
});
