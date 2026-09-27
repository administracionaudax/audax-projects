// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { IdentityPageProps } from '@/components/portal/access/types';
import type { PortalShellProps } from '@/components/portal/projects/types';
import PortalLayout from '@/layouts/portal-layout';
import AdminIdentity, { logoError } from '@/pages/admin/identity';

const page = vi.hoisted(() => ({
    url: '/portal',
    props: {} as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({ delete: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => page,
    router: {
        delete: inertia.delete,
        post: inertia.post,
        flushAll: vi.fn(),
        on: () => () => {},
    },
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
}));

const clientUser = {
    id: 3,
    name: 'Carlos Cliente',
    email: 'cliente@example.com',
    avatar: null,
    theme_preference: 'system',
    two_factor_enabled: false,
    roles: ['client'],
    is_client: true,
};

function shell(overrides: Partial<PortalShellProps> = {}): PortalShellProps {
    return {
        company: {
            name: 'Estudio Lur',
            logo: { url: '/marca/logo/abc123', width: 300, height: 100 },
        },
        projects: [
            {
                id: 7,
                code: 'LUR-WEB',
                name: 'Web corporativa',
                view: true,
                gantt: true,
            },
        ],
        ...overrides,
    };
}

function renderLayout(url: string, portal?: PortalShellProps) {
    page.url = url;
    page.props = {
        auth: { user: clientUser, can: {} },
        ...(portal ? { portal } : {}),
    };

    return render(
        <PortalLayout breadcrumbs={[]}>
            <p>Contenido</p>
        </PortalLayout>,
    );
}

beforeEach(() => {
    inertia.delete.mockReset();
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('cabecera del portal (D-067)', () => {
    it('el logo de la empresa va sobre una placa blanca, con su nombre como texto alternativo', () => {
        renderLayout('/portal', shell());

        const home = screen.getByRole('link', {
            name: 'Estudio Lur · Portal de clientes: ir al inicio',
        });
        const logo = within(home).getByRole('img', { name: 'Estudio Lur' });
        expect(logo.getAttribute('src')).toBe('/marca/logo/abc123');
        expect(
            logo.closest('[data-test="portal-company-logo"]')?.className,
        ).toContain('bg-white');
        // El nombre accesible contiene el texto visible (WCAG 2.5.3).
        expect(home.getAttribute('aria-label')).toContain(
            within(home).getByText('Portal de clientes').textContent,
        );
    });

    it('sin logo propio, el logotipo AUDAX', () => {
        renderLayout(
            '/portal',
            shell({ company: { name: 'Audax Studio', logo: null } }),
        );

        const home = screen.getByRole('link', {
            name: 'Audax Studio · Portal de clientes: ir al inicio',
        });
        expect(within(home).getByRole('img', { name: 'AUDAX' })).toBeTruthy();
        expect(
            document.querySelector('[data-test="portal-company-logo"]'),
        ).toBeNull();
    });

    it('navegación: Inicio y, si hay proyectos abiertos, Proyectos, con la página actual marcada', () => {
        const { unmount } = renderLayout('/portal', shell());

        const nav = screen.getByRole('navigation', { name: 'Portal' });
        expect(
            within(nav)
                .getByRole('link', { name: 'Inicio' })
                .getAttribute('aria-current'),
        ).toBe('page');
        const projects = within(nav).getByRole('link', { name: 'Proyectos' });
        expect(projects.getAttribute('href')).toBe('/portal/proyectos');
        expect(projects.getAttribute('aria-current')).toBeNull();
        unmount();

        renderLayout('/portal/proyectos/7/gantt?escala=mes', shell());
        const current = within(
            screen.getByRole('navigation', { name: 'Portal' }),
        ).getByRole('link', { name: 'Proyectos' });
        expect(current.getAttribute('aria-current')).toBe('page');
    });

    it('sin proyectos abiertos (o sin la prop), solo Inicio', () => {
        renderLayout('/ajustes/perfil', shell({ projects: [] }));

        const nav = screen.getByRole('navigation', { name: 'Portal' });
        expect(
            within(nav)
                .getAllByRole('link')
                .map((link) => link.textContent),
        ).toEqual(['Inicio']);
    });

    it('sin la prop del portal no falla: logotipo AUDAX y solo Inicio', () => {
        renderLayout('/portal', undefined);

        expect(
            screen.getByRole('link', {
                name: 'Audax Studio · Portal de clientes: ir al inicio',
            }),
        ).toBeTruthy();
        expect(
            within(
                screen.getByRole('navigation', { name: 'Portal' }),
            ).getAllByRole('link'),
        ).toHaveLength(1);
    });
});

describe('identidad de la empresa (/admin/identidad)', () => {
    const props = (
        overrides: Partial<IdentityPageProps['identity']> = {},
    ): IdentityPageProps => ({
        identity: {
            company_name: 'Audax Studio',
            logo: { url: '/marca/logo/abc123', width: 720, height: 240 },
            ...overrides,
        },
        limits: { max_kb: 1024, max_side: 3000 },
    });

    beforeEach(() => {
        page.url = '/admin/identidad';
        page.props = { auth: { user: null, can: {} } };
        vi.stubGlobal(
            'URL',
            Object.assign(URL, {
                createObjectURL: vi.fn(() => 'blob:vista-previa'),
                revokeObjectURL: vi.fn(),
            }),
        );
    });

    it('valida el tipo y el tamaño antes de subir: nunca SVG y como mucho 1 MB', () => {
        const png = new File(['x'], 'logo.png', { type: 'image/png' });
        const svg = new File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' });
        const big = new File([new Uint8Array(1024 * 1024 + 1)], 'logo.webp', {
            type: 'image/webp',
        });

        expect(logoError(png, 1024)).toBeNull();
        expect(logoError(svg, 1024)).toBe(
            'El logo tiene que ser una imagen PNG, JPG o WebP (SVG no).',
        );
        expect(logoError(big, 1024)).toBe('El logo no puede pasar de 1 MB.');
    });

    it('un SVG enseña el error junto al campo y no deja guardar', async () => {
        const ui = userEvent.setup({ applyAccept: false });
        const { container } = render(<AdminIdentity {...props()} />);

        expect(container.querySelectorAll('h1')).toHaveLength(1);
        const input = screen.getByLabelText('Logo nuevo');
        await ui.upload(
            input,
            new File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' }),
        );

        expect(
            screen.getByText(
                'El logo tiene que ser una imagen PNG, JPG o WebP (SVG no).',
            ),
        ).toBeTruthy();
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(
            (
                screen.getByRole('button', {
                    name: 'Guardar',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('un PNG válido se ve en la vista previa y se envía con el nombre', async () => {
        const ui = userEvent.setup();
        const post = vi.spyOn(coreRouter, 'post').mockImplementation(() => {});
        render(<AdminIdentity {...props({ logo: null })} />);

        // Sin logo, la vista previa lleva el logotipo AUDAX.
        expect(
            screen.getAllByRole('img', { name: 'AUDAX' }).length,
        ).toBeGreaterThan(0);

        const file = new File(['png'], 'logo.png', { type: 'image/png' });
        await ui.clear(screen.getByLabelText('Nombre de la empresa'));
        await ui.type(
            screen.getByLabelText('Nombre de la empresa'),
            'Estudio Lur',
        );
        await ui.upload(screen.getByLabelText('Logo nuevo'), file);

        const previews = screen.getAllByRole('img', { name: 'Estudio Lur' });
        expect(previews.length).toBe(2);
        expect(
            previews.every(
                (image) => image.getAttribute('src') === 'blob:vista-previa',
            ),
        ).toBe(true);

        await ui.click(screen.getByRole('button', { name: 'Guardar' }));
        expect(post).toHaveBeenCalledTimes(1);
        const [url, data, options] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
            Record<string, unknown>,
        ];
        expect(url).toBe('/admin/identidad');
        expect(data.company_name).toBe('Estudio Lur');
        expect(data.logo).toBe(file);
        expect(options.forceFormData).toBe(true);
    });

    it('quitar el logo pide confirmación', async () => {
        const ui = userEvent.setup();
        render(<AdminIdentity {...props()} />);

        await ui.click(screen.getByRole('button', { name: 'Quitar el logo' }));
        const dialog = await screen.findByRole('dialog', {
            name: '¿Quitar el logo?',
        });
        await ui.click(
            within(dialog).getByRole('button', { name: 'Quitar el logo' }),
        );

        expect(inertia.delete).toHaveBeenCalledWith(
            '/admin/identidad/logo',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('sin logo no se ofrece quitarlo', () => {
        render(<AdminIdentity {...props({ logo: null })} />);

        expect(
            screen.queryByRole('button', { name: 'Quitar el logo' }),
        ).toBeNull();
    });
});
