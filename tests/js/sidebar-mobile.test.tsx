// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import {
    Sidebar,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => true }));

/**
 * Menú móvil (UI-13): el título y la descripción sr-only van DENTRO del diálogo. Fuera se
 * leían como texto suelto con el menú cerrado y no daban nombre al diálogo abierto.
 */
describe('barra lateral en móvil', () => {
    function renderMobile() {
        return render(
            <SidebarProvider defaultOpen={false}>
                <SidebarTrigger />
                <Sidebar>
                    <a href="/proyectos">Proyectos</a>
                </Sidebar>
            </SidebarProvider>,
        );
    }

    it('con el menú cerrado no deja texto suelto en la página', () => {
        const { container } = renderMobile();

        expect(document.body.textContent).not.toContain(
            'Navegación principal de la aplicación.',
        );
        expect(container.querySelector('[data-slot="sheet-header"]')).toBe(
            null,
        );
    });

    it('al abrirlo, el diálogo se titula «Menú» y lleva su descripción', async () => {
        const user = userEvent.setup();
        renderMobile();

        await user.click(
            screen.getByRole('button', {
                name: 'Mostrar u ocultar la barra lateral',
            }),
        );

        const dialog = await screen.findByRole('dialog', { name: 'Menú' });
        expect(
            dialog.querySelector('[data-slot="sheet-header"]'),
        ).not.toBeNull();
        expect(dialog.getAttribute('aria-describedby')).toBeTruthy();
        expect(
            document.getElementById(
                dialog.getAttribute('aria-describedby') ?? '',
            )?.textContent,
        ).toBe('Navegación principal de la aplicación.');
    });
});
