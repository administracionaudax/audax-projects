// @vitest-environment jsdom
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Sessions from '@/pages/settings/sessions';
import type { ActiveSession } from '@/types';

type VisitOptions = {
    onStart?: () => void;
    onSuccess?: () => void;
    onFinish?: () => void;
};

const server = vi.hoisted(() => ({
    /** Simula la respuesta de Inertia: cambia las props de la página y luego avisa. */
    respond: (_url: string, _options: VisitOptions) => {},
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    router: {
        delete: (url: string, options: VisitOptions) =>
            server.respond(url, options),
    },
}));

const current: ActiveSession = {
    id: 'a',
    ip_address: '10.0.0.1',
    user_agent: null,
    browser: 'Firefox',
    platform: 'Linux',
    is_current: true,
    last_active_at: '2026-09-26T10:00:00Z',
};

const other: ActiveSession = {
    ...current,
    id: 'b',
    browser: 'Safari',
    platform: 'iOS',
    is_current: false,
};

beforeEach(() => {
    server.respond = () => {};
});

describe('sesiones activas', () => {
    it('avisa si el servidor no admite gestionar sesiones y no ofrece cerrarlas (SEC-03)', () => {
        render(<Sessions sessions={[]} supported={false} />);

        expect(screen.getByRole('status').textContent).toContain(
            'No se pueden gestionar las sesiones',
        );
        expect(
            screen.queryByRole('button', { name: 'Cerrar las demás sesiones' }),
        ).toBeNull();
        expect(screen.queryByText('No hay sesiones activas registradas.')).toBe(
            null,
        );
    });

    it('con el driver database lista las sesiones y ofrece cerrar las demás', () => {
        render(<Sessions sessions={[current, other]} supported />);

        expect(screen.queryByRole('status')).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Cerrar las demás sesiones' }),
        ).toBeTruthy();
    });

    it('tras cerrar una sesión el foco va al encabezado, no a <body> (UI-08)', async () => {
        const user = userEvent.setup();
        const view = render(<Sessions sessions={[current, other]} supported />);

        server.respond = (url, options) => {
            expect(url).toContain('/ajustes/sesiones/');
            options.onStart?.();
            // El servidor borra la sesión: la fila (y su disparador) desaparecen.
            view.rerender(<Sessions sessions={[current]} supported />);
            options.onSuccess?.();
            options.onFinish?.();
        };

        await user.click(
            screen.getByRole('button', {
                name: 'Cerrar sesión: Safari en iOS',
            }),
        );
        const dialog = await screen.findByRole('dialog');
        await user.click(
            Array.from(dialog.querySelectorAll('button')).find(
                (b) => b.textContent === 'Cerrar sesión',
            ) as HTMLButtonElement,
        );

        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 20));
        });

        const heading = screen.getByRole('heading', {
            name: 'Sesiones activas',
        });
        await waitFor(() => expect(document.activeElement).toBe(heading));
        expect(
            view.container.querySelectorAll('[data-test="session-row"]'),
        ).toHaveLength(1);
    });
});
