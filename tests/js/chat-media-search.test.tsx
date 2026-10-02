// @vitest-environment jsdom
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: {} }),
    router: { get: inertia.get },
    Link: ({
        href,
        children,
        replace: _replace,
        preserveScroll: _preserveScroll,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        replace?: boolean;
        preserveScroll?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

import ChatSearchPage from '@/components/chat/media/search-page';
import {
    HighlightedText,
    matchRanges,
} from '@/components/chat/media/text-match';
import type {
    ChatSearchPageProps,
    ChatSearchResult,
} from '@/components/chat/media/types';

function result(overrides: Partial<ChatSearchResult>): ChatSearchResult {
    return {
        id: 10,
        conversation_id: 3,
        conversation: {
            type: 'project',
            label: 'Web corporativa',
            code: 'ARR-WEB',
        },
        author: 'Ana',
        created_at: '2026-09-27T08:30:00Z',
        match: 'message',
        excerpt: 'Mañana revisamos el presupuesto de la campaña',
        file_name: null,
        is_audio: false,
        url: '/chat/3?mensaje=10',
        ...overrides,
    };
}

function props(
    overrides: Partial<ChatSearchPageProps> = {},
): ChatSearchPageProps {
    return {
        query: 'campana',
        filters: { type: null, conversation: null },
        conversation: null,
        results: [
            result({}),
            result({
                id: 9,
                match: 'transcription',
                is_audio: true,
                excerpt: '…lo que dijo el cliente sobre la CAMPAÑA de otoño…',
                url: '/chat/3?mensaje=9',
            }),
            result({
                id: 8,
                match: 'file',
                conversation: { type: 'direct', label: 'Luis', code: null },
                excerpt: 'Campaña-otoño.pdf',
                file_name: 'Campaña-otoño.pdf',
                url: '/chat/5?mensaje=8',
            }),
        ],
        next: null,
        minLength: 2,
        ...overrides,
    };
}

describe('matchRanges y HighlightedText', () => {
    it('encuentra sin tildes ni mayúsculas, con las posiciones del texto original', () => {
        expect(matchRanges('La Campaña y la campana', 'campana')).toEqual([
            { start: 3, end: 10 },
            { start: 16, end: 23 },
        ]);
        expect(matchRanges('Reunión en Cádiz', 'CADIZ')).toEqual([
            { start: 11, end: 16 },
        ]);
        expect(matchRanges('texto', '  ')).toEqual([]);
    });

    it('resalta con <mark> sin interpretar HTML', () => {
        const { container } = render(
            <HighlightedText
                text="<b>Presupuesto</b> aprobado"
                query="presupuesto"
            />,
        );

        expect(container.querySelector('mark')?.textContent).toBe(
            'Presupuesto',
        );
        expect(container.querySelector('b')).toBeNull();
        expect(container.textContent).toBe('<b>Presupuesto</b> aprobado');
    });
});

describe('ChatSearchPage', () => {
    beforeEach(() => {
        inertia.get.mockReset();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('lista los resultados con su conversación, autor, fecha, tipo y la palabra resaltada', () => {
        render(<ChatSearchPage {...props()} />);

        const links = within(
            screen.getByRole('region', { name: 'Resultados' }),
        ).getAllByRole('link');
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            '/chat/3?mensaje=10',
            '/chat/3?mensaje=9',
            '/chat/5?mensaje=8',
        ]);
        expect(links[0].textContent).toContain('ARR-WEB · Web corporativa');
        expect(links[0].textContent).toContain('Ana');
        expect(links[0].textContent).toContain('27/09/2026 10:30');
        expect(links[0].textContent).toContain('Mensaje');
        expect(links[0].querySelector('mark')?.textContent).toBe('campaña');
        expect(links[1].textContent).toContain('Audio');
        expect(links[1].querySelector('mark')?.textContent).toBe('CAMPAÑA');
        expect(links[2].textContent).toContain('Archivo');
        expect(links[2].textContent).toContain('Luis');
        expect(screen.getByText('Resultados: 3')).toBeTruthy();
    });

    it('buscar navega a /chat/buscar con la consulta y los filtros', () => {
        render(
            <ChatSearchPage
                {...props({ filters: { type: 'audios', conversation: 3 } })}
            />,
        );

        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Buscar en el chat' }),
            {
                target: { value: '  presupuesto  ' },
            },
        );
        fireEvent.submit(screen.getByRole('search'));

        expect(inertia.get).toHaveBeenCalledWith(
            '/chat/buscar?q=presupuesto&tipo=audios&conversacion=3',
            {},
            expect.objectContaining({ replace: true }),
        );
    });

    it('los filtros por tipo marcan el activo', () => {
        render(
            <ChatSearchPage
                {...props({
                    filters: { type: 'archivos', conversation: null },
                })}
            />,
        );

        const nav = screen.getByRole('navigation', {
            name: 'Tipo de resultado',
        });
        const active = within(nav).getByRole('link', { current: true });
        expect(active.textContent).toBe('Archivos');
        expect(
            within(nav)
                .getByRole('link', { name: 'Todo' })
                .getAttribute('href'),
        ).toBe('/chat/buscar?q=campana');
        expect(
            within(nav)
                .getByRole('link', { name: 'Audios' })
                .getAttribute('href'),
        ).toBe('/chat/buscar?q=campana&tipo=audios');
    });

    it('limitada a una conversación, se puede quitar el filtro', () => {
        render(
            <ChatSearchPage
                {...props({
                    filters: { type: null, conversation: 5 },
                    conversation: { id: 5, type: 'direct', label: 'Luis' },
                })}
            />,
        );

        expect(screen.getByText('En «Luis»')).toBeTruthy();
        expect(
            screen
                .getByRole('link', {
                    name: 'Buscar en todas las conversaciones',
                })
                .getAttribute('href'),
        ).toBe('/chat/buscar?q=campana');
    });

    it('sin consulta explica qué se busca; sin resultados lo dice', () => {
        const { rerender } = render(
            <ChatSearchPage {...props({ query: '', results: [] })} />,
        );

        expect(screen.getByText('¿Qué buscas?')).toBeTruthy();

        rerender(<ChatSearchPage {...props({ query: 'nada', results: [] })} />);
        expect(screen.getByText('No hay resultados para «nada»')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Volver al chat' })
                .getAttribute('href'),
        ).toBe('/chat');
    });

    it('«Ver más resultados» añade la página siguiente', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    results: [result({ id: 4, url: '/chat/3?mensaje=4' })],
                    next: null,
                }),
                { status: 200 },
            ),
        );
        render(<ChatSearchPage {...props({ next: 8 })} />);

        expect(screen.getByText('Resultados: 3 (hay más)')).toBeTruthy();

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Ver más resultados' }),
            );
        });

        expect(fetchMock.mock.calls[0][0]).toBe(
            '/chat/buscar?q=campana&antes=8',
        );
        expect(fetchMock.mock.calls[0][1]).toMatchObject({
            headers: expect.objectContaining({ Accept: 'application/json' }),
        });
        expect(
            within(
                screen.getByRole('region', { name: 'Resultados' }),
            ).getAllByRole('link'),
        ).toHaveLength(4);
        expect(
            screen.queryByRole('button', { name: 'Ver más resultados' }),
        ).toBeNull();
    });

    it('si falla «Ver más», lo dice y se puede reintentar', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response('', { status: 500 }),
        );
        render(<ChatSearchPage {...props({ next: 8 })} />);

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Ver más resultados' }),
            );
        });

        expect(screen.getByRole('alert').textContent).toContain(
            'No se han podido cargar más resultados',
        );
        expect(
            screen.getByRole('button', { name: 'Ver más resultados' }),
        ).toBeTruthy();
    });
});
