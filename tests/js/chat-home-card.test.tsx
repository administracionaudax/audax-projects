// @vitest-environment jsdom
import { configure, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    // Sin sesión: los contadores de C2 no arrancan y manda el total de la tarjeta.
    usePage: () => ({ url: '/', props: {} }),
    Link: ({
        href,
        children,
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

import { HomeChatCard } from '@/components/chat/home-chat-card';
import type { HomeChatSummary } from '@/types/chat';

configure({ testIdAttribute: 'data-test' });

const summary: HomeChatSummary = {
    unread_total: 6,
    conversations: [
        {
            id: 3,
            type: 'project',
            title: 'Web corporativa',
            unread: 5,
            url: '/chat/3',
        },
        { id: 8, type: 'direct', title: 'Luis Gil', unread: 1, url: '/chat/8' },
    ],
    mentions: [
        {
            id: 41,
            conversation_id: 3,
            conversation: 'Web corporativa',
            author: 'Eva Sanz',
            excerpt: 'Reunión a las 12',
            everyone: true,
            unread: true,
            created_at: new Date().toISOString(),
            url: '/chat/3?mensaje=41',
        },
        {
            id: 40,
            conversation_id: 3,
            conversation: 'Web corporativa',
            author: 'Luis Gil',
            excerpt: 'Revisa la maqueta, @Ana Pérez',
            everyone: false,
            unread: false,
            created_at: new Date().toISOString(),
            url: '/chat/3?mensaje=40',
        },
    ],
};

afterEach(() => {
    vi.restoreAllMocks();
});

describe('HomeChatCard', () => {
    it('enseña las menciones, las conversaciones sin leer y el enlace al chat con el total', () => {
        render(<HomeChatCard summary={summary} />);

        const mentions = screen.getAllByTestId('home-chat-mention');
        expect(mentions).toHaveLength(2);
        expect(mentions[0].textContent).toContain(
            'Eva Sanz ha avisado a todos en «Web corporativa»',
        );
        expect(mentions[0].textContent).toContain('Sin leer');
        expect(within(mentions[1]).getByRole('link').getAttribute('href')).toBe(
            '/chat/3?mensaje=40',
        );
        expect(mentions[1].textContent).toContain(
            'Luis Gil te ha mencionado en «Web corporativa»',
        );
        expect(mentions[1].textContent).not.toContain('Sin leer');

        const rows = screen.getAllByTestId('home-chat-conversation');
        expect(rows.map((row) => row.textContent)).toEqual([
            'Web corporativa5 sin leer',
            'Luis Gil1 sin leer',
        ]);
        expect(
            screen
                .getByRole('link', { name: 'Abrir el chat (6 sin leer)' })
                .getAttribute('href'),
        ).toBe('/chat');
    });

    it('sin nada pendiente lo dice y deja abrir el chat', () => {
        render(
            <HomeChatCard
                summary={{ unread_total: 0, conversations: [], mentions: [] }}
            />,
        );

        expect(
            screen.getByText('No tienes menciones ni mensajes sin leer.'),
        ).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Abrir el chat' }),
        ).toBeTruthy();
    });
});
