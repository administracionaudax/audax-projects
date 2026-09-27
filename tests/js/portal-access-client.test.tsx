// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ClientPortalSection } from '@/components/portal/access/client-portal-section';
import { ProjectPortalSection } from '@/components/portal/access/project-portal-section';
import type {
    ClientPortalAccess,
    PortalUserRow,
    ProjectPortalSettings,
} from '@/components/portal/access/types';

const page = vi.hoisted(() => ({
    url: '/clientes/5',
    props: {} as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => page,
    router: { post: inertia.post, get: inertia.get, on: () => () => {} },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

function user(overrides: Partial<PortalUserRow>): PortalUserRow {
    return {
        id: 0,
        name: 'Persona',
        email: 'persona@lur.example',
        is_active: true,
        invitation: 'accepted',
        invitation_expires_at: null,
        last_login_at: null,
        ...overrides,
    };
}

const carmen = user({
    id: 10,
    name: 'Carmen Lur',
    email: 'carmen@lur.example',
    last_login_at: '2026-09-20T06:30:00Z',
});
const inigo = user({
    id: 11,
    name: 'Íñigo Lur',
    invitation: 'pending',
    invitation_expires_at: '2026-10-04T10:00:00Z',
});
const jon = user({ id: 12, name: 'Jon Lur', invitation: 'expired' });
const zoe = user({ id: 13, name: 'Zoe Lur', is_active: false });

function access(
    overrides: Partial<ClientPortalAccess> = {},
): ClientPortalAccess {
    return {
        can: { manageUsers: true, updateSettings: true },
        client_active: true,
        users: [carmen, inigo, jon, zoe],
        settings: {
            person_display: 'name',
            entry_visibility: 'approved',
            notify_thresholds: false,
        },
        options: {
            person_display: ['name', 'initials', 'team'],
            entry_visibility: ['approved', 'submitted'],
        },
        projects: [
            {
                id: 3,
                code: 'LUR-WEB',
                name: 'Web corporativa',
                project_visible: true,
                show_task_hours: true,
                gantt_visible: false,
            },
        ],
        ...overrides,
    };
}

function renderSection(
    portal: Parameters<typeof ClientPortalSection>[0]['portal'] = access(),
) {
    return render(
        <ClientPortalSection
            clientId={5}
            clientName="Bodegas Lur"
            portal={portal}
        />,
    );
}

function row(name: string): HTMLElement {
    return screen
        .getAllByRole('listitem')
        .find((item) => item.textContent?.includes(name)) as HTMLElement;
}

beforeEach(() => {
    inertia.post.mockReset();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('acceso al portal en la ficha de cliente', () => {
    it('quien no lo gestiona solo ve quién lo hace', () => {
        renderSection(null);

        expect(
            screen.getByText(
                'Lo gestionan la administración, los responsables y los gestores de sus proyectos.',
            ),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: 'Invitar al portal' }),
        ).toBeNull();
    });

    it('lista cada persona con su estado (icono y texto) y su último acceso o la caducidad del enlace', () => {
        renderSection();

        expect(
            screen.getByRole('heading', { name: 'Personas con acceso (4)' }),
        ).toBeTruthy();
        expect(row('Carmen Lur').textContent).toContain('Con acceso');
        expect(row('Carmen Lur').textContent).toContain(
            'Último acceso: 20/09/2026 08:30',
        );
        expect(row('Íñigo Lur').textContent).toContain('Invitación pendiente');
        expect(row('Íñigo Lur').textContent).toContain(
            'El enlace caduca el 04/10/2026',
        );
        expect(row('Jon Lur').textContent).toContain('Invitación caducada');
        expect(row('Jon Lur').textContent).toContain('Todavía no ha entrado');
        expect(row('Zoe Lur').textContent).toContain('Sin acceso');
    });

    it('las acciones dependen del estado: reenviar si no ha entrado, revocar si tiene acceso y reactivar si no', async () => {
        const ui = userEvent.setup();
        renderSection();

        await ui.click(
            within(row('Carmen Lur')).getByRole('button', {
                name: 'Acciones de Carmen Lur',
            }),
        );
        expect(
            screen.queryByRole('menuitem', { name: 'Reenviar la invitación' }),
        ).toBeNull();
        expect(
            screen.getByRole('menuitem', { name: 'Revocar el acceso' }),
        ).toBeTruthy();
        await ui.keyboard('{Escape}');

        await ui.click(
            within(row('Íñigo Lur')).getByRole('button', {
                name: 'Acciones de Íñigo Lur',
            }),
        );
        await ui.click(
            screen.getByRole('menuitem', { name: 'Reenviar la invitación' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/clientes/5/portal/usuarios/11/invitacion',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );

        await ui.click(
            within(row('Zoe Lur')).getByRole('button', {
                name: 'Acciones de Zoe Lur',
            }),
        );
        expect(
            screen.queryByRole('menuitem', { name: 'Revocar el acceso' }),
        ).toBeNull();
        await ui.click(
            screen.getByRole('menuitem', { name: 'Reactivar el acceso' }),
        );
        expect(inertia.post).toHaveBeenLastCalledWith(
            '/clientes/5/portal/usuarios/13/reactivar',
            {},
            expect.anything(),
        );
    });

    it('revocar pide confirmación y explica que cierra sus sesiones', async () => {
        const ui = userEvent.setup();
        renderSection();

        await ui.click(
            within(row('Carmen Lur')).getByRole('button', {
                name: 'Acciones de Carmen Lur',
            }),
        );
        await ui.click(
            screen.getByRole('menuitem', { name: 'Revocar el acceso' }),
        );

        const dialog = await screen.findByRole('dialog', {
            name: '¿Revocar el acceso de Carmen Lur?',
        });
        expect(dialog.textContent).toContain(
            'sus sesiones abiertas se cerrarán al momento',
        );
        expect(inertia.post).not.toHaveBeenCalled();

        await ui.click(
            within(dialog).getByRole('button', { name: 'Revocar el acceso' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/clientes/5/portal/usuarios/10/revocar',
            {},
            expect.anything(),
        );
    });

    it('sin permiso para gestionar usuarios no hay acciones ni invitación; sin permiso de ajustes, se dice quién los cambia', () => {
        renderSection(
            access({ can: { manageUsers: false, updateSettings: false } }),
        );

        expect(
            screen.queryByRole('button', { name: /Acciones de/ }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Invitar al portal' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Cambiar lo que ve' }),
        ).toBeNull();
        expect(
            screen.getByText(
                'Lo cambian la administración y los responsables.',
            ),
        ).toBeTruthy();
    });

    it('con el cliente desactivado lo avisa y no deja invitar', () => {
        renderSection(access({ client_active: false }));

        expect(screen.getByRole('status').textContent).toContain(
            'El cliente está desactivado',
        );
        expect(
            (
                screen.getByRole('button', {
                    name: 'Invitar al portal',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('sin usuarios, un estado vacío; y resume los ajustes y los proyectos abiertos', () => {
        renderSection(access({ users: [] }));

        expect(screen.getByText('Nadie tiene acceso todavía')).toBeTruthy();
        const summary = screen
            .getByText('Cómo se nombra a las personas')
            .closest('dl') as HTMLElement;
        expect(summary.textContent).toContain('Nombre');
        expect(summary.textContent).toContain('Aprobadas y bloqueadas');
        expect(summary.textContent).toContain('No');

        const open = screen.getByRole('link', {
            name: 'Web corporativa: ajustes del portal del proyecto',
        });
        expect(open.getAttribute('href')).toBe('/proyectos/3/ajustes');
        const badges = open.closest('li')?.textContent;
        expect(badges).toContain('Tareas');
        expect(badges).toContain('Horas por tarea');
        expect(badges).not.toContain('Gantt');
    });

    it('invitar envía nombre y correo al cliente y enseña los errores junto al campo', async () => {
        const ui = userEvent.setup();
        const post = vi.spyOn(coreRouter, 'post').mockImplementation(() => {});
        renderSection();

        await ui.click(
            screen.getByRole('button', { name: 'Invitar al portal' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Invitar al portal de Bodegas Lur',
        });
        expect(dialog.textContent).toContain('caduca en 7 días');

        await ui.type(within(dialog).getByLabelText('Nombre'), 'Ane Lur');
        await ui.type(
            within(dialog).getByLabelText('Correo electrónico'),
            'ane@lur.example',
        );
        await ui.click(
            within(dialog).getByRole('button', {
                name: 'Enviar la invitación',
            }),
        );

        expect(post).toHaveBeenCalledTimes(1);
        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/clientes/5/portal/usuarios');
        expect(data).toEqual({ name: 'Ane Lur', email: 'ane@lur.example' });
    });

    it('los ajustes se cambian en un diálogo con radios y un interruptor', async () => {
        const ui = userEvent.setup();
        const put = vi.spyOn(coreRouter, 'put').mockImplementation(() => {});
        renderSection();

        await ui.click(
            screen.getByRole('button', { name: 'Cambiar lo que ve' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Qué ve el cliente',
        });

        await ui.click(
            within(dialog).getByRole('radio', { name: /Iniciales/ }),
        );
        await ui.click(
            within(dialog).getByRole('radio', { name: /También las enviadas/ }),
        );
        await ui.click(
            within(dialog).getByRole('switch', {
                name: 'Avisos de bolsa por email',
            }),
        );
        await ui.click(within(dialog).getByRole('button', { name: 'Guardar' }));

        expect(put).toHaveBeenCalledTimes(1);
        const [url, data] = put.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/clientes/5/portal/ajustes');
        expect(data).toEqual({
            portal_person_display: 'initials',
            portal_entry_visibility: 'submitted',
            portal_notify_thresholds: true,
        });
    });
});

describe('portal del cliente en los ajustes del proyecto', () => {
    const settings = (
        overrides: Partial<ProjectPortalSettings> = {},
    ): ProjectPortalSettings => ({
        project_visible: false,
        show_task_hours: false,
        gantt_visible: false,
        client: { id: 5, name: 'Bodegas Lur', is_active: true },
        active_users: 2,
        ...overrides,
    });

    it('un proyecto sin cliente no se puede abrir', () => {
        render(
            <ProjectPortalSection
                projectId={3}
                portal={settings({ client: null, active_users: 0 })}
            />,
        );

        expect(
            screen.getByText(
                'Este proyecto no tiene cliente: no se puede abrir al portal.',
            ),
        ).toBeTruthy();
        expect(screen.queryByRole('switch')).toBeNull();
    });

    it('las horas por tarea solo se activan con el proyecto abierto; guardar envía los tres ajustes', async () => {
        const ui = userEvent.setup();
        const put = vi.spyOn(coreRouter, 'put').mockImplementation(() => {});
        render(<ProjectPortalSection projectId={3} portal={settings()} />);

        expect(
            screen.getByText(
                '2 personas de Bodegas Lur tienen acceso al portal.',
            ),
        ).toBeTruthy();
        const view = screen.getByRole('switch', {
            name: 'Abrir el proyecto al portal',
        });
        const hours = screen.getByRole('switch', {
            name: 'Enseñar las horas totales por tarea',
        }) as HTMLButtonElement;
        const save = screen.getByRole('button', {
            name: 'Guardar',
        }) as HTMLButtonElement;
        expect(hours.disabled).toBe(true);
        expect(save.disabled).toBe(true);

        await ui.click(view);
        expect(hours.disabled).toBe(false);
        await ui.click(hours);
        await ui.click(
            screen.getByRole('switch', { name: 'Abrir el Gantt al portal' }),
        );

        // Cerrar la vista apaga también las horas.
        await ui.click(view);
        expect(hours.getAttribute('aria-checked')).toBe('false');
        await ui.click(view);
        await ui.click(hours);

        await ui.click(save);
        expect(put).toHaveBeenCalledTimes(1);
        const [url, data] = put.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/proyectos/3/portal');
        expect(data).toEqual({
            portal_project_visible: true,
            portal_show_task_hours: true,
            portal_gantt_visible: true,
        });
    });

    it('avisa si el cliente está desactivado o si todavía no tiene a nadie con acceso', () => {
        render(
            <ProjectPortalSection
                projectId={3}
                portal={settings({
                    client: { id: 5, name: 'Bodegas Lur', is_active: false },
                    active_users: 0,
                })}
            />,
        );

        expect(screen.getByRole('status').textContent).toContain(
            'Bodegas Lur está desactivado',
        );
        expect(
            screen.getByText(/todavía no tiene a nadie con acceso al portal/),
        ).toBeTruthy();
    });
});
