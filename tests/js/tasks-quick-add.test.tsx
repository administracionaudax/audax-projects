// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QuickAddTask } from '@/components/tasks/quick-add-task';
import {
    buildTaskLookups,
    defaultBankId,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import type { Project, TaskBankOption, TaskStatus } from '@/types';

type Options = {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
    only?: string[];
};

const server = vi.hoisted(() => ({
    post: vi.fn<
        (url: string, data: Record<string, unknown>, options: Options) => void
    >(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ url: '/proyectos/7/tareas', props: { timer: null } }),
    router: {
        post: (url: string, data: Record<string, unknown>, options: Options) =>
            server.post(url, data, options),
        patch: vi.fn(),
        visit: vi.fn(),
    },
}));

const statuses: TaskStatus[] = [
    {
        id: 1,
        name: 'Por hacer',
        color: '#56667A',
        category: 'todo',
        position: 0,
        is_default: true,
    },
];

function project(billing: Project['billing_type']): Project {
    return {
        id: 7,
        code: 'ACME',
        name: 'Web ACME',
        color: '#0171FF',
        description: null,
        client_id: 3,
        billing_type: billing,
        status: 'active',
        start_date: null,
        due_date: null,
        budget_minutes: null,
        owner_user_id: 1,
        is_internal: false,
    };
}

function bank(
    id: number,
    name: string,
    departmentId: number | null,
    isOpen = true,
): TaskBankOption {
    return {
        id,
        name,
        status: isOpen ? 'active' : 'closed',
        is_open: isOpen,
        department_id: departmentId,
        department:
            departmentId === null
                ? null
                : {
                      id: departmentId,
                      name: `Dep ${departmentId}`,
                      color: '#0171FF',
                  },
        consumed_pct: 40,
    };
}

function renderQuickAdd(
    options: {
        billing?: Project['billing_type'];
        banks?: TaskBankOption[];
        departmentId?: number | null;
        canCreate?: boolean;
        props?: Partial<Parameters<typeof QuickAddTask>[0]>;
    } = {},
) {
    const lookups = buildTaskLookups({
        project: project(options.billing ?? 'time_and_materials'),
        statuses,
        types: [],
        banks: options.banks ?? [],
        users: [],
        currentUser: { id: 1, department_id: options.departmentId ?? null },
        can: { create: options.canCreate ?? true, update: true },
        maxAttachmentMb: 50,
    });

    return render(
        <TaskLookupsProvider value={lookups}>
            <QuickAddTask
                label="Nueva tarea en «Por hacer»"
                defaults={{ status_id: 1 }}
                {...options.props}
            />
        </TaskLookupsProvider>,
    );
}

beforeEach(() => {
    server.post.mockReset();
});

describe('creación rápida', () => {
    it('crea la tarea al pulsar Intro, vacía el campo y deja el foco para seguir escribiendo', async () => {
        const user = userEvent.setup();
        server.post.mockImplementation((_url, _data, options) => {
            options.onStart?.();
            options.onSuccess?.();
            options.onFinish?.();
        });
        renderQuickAdd();

        const input = screen.getByRole('textbox', {
            name: 'Nueva tarea en «Por hacer»',
        });
        await user.type(input, 'Maquetar la home{Enter}');

        expect(server.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = server.post.mock.calls[0];
        expect(url).toBe('/proyectos/7/tareas');
        expect(data).toEqual({ status_id: 1, title: 'Maquetar la home' });
        expect(options.only).toEqual([
            'tasks',
            'panel',
            'hiddenCompletedCount',
            'calendar',
        ]);

        await waitFor(() => expect((input as HTMLInputElement).value).toBe(''));
        expect(document.activeElement).toBe(input);

        await user.type(input, 'Segunda tarea{Enter}');
        expect(server.post).toHaveBeenCalledTimes(2);
        expect(server.post.mock.calls[1][1]).toMatchObject({
            title: 'Segunda tarea',
        });
    });

    it('no envía títulos vacíos', async () => {
        const user = userEvent.setup();
        renderQuickAdd();

        await user.type(screen.getByRole('textbox'), '   {Enter}');

        expect(server.post).not.toHaveBeenCalled();
    });

    it('muestra el error del servidor junto al campo', async () => {
        const user = userEvent.setup();
        server.post.mockImplementation((_url, _data, options) => {
            options.onError?.({ title: 'El campo título es obligatorio.' });
            options.onFinish?.();
        });
        renderQuickAdd();

        const input = screen.getByRole('textbox');
        await user.type(input, 'X{Enter}');

        expect(screen.getByRole('alert').textContent).toContain(
            'El campo título es obligatorio.',
        );
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect((input as HTMLInputElement).value).toBe('X');
    });

    it('en un proyecto de bolsas envía la bolsa, por defecto la abierta del departamento del usuario', async () => {
        const user = userEvent.setup();
        renderQuickAdd({
            billing: 'hour_bank',
            departmentId: 2,
            banks: [
                bank(5, 'General', null),
                bank(6, 'Desarrollo', 2),
                bank(7, 'Cerrada', 2, false),
            ],
        });

        expect(
            screen.getByRole('combobox', { name: 'Bolsa de la nueva tarea' })
                .textContent,
        ).toContain('Desarrollo');

        await user.type(screen.getByRole('textbox'), 'Con bolsa{Enter}');

        expect(server.post.mock.calls[0][1]).toEqual({
            status_id: 1,
            title: 'Con bolsa',
            hour_bank_id: 6,
        });
    });

    it('si no hay bolsas abiertas, pide una antes de crear', async () => {
        const user = userEvent.setup();
        renderQuickAdd({
            billing: 'hour_bank',
            banks: [bank(7, 'Cerrada', null, false)],
        });

        await user.type(screen.getByRole('textbox'), 'Sin bolsa{Enter}');

        expect(server.post).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toContain(
            'Elige la bolsa',
        );
    });

    it('las subtareas no piden bolsa: usan la del padre', async () => {
        const user = userEvent.setup();
        renderQuickAdd({
            billing: 'hour_bank',
            banks: [bank(5, 'General', null)],
            props: { parentId: 42, defaults: {}, label: 'Nueva subtarea' },
        });

        expect(screen.queryByRole('combobox')).toBeNull();

        await user.type(
            screen.getByRole('textbox', { name: 'Nueva subtarea' }),
            'Hija{Enter}',
        );

        expect(server.post.mock.calls[0][1]).toEqual({
            title: 'Hija',
            parent_task_id: 42,
        });
    });

    it('sin permiso para crear no se muestra', () => {
        renderQuickAdd({ canCreate: false });

        expect(screen.queryByRole('textbox')).toBeNull();
    });

    it('elige la bolsa por defecto según el departamento (SPEC §8.3)', () => {
        const banks = [
            bank(1, 'A', null),
            bank(2, 'B', 3),
            bank(3, 'C', 3, false),
        ];

        expect(defaultBankId(banks, 3)).toBe(2);
        expect(defaultBankId(banks, 9)).toBe(1);
        expect(defaultBankId(banks, null)).toBe(1);
        expect(defaultBankId([bank(3, 'C', 3, false)], 3)).toBeNull();
    });
});
