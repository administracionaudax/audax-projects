// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ClientDialog } from '@/components/clients/client-dialog';
import { Button } from '@/components/ui/button';
import type { Client } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ url: '/clientes', props: { auth: { can: {} } } }),
}));

const client: Client = {
    id: 5,
    name: 'Bodegas Arrieta',
    icon: null,
    owner_user_id: 99,
    tax_id: null,
    contact_name: null,
    contact_email: null,
    phone: null,
    notes: null,
    is_active: true,
};

/** D-310 (H-C3): un responsable que ya no se puede elegir no deja el selector vacío. */
describe('diálogo de cliente', () => {
    it('parte de «Automático» si el responsable guardado ya no está en la lista', async () => {
        const user = userEvent.setup();
        render(
            <ClientDialog
                client={client}
                showFinancials={false}
                trigger={<Button>Editar</Button>}
                people={[{ id: 1, name: 'Ana Administración' }]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Editar' }));

        expect(
            screen.getByRole('combobox', { name: /Responsable/ }).textContent,
        ).toContain('Automático');
    });
});
