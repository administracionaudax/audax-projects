// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import Profile from '@/pages/settings/profile';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({
        url: '/ajustes/perfil',
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Ana Admin',
                    email: 'ana@example.com',
                    avatar: null,
                    theme_preference: 'system',
                    two_factor_enabled: false,
                    roles: ['admin'],
                    is_client: false,
                },
                can: {
                    viewHourBanks: true,
                    viewAdmin: true,
                    viewFinancials: true,
                },
            },
        },
    }),
}));

describe('perfil', () => {
    it('pide la contraseña actual solo al cambiar el correo (SEC-08)', async () => {
        render(<Profile />);

        expect(screen.queryByLabelText('Contraseña actual')).toBeNull();

        const email = screen.getByLabelText('Correo electrónico');
        await userEvent.clear(email);
        await userEvent.type(email, 'nuevo@example.com');

        expect(screen.getByLabelText('Contraseña actual')).toBeTruthy();

        await userEvent.clear(email);
        await userEvent.type(email, 'ANA@example.com');

        expect(screen.queryByLabelText('Contraseña actual')).toBeNull();
    });
});
