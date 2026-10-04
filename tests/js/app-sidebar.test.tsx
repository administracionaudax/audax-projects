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
    is_collaborator: false,
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

// Lo que tiene cualquier empleado (sin permisos extra).
const none: Abilities = {
    viewHourBanks: false,
    viewAdmin: false,
    viewFinancials: false,
    createClients: false,
    createProjects: false,
    approveTime: false,
    lockTime: false,
    manageUsers: false,
    manageSettings: false,
    viewTeamAbsences: false,
    viewClients: true,
    viewWorkload: true,
    viewAbsences: true,
    viewReports: true,
};

// Colaborador externo (D-134): sin Clientes, Bolsas, Carga, Ausencias, Informes ni Administración.
const collaborator: Abilities = {
    ...none,
    viewClients: false,
    viewWorkload: false,
    viewAbsences: false,
    viewReports: false,
};

beforeEach(() => {
    page.url = '/';
    setAbilities(none);
});

describe('navegación principal', () => {
    it('sigue el orden del SPEC §3 con todos los permisos', () => {
        const titles = mainNavItems({
            ...none,
            viewHourBanks: true,
            viewAdmin: true,
            viewFinancials: true,
            viewTeamAbsences: true,
        }).map((item) => item.title);

        // «Ausencias», tras «Carga» (D-091).
        expect(titles).toEqual([
            'Inicio',
            'Mis tareas',
            'Calendario',
            'Proyectos',
            'Clientes',
            'Bolsas',
            'Horas',
            'Carga',
            'Ausencias',
            'Informes',
            'Chat',
            'Administración',
        ]);
    });

    it('todos ven «Ausencias»; solo quien las aprueba, «Ausencias del equipo»', () => {
        const nav = renderSidebar();

        expect(
            nav.getByRole('link', { name: 'Ausencias' }).getAttribute('href'),
        ).toBe('/ausencias');
        expect(
            nav.queryByRole('link', { name: 'Ausencias del equipo' }),
        ).toBeNull();
    });

    it('a quien aprueba ausencias le enseña «Ausencias del equipo» dentro de «Ausencias»', () => {
        setAbilities({ ...none, viewTeamAbsences: true });
        const nav = renderSidebar();

        expect(
            nav
                .getByRole('link', { name: 'Ausencias del equipo' })
                .getAttribute('href'),
        ).toBe('/ausencias/equipo');
        expect(
            nav.getByRole('link', { name: 'Ausencias' }).getAttribute('href'),
        ).toBe('/ausencias');
    });

    it('en «Ausencias del equipo», esa es la página actual y la sección queda resaltada', () => {
        page.url = '/ausencias/equipo';
        setAbilities({ ...none, viewTeamAbsences: true });
        const nav = renderSidebar();

        const section = nav.getByRole('link', { name: 'Ausencias' });
        const team = nav.getByRole('link', { name: 'Ausencias del equipo' });

        expect(team.getAttribute('aria-current')).toBe('page');
        expect(section.getAttribute('aria-current')).toBeNull();
        expect(section.getAttribute('data-active')).toBe('true');
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

describe('navegación de un colaborador externo (D-134)', () => {
    it('solo tiene Inicio, Mis tareas, Calendario, Proyectos, Horas y Chat', () => {
        const titles = mainNavItems(collaborator).map((item) => item.title);

        expect(titles).toEqual([
            'Inicio',
            'Mis tareas',
            'Calendario',
            'Proyectos',
            'Horas',
            'Chat',
        ]);
    });

    it('no pinta los enlaces a clientes, carga, ausencias ni informes', () => {
        page.props = {
            auth: {
                user: {
                    ...user,
                    roles: ['collaborator'],
                    is_collaborator: true,
                },
                can: collaborator,
            },
            sidebarOpen: true,
            name: 'Audax',
        };
        const nav = renderSidebar();

        expect(nav.getByRole('link', { name: 'Proyectos' })).toBeTruthy();
        expect(nav.getByRole('link', { name: 'Chat' })).toBeTruthy();

        for (const name of [
            'Clientes',
            'Bolsas',
            'Carga',
            'Ausencias',
            'Informes',
            'Administración',
        ]) {
            expect(nav.queryByRole('link', { name })).toBeNull();
        }

        const hrefs = nav
            .getAllByRole('link')
            .map((link) => link.getAttribute('href'));
        expect(hrefs).not.toContain('/clientes');
        expect(hrefs).not.toContain('/informes');
    });
});
