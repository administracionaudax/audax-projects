// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

const navigate = vi.hoisted(() => ({ handler: null as null | (() => void) }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: {
        on: (event: string, handler: () => void) => {
            if (event === 'navigate') {
                navigate.handler = handler;
            }

            return () => {
                navigate.handler = null;
            };
        },
    },
}));

import { act } from 'react';
import { ErrorBoundary } from '@/components/error-boundary';

function Broken({ fail }: { fail: boolean }) {
    if (fail) {
        throw new Error('fallo al pintar');
    }

    return <p>Todo bien</p>;
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('pantalla de error del navegador (F-017)', () => {
    it('si una página falla al pintarse, lo dice con «Recargar» e «Ir al inicio»; al navegar se rearma', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const reload = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: window.location.href, reload },
        });

        const { rerender } = render(
            <ErrorBoundary>
                <Broken fail />
            </ErrorBoundary>,
        );

        expect(screen.getByRole('alert').textContent).toContain(
            'Algo ha fallado al mostrar esta página',
        );
        expect(
            screen
                .getByRole('link', { name: 'Ir al inicio' })
                .getAttribute('href'),
        ).toBe('/');
        await userEvent.click(screen.getByRole('button', { name: 'Recargar' }));
        expect(reload).toHaveBeenCalled();

        rerender(
            <ErrorBoundary>
                <Broken fail={false} />
            </ErrorBoundary>,
        );
        act(() => navigate.handler?.());
        expect(screen.getByText('Todo bien')).toBeTruthy();
    });
});
