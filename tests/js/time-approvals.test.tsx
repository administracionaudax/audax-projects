// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Approvals from '@/pages/time/approvals';
import type { PendingWeek, TimeEntry } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    router: { post: vi.fn(), on: () => () => {} },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const DAYS = [
    '2026-09-21',
    '2026-09-22',
    '2026-09-23',
    '2026-09-24',
    '2026-09-25',
    '2026-09-26',
    '2026-09-27',
];

function pendingWeek(overrides: Partial<PendingWeek> = {}): PendingWeek {
    return {
        period: {
            id: 41,
            user_id: 7,
            user: {
                id: 7,
                name: 'Elena Martín',
                avatar: null,
                department_id: 1,
                is_active: true,
            },
            week: '2026-W39',
            week_start: '2026-09-21',
            week_end: '2026-09-27',
            status: 'submitted',
            submitted_at: '2026-09-25T16:00:00Z',
            reviewed_at: null,
            review_comment: null,
            auto_approved: false,
        },
        department: 'Diseño',
        days: Object.fromEntries(
            DAYS.map((day, index) => [day, index === 0 ? 90 : 0]),
        ),
        total: 90,
        capacity: 2400,
        capacity_days: Object.fromEntries(
            DAYS.map((day, index) => [day, index < 5 ? 480 : 0]),
        ),
        billable: 90,
        overage: 0,
        entries_count: 2,
        ...overrides,
    };
}

function entry(id: number, minutes: number): TimeEntry {
    return {
        id,
        user_id: 7,
        task_id: 12,
        task: { id: 12, title: 'Maquetar la home' },
        project_id: 3,
        project: { id: 3, code: 'ACME-WEB', name: 'Web', color: '#0171FF' },
        hour_bank_id: null,
        date: '2026-09-21',
        minutes,
        overage_minutes: 0,
        in_bank_minutes: minutes,
        started_at: null,
        ended_at: null,
        description: `Entrada ${id}`,
        is_billable: true,
        status: 'submitted',
        approved_at: null,
        created_by: 7,
        logged_on_behalf: false,
    };
}

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('aprobaciones (PERF-01)', () => {
    it('no pide las entradas hasta desplegar el detalle, y entonces las pinta', async () => {
        const user = userEvent.setup();
        fetchMock.mockResolvedValue(
            new Response(
                JSON.stringify({ entries: [entry(1, 60), entry(2, 30)] }),
                {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        );

        render(<Approvals pending={[pendingWeek()]} history={[]} limit={100} />);

        expect(fetchMock).not.toHaveBeenCalled();

        await user.click(
            screen.getByRole('button', { name: 'Ver el detalle (2)' }),
        );

        await waitFor(() =>
            expect(screen.getByText('Entrada 1')).toBeTruthy(),
        );
        expect(screen.getByText('Entrada 2')).toBeTruthy();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toBe(
            '/horas/aprobaciones/41/entradas',
        );

        // Plegar y volver a desplegar no las vuelve a pedir.
        await user.click(
            screen.getByRole('button', { name: 'Ver el detalle (2)' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Ver el detalle (2)' }),
        );
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('si falla, lo explica y deja reintentar; una semana sin horas no pide nada', async () => {
        const user = userEvent.setup();
        fetchMock
            .mockResolvedValueOnce(new Response('', { status: 500 }))
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ entries: [entry(1, 60)] }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );

        render(
            <Approvals
                pending={[
                    pendingWeek(),
                    pendingWeek({
                        period: {
                            ...pendingWeek().period,
                            id: 42,
                            week: '2026-W38',
                        },
                        entries_count: 0,
                        total: 0,
                    }),
                ]}
                history={[]}
                limit={100}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Ver el detalle (2)' }),
        );
        await waitFor(() =>
            expect(
                screen.getByText('No se han podido cargar las entradas.'),
            ).toBeTruthy(),
        );

        await user.click(screen.getByRole('button', { name: 'Reintentar' }));
        await waitFor(() =>
            expect(screen.getByText('Entrada 1')).toBeTruthy(),
        );

        await user.click(
            screen.getByRole('button', { name: 'Ver el detalle (0)' }),
        );
        expect(screen.getByText('Semana enviada sin horas.')).toBeTruthy();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
