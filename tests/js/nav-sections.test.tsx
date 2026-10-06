// @vitest-environment jsdom
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import fixture from '../fixtures/nav-sections.json';
import { AppSidebar } from '@/components/app-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import {
    NAV_SECTION_IDS,
    navSectionsStorageKey,
    resetNavSectionsMemory,
} from '@/hooks/use-nav-sections';
import type { Abilities, User } from '@/types';

/*
 * Secciones plegables de la barra lateral (D-260): plegar y desplegar con ratón y teclado, estado
 * por persona y persistente (servidor y navegador), auto-despliegue de la sección de la página
 * actual, secciones vacías ocultas y modo icono sin encabezados.
 */

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
    id: 7,
    name: 'Ana García',
    email: 'ana@audaxstudio.com',
    avatar: null,
    theme_preference: 'system',
    two_factor_enabled: false,
    roles: ['admin'],
    is_client: false,
    is_collaborator: false,
};

// Un admin que usa la Weekly: ve todas las secciones con entradas.
const admin: Abilities = {
    viewHourBanks: true,
    viewAdmin: true,
    viewFinancials: true,
    createClients: true,
    createProjects: true,
    approveTime: true,
    lockTime: true,
    manageUsers: true,
    manageSettings: true,
    viewTeamAbsences: true,
    viewClients: true,
    viewWorkload: true,
    viewAbsences: true,
    viewReports: true,
    useWeeklies: true,
};

function setProps(extra: Record<string, unknown> = {}, can = admin) {
    page.props = {
        auth: { user, can },
        sidebarOpen: true,
        name: 'Audax',
        config: { modules: { weeklies: true, assistant: true, help: true } },
        ...extra,
    };
}

/** Navegar: Inertia trae props nuevas (otros objetos) y la barra se vuelve a pintar. */
function visit(url: string) {
    page.url = url;
    page.props = { ...page.props, auth: { user, can: { ...admin } } };
}

function tree(open = true) {
    return (
        <TooltipProvider>
            <SidebarProvider defaultOpen={open}>
                <AppSidebar />
            </SidebarProvider>
        </TooltipProvider>
    );
}

function nav() {
    return within(
        screen.getByRole('navigation', { name: 'Navegación principal' }),
    );
}

/** Peticiones al servidor con las secciones plegadas (no las del chat u otras). */
function navRequests() {
    return fetchMock.mock.calls.filter(
        ([url]) => url === '/menu/secciones',
    ) as [string, RequestInit][];
}

function toggle(name: string) {
    return nav().getByRole('button', { name });
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
    page.url = '/';
    setProps();
    resetNavSectionsMemory();
    window.localStorage.clear();
    fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('secciones plegables de la barra lateral (D-260)', () => {
    it('la lista de secciones es la del contrato compartido con el servidor', () => {
        expect([...NAV_SECTION_IDS]).toEqual(fixture.sections);
    });

    it('por defecto todas desplegadas; Inicio y Chat quedan fijos, fuera de las secciones', () => {
        render(tree());

        for (const name of [
            'Proyectos',
            'Weekly',
            'Personas',
            'Administración',
        ]) {
            const button = toggle(name);
            expect(button.getAttribute('aria-expanded')).toBe('true');
            const content = document.getElementById(
                button.getAttribute('aria-controls') ?? '',
            );
            expect(content).not.toBeNull();
            expect(content?.hidden).toBe(false);
        }

        for (const name of ['Inicio', 'Chat']) {
            expect(
                nav().getByRole('link', { name }).closest('[role="group"]'),
            ).toBeNull();
        }
    });

    it('una sección sin entradas visibles no se pinta (Facturación, todavía vacía)', () => {
        render(tree());

        expect(nav().queryByRole('button', { name: 'Facturación' })).toBeNull();
        expect(nav().queryByRole('group', { name: 'Facturación' })).toBeNull();
    });

    it('a un colaborador externo solo le sale la sección Proyectos', () => {
        setProps(
            {},
            {
                ...admin,
                viewHourBanks: false,
                viewAdmin: false,
                viewClients: false,
                viewWorkload: false,
                viewAbsences: false,
                viewReports: false,
                viewTeamAbsences: false,
                useWeeklies: false,
            },
        );
        render(tree());

        expect(
            nav()
                .getAllByRole('button')
                .map((button) => button.textContent),
        ).toEqual(['Proyectos']);
    });

    it('plegar oculta sus entradas y desplegar las devuelve', async () => {
        const ue = userEvent.setup();
        render(tree());

        await ue.click(toggle('Proyectos'));

        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('false');
        expect(nav().queryByRole('link', { name: 'Clientes' })).toBeNull();
        expect(nav().getByRole('link', { name: 'Mi espacio' })).toBeTruthy();

        await ue.click(toggle('Proyectos'));

        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('true');
        expect(nav().getByRole('link', { name: 'Clientes' })).toBeTruthy();
    });

    it('se pliega y despliega con el teclado (Intro y espacio)', async () => {
        const ue = userEvent.setup();
        render(tree());

        toggle('Weekly').focus();
        await ue.keyboard('{Enter}');
        expect(toggle('Weekly').getAttribute('aria-expanded')).toBe('false');
        expect(nav().queryByRole('link', { name: 'Weeklies' })).toBeNull();

        await ue.keyboard(' ');
        expect(toggle('Weekly').getAttribute('aria-expanded')).toBe('true');
    });

    it('guarda el estado en el navegador por persona y lo recupera al volver', async () => {
        const ue = userEvent.setup();
        const { unmount } = render(tree());

        await ue.click(toggle('Administración'));
        await ue.click(toggle('Proyectos'));

        expect(
            JSON.parse(
                window.localStorage.getItem(navSectionsStorageKey(7)) ?? '',
            ),
        ).toEqual(['projects', 'admin']);
        // Sin la prop del servidor no se le manda nada.
        expect(navRequests()).toEqual([]);

        unmount();
        resetNavSectionsMemory();
        render(tree());

        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('false');
        expect(toggle('Administración').getAttribute('aria-expanded')).toBe(
            'false',
        );
        expect(toggle('Weekly').getAttribute('aria-expanded')).toBe('true');
    });

    it('parte de lo guardado en el servidor y le manda cada cambio', async () => {
        const ue = userEvent.setup();
        setProps({ navCollapsed: ['weekly'] });
        render(tree());

        expect(toggle('Weekly').getAttribute('aria-expanded')).toBe('false');
        expect(navRequests()).toEqual([]);

        await ue.click(toggle('Personas'));

        expect(navRequests()).toHaveLength(1);
        const [url, init] = navRequests()[0];
        expect(url).toBe('/menu/secciones');
        expect(init.method).toBe('PUT');
        expect(JSON.parse(init.body as string)).toEqual({
            collapsed: ['weekly', 'people'],
        });
    });

    it('si el servidor falla, el cambio se mantiene', async () => {
        const ue = userEvent.setup();
        fetchMock.mockRejectedValue(new Error('offline'));
        setProps({ navCollapsed: [] });
        render(tree());

        await ue.click(toggle('Proyectos'));

        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('false');
    });

    it('sin acceso al almacenamiento del navegador, todas desplegadas y sin errores', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('SecurityError');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('SecurityError');
        });
        render(tree());

        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('true');
    });

    it('al entrar en una página de una sección plegada, la sección se despliega sola', () => {
        page.url = '/clientes/3';
        setProps({ navCollapsed: ['projects', 'admin'] });
        render(tree());

        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('true');
        expect(
            nav()
                .getByRole('link', { name: 'Clientes' })
                .getAttribute('aria-current'),
        ).toBe('page');
        // La otra sigue plegada y se guarda el cambio.
        expect(toggle('Administración').getAttribute('aria-expanded')).toBe(
            'false',
        );
        expect(JSON.parse(navRequests()[0]?.[1]?.body as string)).toEqual({
            collapsed: ['admin'],
        });
    });

    it('plegar la sección de la página actual se respeta hasta entrar en otra', async () => {
        const ue = userEvent.setup();
        page.url = '/proyectos';
        const { rerender } = render(tree());

        await ue.click(toggle('Proyectos'));
        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('false');

        // Otra página de la misma sección: sigue plegada.
        visit('/proyectos/5');
        rerender(tree());
        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('false');

        // Fuera de la sección y de vuelta: se despliega.
        visit('/mi-espacio');
        rerender(tree());
        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('false');
        visit('/horas');
        rerender(tree());
        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('true');
    });

    it('la subpágina de una entrada (Envíos programados) también despliega su sección', () => {
        page.url = '/informes/envios';
        setProps({ navCollapsed: ['projects'] });
        render(tree());

        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('true');
    });

    it('con la barra reducida a iconos no hay encabezados y se ven todas las entradas', () => {
        setProps({ navCollapsed: ['projects', 'weekly'] });
        render(tree(false));

        expect(nav().queryAllByRole('button')).toEqual([]);
        // Las entradas de las secciones plegadas siguen ahí, con su tooltip, en su grupo con nombre.
        const projects = nav().getByRole('group', { name: 'Proyectos' });
        expect(
            within(projects).getByRole('link', { name: 'Clientes' }),
        ).toBeTruthy();
        expect(nav().getByRole('link', { name: 'Mi espacio' })).toBeTruthy();
    });

    it('al volver a desplegar la barra, las secciones recuperan su estado', async () => {
        setProps({ navCollapsed: ['weekly'] });
        render(tree(false));
        expect(nav().queryAllByRole('button')).toEqual([]);

        await act(async () => {
            window.dispatchEvent(
                new KeyboardEvent('keydown', { key: 'b', ctrlKey: true }),
            );
        });

        expect(toggle('Weekly').getAttribute('aria-expanded')).toBe('false');
        expect(toggle('Proyectos').getAttribute('aria-expanded')).toBe('true');
    });
});
