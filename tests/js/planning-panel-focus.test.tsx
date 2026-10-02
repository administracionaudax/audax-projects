// @vitest-environment jsdom
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    buildTaskLookups,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import { TaskPanel } from '@/components/tasks/task-panel';
import type { Project, TaskPanelData, TaskStatus } from '@/types';
import type { LinkedTask } from '@/types/planning';
import type { ShiftProposal } from '@/types/schedule';

/**
 * El foco al cambiar la entrega de una tarea con sucesoras desde el panel (D-057, WCAG 2.4.3),
 * con el panel, el selector de fecha y el diálogo de verdad: nunca acaba en <body>.
 */

type Dates = { start_date: string | null; due_date: string };
type SaveOptions = { onFinish?: () => void };

const mocks = vi.hoisted(() => ({
    patch: vi.fn<(url: string, data: unknown) => void>(),
    preview: vi.fn<(taskId: number, dates: Dates) => Promise<unknown[]>>(),
    save: vi.fn<
        (
            taskId: number,
            body: Dates & { shift_successors?: boolean },
            options: SaveOptions,
        ) => void
    >(),
    info: vi.fn<(message: string) => void>(),
}));

vi.mock('sonner', async (importOriginal) => ({
    ...(await importOriginal<typeof import('sonner')>()),
    toast: Object.assign(vi.fn(), {
        info: (message: string) => mocks.info(message),
        error: vi.fn(),
        success: vi.fn(),
    }),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({
        url: '/proyectos/1/tareas?tarea=10',
        props: { timer: null },
    }),
    Link: ({
        href,
        children,
        preserveScroll: _p,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        preserveScroll?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        visit: vi.fn(),
        get: vi.fn(),
        reload: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
        patch: (url: string, data: unknown) => mocks.patch(url, data),
    },
}));

vi.mock('@/components/planning/reschedule-requests', () => ({
    RescheduleError: class extends Error {},
    fetchReschedulePreview: (taskId: number, dates: Dates) =>
        mocks.preview(taskId, dates),
    saveReschedule: (
        taskId: number,
        body: Dates & { shift_successors?: boolean },
        options: SaveOptions,
    ) => mocks.save(taskId, body, options),
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

const project: Project = {
    id: 1,
    code: 'ACME',
    name: 'Web ACME',
    color: '#0171FF',
    description: null,
    client: { id: 3, name: 'ACME' },
    client_id: 3,
    billing_type: 'time_and_materials',
    status: 'active',
    start_date: null,
    due_date: null,
    budget_minutes: null,
    owner_user_id: 1,
    is_internal: false,
};

const successor: LinkedTask = {
    dependency_id: 2,
    conflict: false,
    task: {
        id: 30,
        title: 'Publicación',
        parent_task_id: null,
        status_id: 1,
        start_date: '2026-10-21',
        due_date: '2026-10-23',
        is_milestone: false,
        is_completed: false,
    },
};

const proposal: ShiftProposal = {
    task_id: 30,
    title: 'Publicación',
    start_date: '2026-10-21',
    due_date: '2026-10-23',
    new_start_date: '2026-10-26',
    new_due_date: '2026-10-28',
    shift_days: 5,
    predecessor_id: 10,
};

function panelData(): TaskPanelData {
    return {
        task: {
            id: 10,
            project_id: 1,
            hour_bank_id: null,
            parent_task_id: null,
            title: 'Maquetación',
            description: null,
            task_type_id: null,
            status_id: 1,
            priority: 'normal',
            assignee: null,
            assignee_user_id: null,
            start_date: '2026-10-12',
            due_date: '2026-10-20',
            estimated_minutes: null,
            is_billable: true,
            is_milestone: false,
            is_completed: false,
            position: 0,
            completed_at: null,
            creator: null,
            created_at: null,
            updated_at: null,
        },
        project: {
            id: 1,
            code: 'ACME',
            name: 'Web ACME',
            uses_hour_banks: false,
            is_internal: false,
        },
        parent: null,
        subtasks: [],
        estimate_from_subtasks: false,
        effective_estimated_minutes: null,
        watchers: [],
        is_watching: false,
        attachments: [],
        comments: [],
        time_entries: [],
        time_visible_minutes: 0,
        has_time: false,
        activity: [],
        dependencies: { predecessors: [], successors: [successor] },
        reaction_emojis: [],
        delete_blocked: null,
        can: {
            update: true,
            delete: true,
            comment: true,
            move: true,
            log_time: false,
        },
    };
}

function renderPanel() {
    const lookups = buildTaskLookups({
        project,
        statuses,
        types: [],
        banks: [],
        users: [],
        currentUser: { id: 1, department_id: null },
        can: { create: true, update: true },
        maxAttachmentMb: 50,
    });

    render(
        <TaskLookupsProvider value={lookups}>
            <TaskPanel
                panel={panelData()}
                loading={false}
                onOpen={vi.fn()}
                onClose={vi.fn()}
            />
        </TaskLookupsProvider>,
    );
}

/** Botón del selector de «Vencimiento» del panel. */
function dueTrigger(): HTMLElement {
    return screen.getByLabelText('Vencimiento');
}

/** Con el teclado: abre el selector de «Vencimiento», va al día y lo elige con Enter. */
async function pickDue(user: ReturnType<typeof userEvent.setup>, day: string) {
    dueTrigger().focus();
    await user.keyboard('{Enter}');

    const button = await waitFor(() => {
        const found = document.querySelector<HTMLElement>(
            `[data-day="${day}"] button`,
        );
        expect(found).not.toBeNull();

        return found as HTMLElement;
    });

    button.focus();
    await user.keyboard('{Enter}');
}

/** Espera al diálogo de conflictos (el panel también es un diálogo). */
function conflictDialog(): Promise<HTMLElement> {
    return screen.findByRole('dialog', {
        name: 'Hay tareas que dependen de esta',
    });
}

beforeEach(() => {
    mocks.patch.mockReset();
    mocks.preview.mockReset();
    mocks.save.mockReset();
    mocks.info.mockReset();
});

describe('el foco al cambiar la entrega de una tarea con sucesoras desde el panel', () => {
    it('sin conflictos, el foco vuelve a «Vencimiento» al cerrarse el selector y sigue ahí al guardar', async () => {
        const user = userEvent.setup();
        mocks.preview.mockResolvedValue([]);
        renderPanel();
        const trigger = dueTrigger();

        await pickDue(user, '2026-10-22');

        await waitFor(() => expect(mocks.save).toHaveBeenCalledTimes(1));
        expect(mocks.save.mock.calls[0][1]).toEqual({
            start_date: '2026-10-12',
            due_date: '2026-10-22',
            shift_successors: false,
        });
        await waitFor(() => expect(document.activeElement).toBe(trigger));

        await act(async () => mocks.save.mock.calls[0][2].onFinish?.());

        expect(document.activeElement).toBe(trigger);
    });

    it('«Mover también las sucesoras»: al guardar y cerrarse el diálogo, el foco vuelve a «Vencimiento»', async () => {
        const user = userEvent.setup();
        mocks.preview.mockResolvedValue([proposal]);
        renderPanel();
        const trigger = dueTrigger();

        await pickDue(user, '2026-10-25');
        const dialog = await conflictDialog();
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Mover también las sucesoras',
            }),
        );

        expect(mocks.save).toHaveBeenCalledTimes(1);
        expect(mocks.save.mock.calls[0][1]).toEqual({
            start_date: '2026-10-12',
            due_date: '2026-10-25',
            shift_successors: true,
        });

        // Termina la recarga: se cierra el diálogo.
        await act(async () => mocks.save.mock.calls[0][2].onFinish?.());

        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', {
                    name: 'Hay tareas que dependen de esta',
                }),
            ).toBeNull(),
        );
        await waitFor(() => expect(document.activeElement).toBe(trigger));
    });

    it('«Solo esta tarea» también devuelve el foco a «Vencimiento»', async () => {
        const user = userEvent.setup();
        mocks.preview.mockResolvedValue([proposal]);
        renderPanel();
        const trigger = dueTrigger();

        await pickDue(user, '2026-10-25');
        const dialog = await conflictDialog();
        await user.click(
            within(dialog).getByRole('button', { name: 'Solo esta tarea' }),
        );
        expect(mocks.save.mock.calls[0][1]).toMatchObject({
            shift_successors: false,
        });
        await act(async () => mocks.save.mock.calls[0][2].onFinish?.());

        await waitFor(() => expect(document.activeElement).toBe(trigger));
    });

    it('«Cancelar» no guarda y el foco vuelve a «Vencimiento»', async () => {
        const user = userEvent.setup();
        mocks.preview.mockResolvedValue([proposal]);
        renderPanel();
        const trigger = dueTrigger();

        await pickDue(user, '2026-10-25');
        const dialog = await conflictDialog();
        await user.click(
            within(dialog).getByRole('button', { name: 'Cancelar' }),
        );

        expect(mocks.save).not.toHaveBeenCalled();
        await waitFor(() => expect(document.activeElement).toBe(trigger));
    });

    it('Escape en el diálogo cancela sin cerrar el panel y el foco vuelve a «Vencimiento»', async () => {
        const user = userEvent.setup();
        mocks.preview.mockResolvedValue([proposal]);
        renderPanel();
        const trigger = dueTrigger();

        await pickDue(user, '2026-10-25');
        await conflictDialog();
        await user.keyboard('{Escape}');

        expect(mocks.save).not.toHaveBeenCalled();
        await waitFor(() => expect(document.activeElement).toBe(trigger));
        expect(
            screen.getByRole('dialog', { name: 'Maquetación' }),
        ).toBeTruthy();
    });

    it('mientras hay un cambio en curso, «Vencimiento» conserva el foco y otro cambio solo se avisa', async () => {
        const user = userEvent.setup();
        let answer: (value: ShiftProposal[]) => void = () => {};
        mocks.preview.mockImplementationOnce(
            () =>
                new Promise<ShiftProposal[]>((resolve) => {
                    answer = resolve;
                }),
        );
        renderPanel();
        const trigger = dueTrigger();

        await pickDue(user, '2026-10-22');

        // El botón no se desactiva: el selector le devuelve el foco.
        await waitFor(() => expect(document.activeElement).toBe(trigger));
        expect(trigger.hasAttribute('disabled')).toBe(false);

        // Otro día antes de que llegue la propuesta: no se pide nada y se avisa.
        await pickDue(user, '2026-10-24');

        expect(mocks.preview).toHaveBeenCalledTimes(1);
        expect(mocks.patch).not.toHaveBeenCalled();
        expect(mocks.info).toHaveBeenCalledWith(
            'Espera a que termine el cambio de fecha que está en curso.',
        );
        await waitFor(() => expect(document.activeElement).toBe(trigger));

        // Llega la propuesta del primero (sin conflictos) y se guarda el primero.
        await act(async () => answer([]));
        expect(mocks.save).toHaveBeenCalledTimes(1);
        expect(mocks.save.mock.calls[0][1]).toMatchObject({
            due_date: '2026-10-22',
        });
    });
});
