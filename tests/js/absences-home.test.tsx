// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { MyAbsencesSummary } from '@/components/absences/types';
import type { MyIndicators } from '@/components/reports/r1-types';
import { TooltipProvider } from '@/components/ui/tooltip';
import Home from '@/pages/home';

/*
| Inicio: la tarjeta «Mis ausencias» (Fase 3, ya en marcha) sale antes de las tarjetas «Llega en la
| Fase N» de funciones que aún no existen. En el móvil las tarjetas van en una columna: así la que
| tiene contenido no queda debajo de los marcadores.
*/

const page = vi.hoisted(() => ({
    url: '/',
    props: {
        auth: { user: { id: 7, name: 'Elena Ruiz' }, can: {} },
        timer: null,
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    // «Mi carga» (Fase 3) llega en una prop diferida: aquí se pinta su estado de carga.
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

// El diálogo de imputar (cerrado) no interviene en el orden de las tarjetas.
vi.mock('@/components/time/time-entry-dialog', () => ({
    TimeEntryDialog: () => null,
}));

const ABSENCES: MyAbsencesSummary = {
    upcoming: [
        {
            id: 1,
            type: 'vacation',
            status: 'approved',
            start_date: '2026-10-05',
            end_date: '2026-10-09',
            partial_minutes: null,
        },
    ],
    pending: [],
};

const INDICATORS: MyIndicators = {
    from: '2026-09-01',
    to: '2026-09-30',
    capacity_minutes: 9600,
    capacity_to_date_minutes: 6720,
    logged_minutes: 6000,
    billable_minutes: 5400,
    occupancy: 0.625,
    billability: 0.9,
    estimation: {
        tasks: 0,
        estimated_minutes: 0,
        actual_minutes: 0,
        accuracy: null,
        deviation: null,
    },
    clients: [],
    projects: [],
};

describe('Inicio', () => {
    it('pinta «Mis ausencias» antes de las tarjetas de fases futuras', () => {
        render(
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
                        iso: '2026-W39',
                        period: {
                            id: null,
                            user_id: 7,
                            week: '2026-W39',
                            week_start: '2026-09-21',
                            week_end: '2026-09-27',
                            status: 'open',
                            submitted_at: null,
                            reviewed_at: null,
                            review_comment: null,
                            auto_approved: false,
                        },
                    }}
                    unlogged_days={[]}
                    indicators={INDICATORS}
                    absences={ABSENCES}
                />
            </TooltipProvider>,
        );

        const cards = Array.from(
            document.querySelectorAll('[data-test^="home-card-"]'),
        ).map((card) => card.getAttribute('data-test'));
        const absences = cards.indexOf('home-card-absences');

        expect(absences).toBeGreaterThan(-1);
        // «Mi carga», «Indicadores» e «Hitos» ya funcionan (Fases 2 a 4); detrás, las de fases futuras.
        for (const later of ['home-card-mentions']) {
            expect(cards.indexOf(later)).toBeGreaterThan(absences);
        }
        // Y las tarjetas que ya funcionan siguen delante.
        expect(cards.indexOf('home-card-today-tasks')).toBeLessThan(absences);
        expect(
            screen.getByRole('heading', { name: 'Mis ausencias' }),
        ).toBeTruthy();
    });
});
