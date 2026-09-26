// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ErrorPage, { errorMessage } from '@/pages/error';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
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

describe('página de error (UX-03)', () => {
    it('explica el 403 en español con un encabezado y la vuelta a Inicio', () => {
        render(<ErrorPage status={403} />);

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'No tienes acceso a esta página',
            }),
        ).toBeTruthy();
        expect(screen.getByText('Error 403')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Ir a Inicio' })
                .getAttribute('href'),
        ).toBe('/');
        expect(screen.getByRole('main')).toBeTruthy();
    });

    it('tiene un mensaje para cada estado y, si no lo conoce, el genérico', () => {
        expect(errorMessage(404).title).toBe('No encontramos esta página');
        expect(errorMessage(419).title).toBe('La página ha caducado');
        expect(errorMessage(503).title).toBe('Estamos haciendo mantenimiento');
        expect(errorMessage(418)).toEqual(errorMessage(500));
    });
});
