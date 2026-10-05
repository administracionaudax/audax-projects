// @vitest-environment jsdom
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ version: 'v1', props: {} }));
const router = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage: () => page, router }));

import { AppFreshness } from '@/components/app-freshness';

const fetchMock = vi.fn<typeof fetch>();

function setVisibility(state: 'visible' | 'hidden') {
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        value: state,
    });
    fireEvent(document, new Event('visibilitychange'));
}

describe('versión nueva y conexión (F-013 y F-014)', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        router.reload.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        setVisibility('visible');
    });

    it('al volver a la pestaña, si hay un despliegue nuevo, avisa con «Recargar»', async () => {
        fetchMock.mockResolvedValue(
            new Response(JSON.stringify({ version: 'v2' }), { status: 200 }),
        );
        render(<AppFreshness />);
        expect(screen.queryByRole('status')).toBeNull();

        setVisibility('hidden');
        await act(async () => {
            setVisibility('visible');
        });

        expect(fetchMock.mock.calls[0][0]).toBe('/version');
        expect(screen.getByRole('status').textContent).toContain(
            'Hay una versión nueva de la app',
        );
        expect(screen.getByRole('button', { name: 'Recargar' })).toBeTruthy();
        // Una vuelta rápida no recarga los datos.
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('con la misma versión no dice nada', async () => {
        fetchMock.mockResolvedValue(
            new Response(JSON.stringify({ version: 'v1' }), { status: 200 }),
        );
        render(<AppFreshness />);

        await act(async () => {
            setVisibility('visible');
        });

        expect(screen.queryByRole('status')).toBeNull();
    });

    it('sin conexión lo dice y, al volver, recarga los datos de la página', async () => {
        fetchMock.mockResolvedValue(
            new Response(JSON.stringify({ version: 'v1' }), { status: 200 }),
        );
        render(<AppFreshness />);

        act(() => {
            window.dispatchEvent(new Event('offline'));
        });
        expect(screen.getByRole('status').textContent).toContain(
            'Sin conexión',
        );

        await act(async () => {
            window.dispatchEvent(new Event('online'));
        });
        expect(screen.queryByRole('status')).toBeNull();
        expect(router.reload).toHaveBeenCalledTimes(1);
    });
});
