// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppSidebar, navSections } from '@/components/app-sidebar';
import { resetNavSectionsMemory } from '@/hooks/use-nav-sections';
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
    // Todo desplegado (navCollapsed vacío) para ver todas las entradas.
    page.props = {
        auth: { user, can },
        sidebarOpen: true,
        name: 'Audax',
        navCollapsed: [],
    };
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
    resetNavSectionsMemory();
    window.localStorage.clear();
});

/** Títulos de cada bloque que se pinta (sin los vacíos), por id de sección. */
function titlesBySection(can: Abilities) {
    return Object.fromEntries(
        navSections(can)
            .filter((section) => section.items.length > 0)
            .map((section) => [
                section.id,
                section.items.map((item) => item.title),
            ]),
    );
}

describe('navegación principal', () => {
    it('agrupa las entradas en las secciones de D-260, con todos los permisos', () => {
        // Chat, fijo (a Inicio se va con el logotipo, D-261); «Ausencias», en Personas; Facturación,
        // sin entradas, no sale.
        expect(
            titlesBySection({
                ...none,
                viewHourBanks: true,
                viewAdmin: true,
                viewFinancials: true,
                viewTeamAbsences: true,
                manageUsers: true,
                manageSettings: true,
            }),
        ).toEqual({
            main: ['Chat'],
            projects: [
                'Mis tareas',
                'Calendario',
                'Proyectos',
                'Clientes',
                'Bolsas',
                'Horas',
                'Carga',
                'Informes',
            ],
            people: ['Ausencias'],
            admin: ['Panel', 'Usuarios', 'Ajustes'],
        });
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

    it('muestra la sección Administración solo al admin', () => {
        expect(
            renderSidebar().queryByRole('group', { name: 'Administración' }),
        ).toBeNull();
    });

    it('al admin, la sección Administración con el panel, Usuarios y Ajustes', () => {
        setAbilities({
            ...none,
            viewHourBanks: true,
            viewAdmin: true,
            manageUsers: true,
            manageSettings: true,
        });
        const nav = renderSidebar();
        const admin = within(
            nav.getByRole('group', { name: 'Administración' }),
        );

        expect(
            admin.getAllByRole('link').map((link) => link.getAttribute('href')),
        ).toEqual(['/admin', '/admin/usuarios', '/admin/ajustes']);
    });

    it('el panel de administración solo es la página actual en /admin', () => {
        page.url = '/admin/usuarios';
        setAbilities({
            ...none,
            viewAdmin: true,
            manageUsers: true,
            manageSettings: true,
        });
        const nav = renderSidebar();

        expect(
            nav
                .getByRole('link', { name: 'Panel' })
                .getAttribute('aria-current'),
        ).toBeNull();
        expect(
            nav
                .getByRole('link', { name: 'Usuarios' })
                .getAttribute('aria-current'),
        ).toBe('page');
    });

    it('a Inicio se va con el logotipo, sin entrada propia en el menú (D-261)', () => {
        const nav = renderSidebar();

        expect(nav.queryByRole('link', { name: 'Inicio' })).toBeNull();
        expect(
            screen
                .getByRole('img', { name: 'AUDAX' })
                .closest('a')
                ?.getAttribute('href'),
        ).toBe('/');
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
    it('solo tiene Mis tareas, Calendario, Proyectos, Horas y Chat', () => {
        // Sin Weekly, Personas ni Administración: esas secciones no se pintan.
        expect(titlesBySection(collaborator)).toEqual({
            main: ['Chat'],
            projects: ['Mis tareas', 'Calendario', 'Proyectos', 'Horas'],
        });
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
