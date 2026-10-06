// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { JoinClientsDialog } from '@/components/weeklies/weekly-dialogs';
import { Button } from '@/components/ui/button';

type ReloadOptions = {
    onSuccess?: (page: { props: Record<string, unknown> }) => void;
    onFinish?: () => void;
};

const reload = vi.fn<(options: ReloadOptions) => void>();

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: {
        reload: (options: ReloadOptions) => reload(options),
        post: vi.fn(),
    },
}));

const CLIENTS = [
    { id: 1, name: 'Bodegas Arrieta', icon: null, projects: [] },
    { id: 2, name: 'Cervezas Montaña', icon: null, projects: [] },
];

/** D-310 (H-D1): «Unirme a clientes» no pierde la lista ni dice «no hay clientes» si falla. */
describe('Unirme a clientes', () => {
    beforeEach(() => reload.mockReset());

    it('conserva la lista aunque una recarga en vivo borre la prop opcional', async () => {
        reload.mockImplementation((options) => {
            options?.onSuccess?.({ props: { joinable_clients: CLIENTS } });
            options?.onFinish?.();
        });
        const user = userEvent.setup();
        const { rerender } = render(
            <JoinClientsDialog
                clients={undefined}
                trigger={<Button>Unirme</Button>}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Unirme' }));
        expect(screen.getByText('Bodegas Arrieta')).toBeTruthy();

        // useWeeklyLive recarga la página entera: la prop opcional vuelve a undefined.
        rerender(
            <JoinClientsDialog
                clients={undefined}
                trigger={<Button>Unirme</Button>}
            />,
        );
        expect(screen.getByText('Cervezas Montaña')).toBeTruthy();
    });

    it('si la carga falla, lo dice y deja reintentar', async () => {
        reload.mockImplementation((options) => options?.onFinish?.());
        const user = userEvent.setup();
        render(
            <JoinClientsDialog
                clients={undefined}
                trigger={<Button>Unirme</Button>}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Unirme' }));
        expect(
            screen.getByText('No se ha podido cargar la lista de clientes.'),
        ).toBeTruthy();
        expect(screen.queryByText(/No hay clientes activos/)).toBeNull();

        await user.click(screen.getByRole('button', { name: 'Reintentar' }));
        expect(reload).toHaveBeenCalledTimes(2);
    });
});
