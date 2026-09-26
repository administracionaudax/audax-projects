// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AppearanceTabs from '@/components/appearance-tabs';
import { setAppearance, syncAppearance } from '@/hooks/use-appearance';

const patch = vi.fn();

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: { patch: (...args: unknown[]) => patch(...args) },
}));

beforeEach(() => {
    patch.mockReset();
    setAppearance('system');
});

describe('selector de apariencia', () => {
    it('ofrece claro, oscuro y según el sistema', () => {
        render(<AppearanceTabs />);

        expect(screen.getByRole('radio', { name: 'Claro' })).toBeTruthy();
        expect(screen.getByRole('radio', { name: 'Oscuro' })).toBeTruthy();
        expect(
            screen
                .getByRole('radio', { name: 'Según el sistema' })
                .getAttribute('aria-checked'),
        ).toBe('true');
    });

    it('aplica el tema y lo guarda en el usuario con PATCH appearance.update', async () => {
        const user = userEvent.setup();
        render(<AppearanceTabs />);

        await user.click(screen.getByRole('radio', { name: 'Oscuro' }));

        expect(patch).toHaveBeenCalledTimes(1);
        expect(patch).toHaveBeenCalledWith(
            '/ajustes/apariencia',
            { theme: 'dark' },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.cookie).toContain('appearance=dark');
        expect(
            screen
                .getByRole('radio', { name: 'Oscuro' })
                .getAttribute('aria-checked'),
        ).toBe('true');
    });

    it('no llama al servidor si se pulsa la opción ya activa', async () => {
        const user = userEvent.setup();
        render(<AppearanceTabs />);

        await user.click(
            screen.getByRole('radio', { name: 'Según el sistema' }),
        );

        expect(patch).not.toHaveBeenCalled();
    });
});

describe('preferencia del usuario', () => {
    it('aplica theme_preference si difiere del tema del navegador', () => {
        localStorage.setItem('appearance', 'light');

        expect(syncAppearance('dark')).toBe(true);
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('no toca nada si ya coinciden o si el valor no es válido', () => {
        localStorage.setItem('appearance', 'light');

        expect(syncAppearance('light')).toBe(false);
        expect(syncAppearance(undefined)).toBe(false);
        expect(localStorage.getItem('appearance')).toBe('light');
    });
});
