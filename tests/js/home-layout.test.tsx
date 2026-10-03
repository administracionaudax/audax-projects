// @vitest-environment jsdom
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    HOME_CARD_IDS,
    orderCards,
    sameOrder,
} from '@/components/home/home-layout';
import { TooltipProvider } from '@/components/ui/tooltip';
import Home from '@/pages/home';
import fixture from '../fixtures/home-cards.json';

/*
| Orden de las tarjetas de Inicio (D-138): se ordenan por el orden guardado, se arrastran por su
| asa (también con el teclado, con anuncios en español) y se guardan sin recargar la página.
*/

const page = vi.hoisted(() => ({
    url: '/',
    props: {
        auth: { user: { id: 7, name: 'Elena Ruiz' }, can: {} },
        timer: null,
    } as Record<string, unknown>,
}));

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));

vi.mock('sonner', async (importOriginal) => ({
    ...(await importOriginal<typeof import('sonner')>()),
    toast: notify,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    Deferred: ({ fallback }: { fallback?: ReactNode }) => fallback ?? null,
    usePage: () => page,
    router: { get: vi.fn(), post: vi.fn(), on: () => () => {} },
    Link: ({
        href,
        children,
        preserveScroll: _preserveScroll,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        preserveScroll?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

vi.mock('@/components/time/time-entry-dialog', () => ({
    TimeEntryDialog: () => null,
}));

type HomeProps = ComponentProps<typeof Home>;

function renderHome(props: Partial<HomeProps> = {}) {
    return render(
        <TooltipProvider>
            <Home
                tasks={{ overdue: [], today: [], week: [] }}
                hours={{
                    today: 0,
                    week: 0,
                    capacity_today: 480,
                    capacity_week: 2400,
                }}
                week={{
                    iso: '2026-W40',
                    period: {
                        id: null,
                        user_id: 7,
                        week: '2026-W40',
                        week_start: '2026-09-28',
                        week_end: '2026-10-04',
                        status: 'open',
                        submitted_at: null,
                        reviewed_at: null,
                        review_comment: null,
                        auto_approved: false,
                    },
                }}
                unlogged_days={[]}
                milestones={[]}
                {...props}
            />
        </TooltipProvider>,
    );
}

/** Ids de las tarjetas en el orden en que se pintan. */
function cardOrder(): string[] {
    return Array.from(
        document.querySelectorAll<HTMLElement>('[data-home-card]'),
    ).map((card) => card.dataset.homeCard ?? '');
}

/** jsdom no maqueta: cada tarjeta ocupa una fila de 200 px, en una sola columna. */
function mockGridGeometry() {
    return vi
        .spyOn(Element.prototype, 'getBoundingClientRect')
        .mockImplementation(function (this: Element) {
            const card = (this as HTMLElement).closest<HTMLElement>(
                '[data-home-card]',
            );
            const index = card
                ? Array.from(
                      document.querySelectorAll('[data-home-card]'),
                  ).indexOf(card)
                : -1;
            const y = index === -1 ? 0 : index * 200;
            const height = index === -1 ? 0 : 180;

            return {
                x: 0,
                y,
                left: 0,
                top: y,
                width: index === -1 ? 0 : 300,
                height,
                right: index === -1 ? 0 : 300,
                bottom: y + height,
                toJSON: () => ({}),
            } as DOMRect;
        });
}

async function tick() {
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 20));
    });
}

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
    fetchMock.mockReset();
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);
    notify.success.mockReset();
    notify.error.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('orderCards', () => {
    const available = ['today-tasks', 'timer', 'week-hours', 'mentions'];

    it('sin orden guardado, deja el orden por defecto', () => {
        expect(orderCards(available, null)).toEqual(available);
        expect(orderCards(available, [])).toEqual(available);
    });

    it('pone primero las guardadas, en su orden', () => {
        expect(
            orderCards(available, [
                'mentions',
                'timer',
                'today-tasks',
                'week-hours',
            ]),
        ).toEqual(['mentions', 'timer', 'today-tasks', 'week-hours']);
    });

    it('ignora las guardadas que no se pintan y las repetidas', () => {
        expect(
            orderCards(available, ['old-card', 'timer', 'workload', 'timer']),
        ).toEqual(['timer', 'today-tasks', 'week-hours', 'mentions']);
    });

    it('añade al final, en su orden por defecto, las que faltan', () => {
        expect(orderCards(available, ['mentions'])).toEqual([
            'mentions',
            'today-tasks',
            'timer',
            'week-hours',
        ]);
    });

    it('compara órdenes', () => {
        expect(sameOrder(['a', 'b'], ['a', 'b'])).toBe(true);
        expect(sameOrder(['a', 'b'], ['b', 'a'])).toBe(false);
        expect(sameOrder(['a'], ['a', 'b'])).toBe(false);
    });

    it('la lista de tarjetas es la del contrato con el servidor', () => {
        expect([...HOME_CARD_IDS]).toEqual(fixture.cards);
    });
});

describe('Inicio: orden de las tarjetas', () => {
    it('pinta las tarjetas en el orden guardado y deja al final las que faltan', () => {
        renderHome({ home_layout: ['mentions', 'unlogged-days', 'old-card'] });

        expect(cardOrder()).toEqual([
            'mentions',
            'unlogged-days',
            'today-tasks',
            'timer',
            'week-hours',
            'workload',
            'milestones',
        ]);
    });

    it('cada tarjeta tiene un asa con su nombre accesible', () => {
        renderHome();

        const handle = screen.getByRole('button', {
            name: 'Mover la tarjeta «Temporizador»',
        });

        expect(handle.tagName).toBe('BUTTON');
        expect(handle.getAttribute('aria-roledescription')).toBe(
            'tarjeta que se puede mover',
        );
        expect(
            document.getElementById(
                handle.getAttribute('aria-describedby') ?? '',
            )?.textContent,
        ).toContain('pulsa espacio o Intro en su asa');
        expect(
            screen.getAllByRole('button', { name: /^Mover la tarjeta «/ }),
        ).toHaveLength(7);
    });

    it('«Restablecer orden» solo sale con un orden guardado', () => {
        const { unmount } = renderHome();

        expect(
            screen.queryByRole('button', { name: 'Restablecer orden' }),
        ).toBeNull();
        unmount();

        renderHome({ home_layout: ['timer'] });

        expect(
            screen.getByRole('button', { name: 'Restablecer orden' }),
        ).toBeTruthy();
    });

    it('se mueve con el teclado, lo anuncia en español y lo guarda', async () => {
        mockGridGeometry();
        const user = userEvent.setup();
        renderHome();

        screen
            .getByRole('button', {
                name: 'Mover la tarjeta «Mis tareas de hoy y de esta semana»',
            })
            .focus();
        await user.keyboard(' ');
        await tick();

        await waitFor(() =>
            expect(document.body.textContent).toContain(
                'Has cogido la tarjeta «Mis tareas de hoy y de esta semana». Está en la posición 1 de 7.',
            ),
        );

        await user.keyboard('{ArrowDown}');
        await tick();
        await user.keyboard(' ');

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/inicio/orden');
        expect(init?.method).toBe('PUT');
        expect(JSON.parse(init?.body as string)).toEqual({
            cards: [
                'timer',
                'today-tasks',
                'week-hours',
                'workload',
                'unlogged-days',
                'milestones',
                'mentions',
            ],
        });
        expect(cardOrder().slice(0, 2)).toEqual(['timer', 'today-tasks']);
        await waitFor(() =>
            expect(document.body.textContent).toContain(
                'Has soltado la tarjeta «Mis tareas de hoy y de esta semana» en la posición 2 de 7.',
            ),
        );
        // Ya hay un orden guardado: se puede restablecer.
        expect(
            screen.getByRole('button', { name: 'Restablecer orden' }),
        ).toBeTruthy();
    });

    it('Escape cancela el movimiento sin guardar nada', async () => {
        mockGridGeometry();
        const user = userEvent.setup();
        renderHome();

        screen
            .getByRole('button', { name: 'Mover la tarjeta «Temporizador»' })
            .focus();
        await user.keyboard(' ');
        await tick();
        await user.keyboard('{ArrowDown}');
        await tick();
        await user.keyboard('{Escape}');

        await waitFor(() =>
            expect(document.body.textContent).toContain(
                'Movimiento cancelado. La tarjeta «Temporizador» vuelve a su sitio.',
            ),
        );
        expect(fetchMock).not.toHaveBeenCalled();
        expect(cardOrder().slice(0, 2)).toEqual(['today-tasks', 'timer']);
    });

    it('si no se guarda, vuelve al orden anterior con un aviso', async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 500 }));
        mockGridGeometry();
        const user = userEvent.setup();
        renderHome();

        screen
            .getByRole('button', { name: 'Mover la tarjeta «Temporizador»' })
            .focus();
        await user.keyboard(' ');
        await tick();
        await user.keyboard('{ArrowDown}');
        await tick();
        await user.keyboard(' ');

        await waitFor(() =>
            expect(notify.error).toHaveBeenCalledWith(
                'No se ha podido guardar el orden de las tarjetas. Vuelven a como estaban.',
            ),
        );
        expect(cardOrder().slice(0, 3)).toEqual([
            'today-tasks',
            'timer',
            'week-hours',
        ]);
        expect(
            screen.queryByRole('button', { name: 'Restablecer orden' }),
        ).toBeNull();
    });

    it('restablece el orden por defecto y lleva el foco al título', async () => {
        const user = userEvent.setup();
        renderHome({ home_layout: ['mentions', 'timer'] });

        expect(cardOrder()[0]).toBe('mentions');

        await user.click(
            screen.getByRole('button', { name: 'Restablecer orden' }),
        );

        expect(cardOrder()[0]).toBe('today-tasks');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls[0][1]?.method).toBe('DELETE');
        await waitFor(() => expect(notify.success).toHaveBeenCalled());
        expect(
            screen.queryByRole('button', { name: 'Restablecer orden' }),
        ).toBeNull();
        expect(document.activeElement?.tagName).toBe('H1');
    });
});
