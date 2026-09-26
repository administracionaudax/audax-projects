// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppSidebar, mainNavItems } from '@/components/app-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { Abilities, User } from '@/types';

const page = vi.hoisted(() => ({
    url: '/',
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        usePage: () => page,
        Link: ({
            href,
            children,
            prefetch: _prefetch,
            as: _as,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            prefetch?: boolean;
            as?: string;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

const user: User = {
    id: 1,
    name: 'Ana García',
    email: 'ana@audaxstudio.com',
    avatar: null,
    theme_preference: 'system',
    two_factor_enabled: false,
    roles: ['employee'],
    is_client: false,
};

function setAbilities(can: Abilities) {
    page.props = { auth: { user, can }, sidebarOpen: true, name: 'Audax' };
}

function renderSidebar() {
    render(
        <TooltipProvider>
            <SidebarProvider>
                <AppSidebar />
            </SidebarProvider>
        </TooltipProvider>,
    );

    return within(
        screen.getByRole('navigation', { name: 'Navegación principal' }),
    );
}

const none: Abilities = {
    viewHourBanks: false,
    viewAdmin: false,
    viewFinancials: false,
};

beforeEach(() => setAbilities(none));

describe('navegación principal', () => {
    it('sigue el orden del SPEC §3 con todos los permisos', () => {
        const titles = mainNavItems({
            viewHourBanks: true,
            viewAdmin: true,
            viewFinancials: true,
        }).map((item) => item.title);

        expect(titles).toEqual([
            'Inicio',
            'Mis tareas',
            'Proyectos',
            'Clientes',
            'Bolsas',
            'Horas',
            'Carga',
            'Informes',
            'Chat',
            'Administración',
        ]);
    });

    it('oculta Bolsas y Administración a un empleado', () => {
        const nav = renderSidebar();

        expect(nav.getByRole('link', { name: 'Proyectos' })).toBeTruthy();
        expect(nav.queryByRole('link', { name: 'Bolsas' })).toBeNull();
        expect(nav.queryByRole('link', { name: 'Administración' })).toBeNull();
    });

    it('muestra Bolsas a quien puede verlas (responsable o gestor)', () => {
        setAbilities({ ...none, viewHourBanks: true });
        const nav = renderSidebar();

        expect(
            nav.getByRole('link', { name: 'Bolsas' }).getAttribute('href'),
        ).toBe('/bolsas');
        expect(nav.queryByRole('link', { name: 'Administración' })).toBeNull();
    });

    it('muestra Administración solo al admin', () => {
        setAbilities({ ...none, viewHourBanks: true, viewAdmin: true });
        const nav = renderSidebar();

        expect(
            nav
                .getByRole('link', { name: 'Administración' })
                .getAttribute('href'),
        ).toBe('/admin');
    });

    it('marca Inicio como la página actual', () => {
        const nav = renderSidebar();

        expect(
            nav
                .getByRole('link', { name: 'Inicio' })
                .getAttribute('aria-current'),
        ).toBe('page');
        expect(
            nav
                .getByRole('link', { name: 'Proyectos' })
                .getAttribute('aria-current'),
        ).toBeNull();
    });

    it('muestra el logotipo oficial de AUDAX (SVG accesible), sin enlaces externos', () => {
        renderSidebar();

        expect(screen.getByRole('img', { name: 'AUDAX' })).toBeTruthy();
        expect(
            screen
                .getAllByRole('link')
                .filter((link) =>
                    (link.getAttribute('href') ?? '').startsWith('http'),
                ),
        ).toEqual([]);
    });
});
