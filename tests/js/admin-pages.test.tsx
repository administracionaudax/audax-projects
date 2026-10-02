// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminIndex from '@/pages/admin/index';
import AdminSettings from '@/pages/admin/settings';
import AdminStatuses from '@/pages/admin/statuses/index';
import AdminUserDeactivate from '@/pages/admin/users/deactivate';
import AdminUsersIndex from '@/pages/admin/users/index';
import type {
    Abilities,
    AdminSettingsProps,
    AdminTaskStatus,
    AdminUser,
    AdminUserDeactivateProps,
    AdminUsersIndexProps,
} from '@/types';

const page = vi.hoisted(() => ({
    url: '/admin',
    props: {} as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
        router: {
            get: inertia.get,
            post: inertia.post,
            delete: inertia.delete,
            on: () => () => {},
        },
        Link: ({
            href,
            children,
            preserveScroll: _preserveScroll,
            prefetch: _prefetch,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            preserveScroll?: boolean;
            prefetch?: boolean;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

const ALL: Abilities = {
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
};

function withAbilities(can: Partial<Abilities>) {
    page.props = { auth: { user: null, can: { ...ALL, ...can } } };
}

beforeEach(() => {
    withAbilities({});
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.delete.mockReset();
});

describe('panel de administración', () => {
    it('las áreas de la Fase 1 enlazan a sus páginas; las demás siguen con su fase', () => {
        render(<AdminIndex />);

        const users = screen.getByRole('link', { name: 'Gestionar usuarios' });
        expect(users.getAttribute('href')).toBe('/admin/usuarios');
        expect(
            screen
                .getByRole('link', { name: 'Tipos de tarea' })
                .getAttribute('href'),
        ).toBe('/admin/tipos-de-tarea');
        expect(
            screen.getByRole('link', { name: 'Estados' }).getAttribute('href'),
        ).toBe('/admin/estados');
        expect(
            screen
                .getByRole('link', { name: 'Abrir los ajustes' })
                .getAttribute('href'),
        ).toBe('/admin/ajustes');
        // Fase 3: los festivos ya tienen su página (/admin/festivos).
        expect(
            screen
                .getByRole('link', { name: 'Gestionar los festivos' })
                .getAttribute('href'),
        ).toBe('/admin/festivos');
        // … y la tarjeta «Festivos y ausencias» lleva también a las del equipo.
        expect(
            screen
                .getByRole('link', { name: 'Ver las ausencias del equipo' })
                .getAttribute('href'),
        ).toBe('/ausencias/equipo');
        expect(screen.queryByText('Llega en la Fase 3')).toBeNull();
        // Fase 7: auditoría y privacidad (D-074 y D-075).
        expect(
            screen
                .getByRole('link', { name: 'Abrir la auditoría' })
                .getAttribute('href'),
        ).toBe('/admin/auditoria');
        expect(
            screen
                .getByRole('link', { name: 'Abrir privacidad y datos' })
                .getAttribute('href'),
        ).toBe('/admin/privacidad');
        // Con las Fases 5 a 7 integradas, todas las áreas tienen ya sus enlaces.
        expect(screen.queryByText(/^Llega en la Fase/)).toBeNull();
    });

    it('sin el permiso, el enlace no aparece', () => {
        withAbilities({ manageSettings: false });
        render(<AdminIndex />);

        expect(
            screen.getByRole('link', { name: 'Gestionar usuarios' }),
        ).toBeTruthy();
        expect(
            screen.queryByRole('link', { name: 'Abrir los ajustes' }),
        ).toBeNull();
    });
});

const person = (overrides: Partial<AdminUser> = {}): AdminUser => ({
    id: 1,
    name: 'Laura Gómez',
    email: 'laura@audaxstudio.com',
    avatar: null,
    role: 'employee',
    department_id: 1,
    department: { id: 1, name: 'Diseño', color: '#0171FF' },
    is_active: true,
    last_login_at: '2026-09-24T08:00:00Z',
    two_factor_enabled: false,
    ...overrides,
});

const usersProps = (
    data: AdminUser[],
    filters: Partial<AdminUsersIndexProps['filters']> = {},
): AdminUsersIndexProps => ({
    users: {
        data,
        links: { first: null, last: null, prev: null, next: null },
        meta: {
            current_page: 1,
            from: data.length ? 1 : null,
            last_page: 1,
            path: '/admin/usuarios',
            per_page: 25,
            to: data.length || null,
            total: data.length,
        },
    },
    filters: {
        q: '',
        rol: null,
        departamento: null,
        estado: 'activos',
        ...filters,
    },
    departments: [{ id: 1, name: 'Diseño', color: '#0171FF' }],
    roles: ['admin', 'department_manager', 'employee'],
    canGrantAdmin: true,
});

describe('usuarios', () => {
    it('lista a las personas con su rol, departamento y estado; un solo h1', () => {
        const { container } = render(
            <AdminUsersIndex
                {...usersProps([
                    person(),
                    person({
                        id: 2,
                        name: 'Marc Puig',
                        last_login_at: null,
                        role: 'department_manager',
                        department: null,
                        department_id: null,
                    }),
                ])}
            />,
        );

        expect(container.querySelectorAll('h1')).toHaveLength(1);
        const table = screen.getByRole('table', { name: 'Usuarios' });
        const rows = within(table).getAllByRole('row');
        expect(rows).toHaveLength(3);
        expect(
            within(rows[1])
                .getByRole('link', { name: 'Laura Gómez' })
                .getAttribute('href'),
        ).toBe('/admin/usuarios/1');
        expect(within(rows[1]).getByText('Diseño')).toBeTruthy();
        expect(within(rows[2]).getByText('Invitación pendiente')).toBeTruthy();
        expect(within(rows[2]).getByText('Sin departamento')).toBeTruthy();
    });

    it('cambiar un filtro recarga el listado con la URL limpia', async () => {
        render(<AdminUsersIndex {...usersProps([person()])} />);

        await userEvent.selectOptions(
            screen.getByLabelText('Rol'),
            'department_manager',
        );

        expect(inertia.get).toHaveBeenCalledWith(
            '/admin/usuarios',
            { rol: 'department_manager' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('sin resultados con filtros, lo dice y ofrece quitarlos', () => {
        render(<AdminUsersIndex {...usersProps([], { q: 'nadie' })} />);

        expect(screen.getByText('No hay nadie con estos filtros')).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Quitar los filtros' }),
        ).toBeTruthy();
    });

    it('el diálogo de invitación solo ofrece el rol de admin a quien puede darlo y oculta la economía sin permiso', async () => {
        withAbilities({ viewFinancials: false });
        render(
            <AdminUsersIndex
                {...usersProps([person()])}
                canGrantAdmin={false}
            />,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Invitar a una persona' }),
        );
        const dialog = await screen.findByRole('dialog');

        const roles = within(within(dialog).getByLabelText('Rol'))
            .getAllByRole('option')
            .map((option) => option.textContent);
        expect(roles).toEqual(['Responsable de departamento', 'Empleado']);
        expect(within(dialog).queryByLabelText(/Coste por hora/)).toBeNull();
    });
});

const deactivateProps = (
    overrides: Partial<AdminUserDeactivateProps> = {},
): AdminUserDeactivateProps => ({
    user: { id: 7, name: 'Laura Baja', email: 'laura@audaxstudio.com' },
    blocked: null,
    tasks: [
        {
            id: 11,
            title: 'Maquetar la home',
            due_date: '2026-10-01',
            project: { id: 3, code: 'HOT-WEB', name: 'Web', color: '#0171FF' },
        },
        {
            id: 12,
            title: 'Revisar textos',
            due_date: null,
            project: { id: 3, code: 'HOT-WEB', name: 'Web', color: '#0171FF' },
        },
    ],
    timer: {
        task_title: 'Maquetar la home',
        started_at: '2026-09-24T07:00:00Z',
        elapsed_minutes: 95,
    },
    candidates: [
        {
            id: 2,
            name: 'Marc Sigue',
            avatar: null,
            department_id: 1,
            is_active: true,
        },
        {
            id: 3,
            name: 'Nerea',
            avatar: null,
            department_id: 1,
            is_active: true,
        },
    ],
    managedDepartments: ['Diseño'],
    ownedProjects: [{ id: 3, code: 'HOT-WEB', name: 'Web' }],
    ...overrides,
});

describe('asistente de baja', () => {
    it('«Pasar todas a» cambia la persona de todas las tareas y se puede ajustar una a una', async () => {
        render(<AdminUserDeactivate {...deactivateProps()} />);

        await userEvent.selectOptions(
            screen.getByLabelText('Pasar todas a'),
            '2',
        );

        const first = screen.getByLabelText(
            'Persona que sigue con «Maquetar la home»',
        ) as HTMLSelectElement;
        const second = screen.getByLabelText(
            'Persona que sigue con «Revisar textos»',
        ) as HTMLSelectElement;
        expect(first.value).toBe('2');
        expect(second.value).toBe('2');

        await userEvent.selectOptions(second, '');
        expect(second.value).toBe('');
        expect(first.value).toBe('2');
    });

    it('explica qué más pasará: temporizador y departamentos', () => {
        render(<AdminUserDeactivate {...deactivateProps()} />);

        expect(
            screen.getByText(
                /Tiene el temporizador en marcha en «Maquetar la home» \(1:35\)/,
            ),
        ).toBeTruthy();
        expect(
            screen.getByText('Dejará de ser responsable de: Diseño.'),
        ).toBeTruthy();
    });

    it('deja elegir el nuevo gestor principal de cada proyecto que dirige y lo envía', async () => {
        render(<AdminUserDeactivate {...deactivateProps()} />);

        expect(
            screen
                .getByRole('link', { name: 'HOT-WEB · Web' })
                .getAttribute('href'),
        ).toBe('/proyectos/3');

        const owner = screen.getByLabelText(
            'Nuevo gestor principal de «Web»',
        ) as HTMLSelectElement;
        expect(owner.value).toBe('');
        expect(
            within(owner).getByRole('option', { name: 'Sin cambios' }),
        ).toBeTruthy();

        // useForm envía con el router de @inertiajs/core.
        const post = vi.spyOn(coreRouter, 'post').mockImplementation(() => {});

        await userEvent.selectOptions(owner, '3');
        // «Pasar todas a» cambia las tareas sin perder lo elegido para los proyectos.
        await userEvent.selectOptions(
            screen.getByLabelText('Pasar todas a'),
            '2',
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Desactivar a Laura Baja' }),
        );

        expect(post).toHaveBeenCalledTimes(1);
        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/admin/usuarios/7/baja');
        expect(data.owners).toEqual([{ project_id: 3, owner_user_id: 3 }]);
        expect(data.default_assignee_id).toBe(2);
        post.mockRestore();
    });

    it('sin proyectos que dirija, lo dice en «Qué más pasará»', () => {
        render(
            <AdminUserDeactivate {...deactivateProps({ ownedProjects: [] })} />,
        );

        expect(
            screen.getByText(
                'No es gestor principal de ningún proyecto abierto.',
            ),
        ).toBeTruthy();
        expect(screen.queryByLabelText(/Nuevo gestor principal/)).toBeNull();
    });

    it('si no se puede desactivar, lo dice y no deja confirmar', () => {
        render(
            <AdminUserDeactivate
                {...deactivateProps({
                    blocked: 'No puedes desactivar tu propia cuenta.',
                })}
            />,
        );

        expect(screen.getByRole('alert').textContent).toContain(
            'No puedes desactivar tu propia cuenta.',
        );
        expect(
            (
                screen.getByRole('button', {
                    name: 'Desactivar a Laura Baja',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });
});

const settingsProps: AdminSettingsProps = {
    settings: {
        company_name: 'Audax Studio',
        require_2fa: false,
        timer_rounding_minutes: 1,
        timer_warning_hours: 10,
        hour_bank_alert_thresholds: [75, 90, 100],
        allow_hour_bank_overage: true,
        require_timesheet_approval: true,
        allow_future_time_entries: false,
        time_entry_description_required: false,
        max_attachment_mb: 50,
        default_work_minutes: [480, 480, 480, 480, 480, 0, 0],
        max_audio_seconds: 300,
        weekly_digest_enabled: true,
        occupancy_low_threshold: 70,
        occupancy_high_threshold: 110,
    },
    roundings: [1, 5, 10, 15, 30],
    serverUploadLimitMb: 20,
};

describe('ajustes', () => {
    it('añade umbrales hasta cinco y se pueden quitar', async () => {
        render(<AdminSettings {...settingsProps} />);

        const add = screen.getByRole('button', { name: 'Añadir un umbral' });
        await userEvent.click(add);
        await userEvent.click(add);

        expect(screen.getAllByLabelText(/Umbral \d \(%\)/)).toHaveLength(5);
        expect((add as HTMLButtonElement).disabled).toBe(true);

        await userEvent.click(
            screen.getByRole('button', { name: 'Quitar el umbral 90 %' }),
        );
        expect(screen.getAllByLabelText(/Umbral \d \(%\)/)).toHaveLength(4);
    });

    it('avisa si el tamaño de los adjuntos supera el límite del servidor', () => {
        render(<AdminSettings {...settingsProps} />);

        expect(screen.getByRole('status').textContent).toContain(
            'El servidor solo admite subidas de hasta 20 MB',
        );
    });

    it('los interruptores tienen etiqueta y explicación', () => {
        render(<AdminSettings {...settingsProps} />);

        const overage = screen.getByRole('switch', {
            name: 'Permitir el exceso',
        });
        expect(overage.getAttribute('aria-checked')).toBe('true');
        expect(overage.getAttribute('aria-describedby')).toBeTruthy();
    });
});

const status = (overrides: Partial<AdminTaskStatus>): AdminTaskStatus => ({
    id: 1,
    name: 'Por hacer',
    color: '#56667A',
    category: 'todo',
    position: 0,
    is_default: true,
    tasks_count: 0,
    ...overrides,
});

describe('estados', () => {
    const statuses = [
        status({}),
        status({
            id: 2,
            name: 'En revisión',
            category: 'in_progress',
            is_default: false,
            position: 1,
            tasks_count: 4,
        }),
        status({
            id: 3,
            name: 'Hecha',
            category: 'done',
            is_default: false,
            position: 2,
        }),
    ];

    it('el estado por defecto no se puede borrar y se marca con texto', () => {
        render(<AdminStatuses statuses={statuses} palette={['#0171FF']} />);

        expect(screen.getByText('Por defecto')).toBeTruthy();
        expect(
            screen.queryByRole('button', {
                name: 'Eliminar el estado Por hacer',
            }),
        ).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Eliminar el estado Hecha' }),
        ).toBeTruthy();
    });

    it('borrar un estado con tareas pide a qué estado pasan', async () => {
        render(<AdminStatuses statuses={statuses} palette={['#0171FF']} />);

        await userEvent.click(
            screen.getByRole('button', {
                name: 'Eliminar el estado En revisión',
            }),
        );
        const dialog = await screen.findByRole('dialog');

        expect(
            within(dialog).getByText(/Hay tareas en este estado \(4\)/),
        ).toBeTruthy();
        const submit = within(dialog).getByRole('button', {
            name: 'Eliminar',
        }) as HTMLButtonElement;
        expect(submit.disabled).toBe(true);

        const options = within(
            within(dialog).getByLabelText('Las tareas pasan a'),
        )
            .getAllByRole('option')
            .map((option) => option.textContent);
        expect(options).toEqual([
            'Elige un estado',
            'Por hacer (Por hacer)',
            'Hecha (Hecha)',
        ]);

        await userEvent.selectOptions(
            within(dialog).getByLabelText('Las tareas pasan a'),
            '3',
        );
        expect(submit.disabled).toBe(false);
    });
});
