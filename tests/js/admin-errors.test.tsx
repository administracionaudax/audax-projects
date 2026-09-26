// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import AdminDepartments from '@/pages/admin/departments/index';
import AdminUserEdit from '@/pages/admin/users/edit';
import type {
    AdminDepartment,
    AdminUserEditProps,
    AdminWorkSchedule,
} from '@/types';

/*
| Las acciones sin formulario (borrar desde un diálogo de confirmación, reenviar, reactivar…) no
| pierden los errores del servidor: se enseñan en un aviso.
*/

const toast = vi.hoisted(() => ({
    error: vi.fn(),
    success: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
}));

vi.mock('sonner', () => ({ toast }));

const page = vi.hoisted(() => ({
    url: '/admin/departamentos',
    props: {} as Record<string, unknown>,
}));

type VisitOptions = {
    onStart?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

const inertia = vi.hoisted(() => ({
    delete: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
        router: {
            get: vi.fn(),
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

/** Simula una visita que vuelve con errores de validación. */
function failWith(errors: Record<string, string>) {
    return (_url: string, ...rest: unknown[]) => {
        const options = (rest.at(-1) ?? {}) as VisitOptions;
        options.onStart?.();
        options.onError?.(errors);
        options.onFinish?.();
    };
}

beforeEach(() => {
    page.props = {
        auth: {
            user: null,
            can: {
                viewHourBanks: true,
                viewAdmin: true,
                viewFinancials: true,
                createClients: true,
                createProjects: true,
                approveTime: true,
                lockTime: true,
                manageUsers: true,
                manageSettings: true,
            },
        },
    };
    toast.error.mockReset();
    inertia.delete.mockReset();
    inertia.post.mockReset();
});

const department: AdminDepartment = {
    id: 4,
    name: 'Diseño',
    color: '#0171FF',
    managers: [],
    users_count: 0,
    inactive_users_count: 0,
    open_hour_banks_count: 0,
    can_delete: true,
};

describe('errores de las acciones sin formulario', () => {
    it('borrar un departamento que ya no se puede borrar avisa del motivo', async () => {
        const message =
            'No se puede eliminar: tiene personas activas. Cámbialas antes de departamento.';
        inertia.delete.mockImplementation(failWith({ department: message }));

        render(
            <AdminDepartments
                departments={[department]}
                managerOptions={[]}
                palette={['#0171FF']}
            />,
        );

        await userEvent.click(
            screen.getByRole('button', {
                name: 'Eliminar el departamento Diseño',
            }),
        );
        await userEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Eliminar',
            }),
        );

        expect(inertia.delete).toHaveBeenCalledWith(
            '/admin/departamentos/4',
            expect.objectContaining({ onError: expect.any(Function) }),
        );
        expect(toast.error).toHaveBeenCalledWith(message);
    });

    it('con personas de baja, el departamento no se puede borrar y lo explica', () => {
        render(
            <AdminDepartments
                departments={[
                    {
                        ...department,
                        inactive_users_count: 2,
                        can_delete: false,
                    },
                ]}
                managerOptions={[]}
                palette={['#0171FF']}
            />,
        );

        expect(screen.getByText('+2 de baja')).toBeTruthy();
        expect(
            screen
                .getByRole('button', {
                    name: 'Eliminar el departamento Diseño',
                })
                .getAttribute('aria-disabled'),
        ).toBe('true');
        expect(
            screen.getByText(
                'No se puede eliminar mientras tenga personas (también de baja) o bolsas abiertas.',
            ),
        ).toBeTruthy();
    });

    it('borrar una versión de la jornada que ya ha empezado avisa del motivo', async () => {
        const message =
            'Esta versión ya ha empezado: no se puede cambiar. Crea una versión nueva.';
        inertia.delete.mockImplementation(failWith({ schedule: message }));

        const upcoming: AdminWorkSchedule = {
            id: 9,
            valid_from: '2099-01-01',
            valid_to: null,
            week: [480, 480, 480, 480, 480, 0, 0],
            weekly_minutes: 2400,
            is_current: false,
            is_editable: true,
        };
        const props: AdminUserEditProps = {
            user: {
                id: 7,
                name: 'Laura Gómez',
                email: 'laura@audaxstudio.com',
                avatar: null,
                role: 'employee',
                department_id: null,
                is_active: true,
                last_login_at: null,
                two_factor_enabled: false,
            },
            schedules: [upcoming],
            departments: [],
            roles: ['admin', 'department_manager', 'employee'],
            openTasksCount: 0,
            hasActiveTimer: false,
            can: {
                manage: true,
                grantAdmin: true,
                changeRole: true,
                deactivate: true,
                viewFinancials: true,
            },
        };

        render(<AdminUserEdit {...props} />);

        await userEvent.click(
            screen.getByRole('button', {
                name: 'Eliminar la jornada que empieza el 01/01/2099',
            }),
        );
        await userEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Eliminar',
            }),
        );

        expect(toast.error).toHaveBeenCalledWith(message);
    });

    it('sin mensaje del servidor, avisa con un texto genérico', () => {
        toastVisitErrors({});

        expect(toast.error).toHaveBeenCalledWith(
            'No se ha podido completar. Recarga la página y vuelve a intentarlo.',
        );
    });
});
