// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TimeLocks from '@/pages/time/locks';
import type { TimeLocksPageProps } from '@/types';

const post = vi.fn();

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ props: { errors: {} } }),
    router: { post: (...args: unknown[]) => post(...args), get: vi.fn() },
}));

function props(): TimeLocksPageProps {
    return {
        clients: [
            { id: 1, name: 'Bodegas Arrieta' },
            { id: 2, name: 'Cervezas Montaña' },
        ],
        projects: [
            { id: 5, code: 'ARR-WEB', name: 'Web corporativa', client_id: 1 },
        ],
        filters: {
            client_id: 1,
            project_id: null,
            date_from: '2026-09-01',
            date_to: '2026-09-30',
            reference: null,
        },
        preview: {
            summary: {
                lockable: { count: 3, minutes: 180 },
                pending: {
                    draft: { count: 0, minutes: 0 },
                    submitted: { count: 0, minutes: 0 },
                },
                locked: { count: 0, minutes: 0 },
            },
            entries: [],
            limit: 50,
        },
        locks: [],
    } as unknown as TimeLocksPageProps;
}

describe('bloqueo de horas (revisión de formularios, H-B1)', () => {
    beforeEach(() => post.mockReset());

    it('pide repetir la vista previa si cambian el ámbito o las fechas', async () => {
        const user = userEvent.setup();
        render(<TimeLocks {...props()} />);

        expect(
            screen.getByRole('button', { name: 'Bloquear 3 entradas' }),
        ).toBeTruthy();

        // Cambiar a «por proyecto» deja la vista previa desfasada: no se puede bloquear.
        await user.click(screen.getByRole('radio', { name: /proyecto/i }));
        expect(
            screen.getByText(/vuelve a pulsar «Ver qué se bloquea»/),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: 'Bloquear 3 entradas' }),
        ).toBeNull();
    });

    it('bloquea exactamente lo previsualizado, con la referencia escrita', async () => {
        const user = userEvent.setup();
        render(<TimeLocks {...props()} />);

        await user.type(
            screen.getByRole('textbox', { name: 'Referencia' }),
            'F-1',
        );
        await user.click(
            screen.getByRole('button', { name: 'Bloquear 3 entradas' }),
        );
        const buttons = await screen.findAllByRole('button', {
            name: 'Bloquear 3 entradas',
        });
        await user.click(buttons[buttons.length - 1]);

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toEqual({
            client_id: '1',
            date_from: '2026-09-01',
            date_to: '2026-09-30',
            reference: 'F-1',
        });
    });
});
