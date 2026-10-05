// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QuickAddTask } from '@/components/tasks/quick-add-task';
import { TaskCreateDialog } from '@/components/tasks/task-create-dialog';
import type { TaskCreateParent } from '@/components/tasks/task-create-dialog';
import {
    buildTaskLookups,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import type { Project, TaskStatus } from '@/types';

/*
| Diálogo para crear una subtarea (o una tarea con «Más datos») con sus datos (D-163).
*/

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
    {
        id: 2,
        name: 'En curso',
        color: '#0171FF',
        category: 'in_progress',
        position: 1,
        is_default: false,
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

const ana = {
    id: 4,
    name: 'Ana García',
    avatar: null,
    department_id: 2,
    is_active: true,
    is_member: true,
};

const parent: TaskCreateParent = {
    id: 42,
    title: 'Desarrollo web',
    assignee_user_id: 4,
    task_type_id: 9,
    priority: 'high',
    due_date: '2026-10-30',
};

function lookups(billing: Project['billing_type'] = 'time_and_materials') {
    return buildTaskLookups({
        project: project(billing),
        statuses,
        types: [
            {
                id: 9,
                name: 'Desarrollo',
                color: '#0171FF',
                icon: null,
                department_id: null,
                is_billable_default: true,
                is_active: true,
                position: 0,
            },
        ],
        banks: [
            {
                id: 6,
                name: 'Desarrollo',
                status: 'active',
                is_open: true,
                department_id: 2,
                department: null,
                consumed_pct: 10,
            },
        ],
        users: [ana],
        currentUser: { id: 1, department_id: 2 },
        can: { create: true, update: true },
        maxAttachmentMb: 50,
    });
}

function renderDialog(onOpenChange = vi.fn()) {
    render(
        <TaskLookupsProvider value={lookups()}>
            <TaskCreateDialog
                open
                onOpenChange={onOpenChange}
                parent={parent}
            />
        </TaskLookupsProvider>,
    );

    return onOpenChange;
}

function succeed() {
    server.post.mockImplementation((_url, _data, options) => {
        options.onStart?.();
        options.onSuccess?.();
        options.onFinish?.();
    });
}

beforeEach(() => {
    server.post.mockReset();
});

describe('nueva subtarea con sus datos', () => {
    it('hereda del padre el responsable, el tipo, la prioridad y la entrega, y guarda con Intro', async () => {
        const user = userEvent.setup();
        succeed();
        const onOpenChange = renderDialog();

        expect(
            screen.getByRole('heading', { name: 'Nueva subtarea' }),
        ).toBeTruthy();
        const title = screen.getByLabelText('Título');
        expect(document.activeElement).toBe(title);
        expect(
            screen.getByRole('combobox', { name: 'Responsable' }).textContent,
        ).toContain('Ana García');
        expect(
            screen.getByRole('combobox', { name: 'Tipo' }).textContent,
        ).toContain('Desarrollo');
        expect(
            screen.getByRole('button', { name: 'Vencimiento' }).textContent,
        ).toContain('30/10/2026');

        await user.type(screen.getByLabelText('Horas estimadas'), '1h30');
        await user.type(title, 'Formularios{Enter}');

        expect(server.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = server.post.mock.calls[0];
        expect(url).toBe('/proyectos/7/tareas');
        expect(data).toEqual({
            title: 'Formularios',
            status_id: 1,
            priority: 'high',
            assignee_user_id: 4,
            task_type_id: 9,
            start_date: null,
            due_date: '2026-10-30',
            estimated_minutes: 90,
            parent_task_id: 42,
        });
        expect(options.only).toContain('panel');
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('con «Crear otra al guardar» sigue abierto, vacía el título y la estimación y conserva lo demás', async () => {
        const user = userEvent.setup();
        succeed();
        const onOpenChange = renderDialog();

        await user.click(
            screen.getByRole('switch', { name: 'Crear otra al guardar' }),
        );
        await user.type(screen.getByLabelText('Horas estimadas'), '2');
        await user.type(screen.getByLabelText('Título'), 'Primera{Enter}');

        expect(onOpenChange).not.toHaveBeenCalled();
        const title = screen.getByLabelText('Título') as HTMLInputElement;
        await waitFor(() => expect(title.value).toBe(''));
        expect(document.activeElement).toBe(title);
        expect(
            (screen.getByLabelText('Horas estimadas') as HTMLInputElement)
                .value,
        ).toBe('');
        expect(screen.getByRole('status').textContent).toBe(
            'Creada «Primera». Escribe la siguiente.',
        );

        await user.type(title, 'Segunda{Enter}');
        expect(server.post.mock.calls[1][1]).toMatchObject({
            title: 'Segunda',
            assignee_user_id: 4,
            due_date: '2026-10-30',
            estimated_minutes: null,
        });
    });

    it('sin título no envía nada y lo dice junto al campo', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.click(
            screen.getByRole('button', { name: 'Crear subtarea' }),
        );

        expect(screen.getByText('Escribe el título.')).toBeTruthy();
        expect(
            screen.getByLabelText('Título').getAttribute('aria-invalid'),
        ).toBe('true');
        expect(server.post).not.toHaveBeenCalled();
    });

    it('muestra los errores del servidor junto a su campo', async () => {
        const user = userEvent.setup();
        server.post.mockImplementation((_url, _data, options) => {
            options.onError?.({
                due_date: 'El vencimiento no puede ser anterior al inicio.',
            });
            options.onFinish?.();
        });
        renderDialog();

        await user.type(screen.getByLabelText('Título'), 'X{Enter}');

        expect(
            screen.getByText('El vencimiento no puede ser anterior al inicio.'),
        ).toBeTruthy();
    });
});

describe('«Más datos» en el alta rápida', () => {
    it('abre el diálogo con el título escrito y, en un proyecto de bolsas, pide la bolsa', async () => {
        const user = userEvent.setup();
        succeed();
        render(
            <TaskLookupsProvider value={lookups('hour_bank')}>
                <QuickAddTask
                    label="Nueva tarea en «En curso»"
                    defaults={{ status_id: 2 }}
                />
            </TaskLookupsProvider>,
        );

        const input = screen.getByRole('textbox', {
            name: 'Nueva tarea en «En curso»',
        });
        await user.type(input, 'Maquetar la home');
        await user.click(
            screen.getByRole('button', {
                name: 'Crear con más datos: Nueva tarea en «En curso»',
            }),
        );

        expect(
            screen.getByRole('heading', { name: 'Nueva tarea' }),
        ).toBeTruthy();
        expect(
            (screen.getByLabelText('Título') as HTMLInputElement).value,
        ).toBe('Maquetar la home');
        expect(
            screen.getByRole('combobox', { name: 'Bolsa' }).textContent,
        ).toContain('Desarrollo');

        await user.click(screen.getByRole('button', { name: 'Crear tarea' }));

        expect(server.post.mock.calls[0][1]).toMatchObject({
            title: 'Maquetar la home',
            status_id: 2,
            hour_bank_id: 6,
        });
        await waitFor(() => expect((input as HTMLInputElement).value).toBe(''));
    });
});
