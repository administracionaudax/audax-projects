// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

import { GlobalBanner } from '@/components/weeklies/global-banner';

describe('aviso global (F-178)', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
        page.props = {
            config: {
                global_banner: {
                    message: 'El viernes no hay servicio de 9 a 10.',
                    tone: 'warning',
                },
            },
        };
    });

    it('sale con su texto y se oculta en esta sesión', () => {
        const { unmount } = render(<GlobalBanner />);

        const banner = screen.getByRole('complementary', {
            name: 'Aviso para toda la plantilla',
        });
        expect(banner.textContent).toContain(
            'El viernes no hay servicio de 9 a 10.',
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Ocultar el aviso' }),
        );
        expect(screen.queryByRole('complementary')).toBeNull();
        unmount();

        // Al volver a pintar la página sigue oculto; un mensaje nuevo vuelve a salir.
        const again = render(<GlobalBanner />);
        expect(screen.queryByRole('complementary')).toBeNull();
        again.unmount();

        page.props = {
            config: { global_banner: { message: 'Nuevo aviso', tone: 'info' } },
        };
        render(<GlobalBanner />);
        expect(screen.getByRole('complementary').textContent).toContain(
            'Nuevo aviso',
        );
    });

    it('sin aviso no pinta nada', () => {
        page.props = { config: { global_banner: null } };
        const { container } = render(<GlobalBanner />);

        expect(container.innerHTML).toBe('');
    });
});
