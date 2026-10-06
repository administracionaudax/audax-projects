// @vitest-environment jsdom
import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ClientHistoryDialog } from '@/components/weeklies/insights/client-history-dialog';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

function page(body: string) {
    return new Response(
        JSON.stringify({
            reports: [
                {
                    id: body.length,
                    cycle: { id: 4, number: 'W41-26', label: 'Semana 41' },
                    body,
                    submitted_at: '2026-10-05T10:00:00Z',
                    project: null,
                },
            ],
            next_page: null,
        }),
        { status: 200 },
    );
}

/** D-310 (H-D15): el histórico de un cliente no mezcla una respuesta lenta anterior y deja reintentar. */
describe('Ver histórico', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('solo cuenta la respuesta de la última petición', async () => {
        let slow: (response: Response) => void = () => {};
        const fetch = vi
            .fn()
            .mockImplementationOnce(
                () => new Promise<Response>((resolve) => (slow = resolve)),
            )
            .mockImplementationOnce(() =>
                Promise.resolve(page('Del cliente B')),
            );
        vi.stubGlobal('fetch', fetch);
        const person = { id: 3, name: 'Elena' };

        const { rerender } = render(
            <ClientHistoryDialog
                person={person}
                client={{ id: 1, name: 'A' }}
                open
                onOpenChange={() => {}}
            />,
        );
        rerender(
            <ClientHistoryDialog
                person={person}
                client={{ id: 2, name: 'B' }}
                open
                onOpenChange={() => {}}
            />,
        );
        expect(await screen.findByText('Del cliente B')).toBeTruthy();

        // Llega tarde la del cliente A: no pisa a la de B.
        await act(async () => slow(page('Del cliente A')));
        expect(screen.queryByText('Del cliente A')).toBeNull();
        expect(screen.getByText('Del cliente B')).toBeTruthy();
    });

    it('si falla, ofrece reintentar', async () => {
        const fetch = vi
            .fn()
            .mockRejectedValueOnce(new TypeError('Failed to fetch'))
            .mockResolvedValueOnce(page('Ya está'));
        vi.stubGlobal('fetch', fetch);

        render(
            <ClientHistoryDialog
                person={{ id: 3, name: 'Elena' }}
                client={{ id: 1, name: 'A' }}
                open
                onOpenChange={() => {}}
            />,
        );

        const retry = await screen.findByRole('button', { name: 'Reintentar' });
        await act(async () => retry.click());
        expect(await screen.findByText('Ya está')).toBeTruthy();
    });
});
