// @vitest-environment jsdom
import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Heading from '@/components/heading';
import SettingsLayout from '@/layouts/settings/layout';
import AdminIndex from '@/pages/admin/index';
import Appearance from '@/pages/settings/appearance';
import Sessions from '@/pages/settings/sessions';

/**
 * Jerarquía de encabezados (UI-10, WCAG 1.3.1 y 2.4.6): un solo h1 por página, antes que
 * cualquier h2, y sin saltos de nivel.
 */
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: '/ajustes/sesiones', props: {} }),
    router: { patch: vi.fn(), delete: vi.fn(), on: () => () => {} },
    Link: ({
        href,
        children,
        prefetch: _prefetch,
        ...rest
    }: {
        href: string | { url: string };
        children?: ReactNode;
        prefetch?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

function levels(container: HTMLElement): number[] {
    return [...container.querySelectorAll('h1, h2, h3, h4, h5, h6')].map((h) =>
        Number(h.tagName.slice(1)),
    );
}

function expectOutline(container: HTMLElement) {
    const found = levels(container);

    expect(found.filter((level) => level === 1)).toHaveLength(1);
    expect(found[0]).toBe(1);
    found.slice(1).forEach((level, i) => {
        expect(level - found[i]).toBeLessThanOrEqual(1);
    });
}

describe('Heading', () => {
    it('es h2 por defecto y admite otro nivel con as', () => {
        const { container, rerender } = render(<Heading title="Sección" />);
        expect(container.querySelector('h2')?.textContent).toBe('Sección');

        rerender(<Heading as="h1" title="Página" />);
        expect(container.querySelector('h1')?.textContent).toBe('Página');
        expect(container.querySelector('h2')).toBeNull();
    });
});

describe('jerarquía de encabezados por página', () => {
    it('administración: el título es el único h1 y las áreas son h2', () => {
        const { container } = render(<AdminIndex />);

        expectOutline(container);
        expect(container.querySelector('h1')?.textContent).toBe(
            'Panel de administración',
        );
    });

    it.each([
        ['apariencia', () => <Appearance />],
        ['sesiones', () => <Sessions sessions={[]} />],
    ])(
        'ajustes (%s): «Ajustes» es el único h1, antes de los h2',
        (_n, page) => {
            const { container } = render(
                <SettingsLayout>{page()}</SettingsLayout>,
            );

            expectOutline(container);
            expect(container.querySelector('h1')?.textContent).toBe('Ajustes');
        },
    );
});
