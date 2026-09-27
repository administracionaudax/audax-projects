// @vitest-environment jsdom
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    buildTaskLookups,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import { TaskPanelFields } from '@/components/tasks/task-panel-fields';
import type {
    Project,
    ProjectTasksPageProps,
    TaskPanelData,
    TaskStatus,
} from '@/types';
import type { LinkedTask } from '@/types/planning';
import type { ShiftProposal } from '@/types/schedule';

type Dates = { start_date: string | null; due_date: string };

const mocks = vi.hoisted(() => ({
    patch: vi.fn<(url: string, data: unknown) => void>(),
    preview: vi.fn<(taskId: number, dates: Dates) => Promise<unknown[]>>(),
    save: vi.fn<
        (
            taskId: number,
            body: Dates & { shift_successors?: boolean },
            options: { onFinish?: () => void },
        ) => void
    >(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: {
        patch: (url: string, data: unknown) => mocks.patch(url, data),
        visit: vi.fn(),
    },
}));

vi.mock('@/components/planning/reschedule-requests', () => ({
    RescheduleError: class extends Error {},
    fetchReschedulePreview: (taskId: number, dates: Dates) =>
        mocks.preview(taskId, dates),
    saveReschedule: (
        taskId: number,
        body: Dates & { shift_successors?: boolean },
        options: { onFinish?: () => void },
    ) => mocks.save(taskId, body, options),
}));

// Aquí solo importan las peticiones: basta un campo de fecha. El selector real, con su popover y
// el foco, se prueba en planning-panel-focus.test.tsx.
vi.mock('@/components/domain/date-picker', () => ({
    DatePicker: ({
        id,
        value,
        onChange,
        disabled,
    }: {
        id?: string;
        value: string | null;
        onChange: (value: string | null) => void;
        disabled?: boolean;
    }) => (
        <input
            id={id}
            type="date"
            value={value ?? ''}
            disabled={disabled}
            onChange={(event) => onChange(event.target.value || null)}
        />
    ),
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

function successor(): LinkedTask {
    return {
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
}

function panel(successors: LinkedTask[]): TaskPanelData {
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
        dependencies: { predecessors: [], successors },
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

function renderFields(data: TaskPanelData) {
    const lookups = buildTaskLookups({
        project,
        statuses,
        types: [],
        banks: [],
        users: [],
        currentUser: { id: 1, department_id: null },
        can: { create: true, update: true },
        maxAttachmentMb: 50,
    } satisfies Pick<
        ProjectTasksPageProps,
        | 'project'
        | 'statuses'
        | 'types'
        | 'banks'
        | 'users'
        | 'currentUser'
        | 'can'
        | 'maxAttachmentMb'
    >);

    render(
        <TaskLookupsProvider value={lookups}>
            <TaskPanelFields panel={data} />
        </TaskLookupsProvider>,
    );
}

function changeDue(value: string) {
    fireEvent.change(screen.getByLabelText('Vencimiento'), {
        target: { value },
    });
}

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

beforeEach(() => {
    mocks.patch.mockReset();
    mocks.preview.mockReset();
    mocks.save.mockReset();
});

describe('fecha de entrega en el panel de una tarea con sucesoras (D-057)', () => {
    it('sin sucesoras se guarda como cualquier otro campo', () => {
        renderFields(panel([]));

        changeDue('2026-10-25');

        expect(mocks.patch).toHaveBeenCalledWith('/tareas/10', {
            due_date: '2026-10-25',
        });
        expect(mocks.preview).not.toHaveBeenCalled();
    });

    it('con sucesoras pide la propuesta con el inicio actual y, sin conflictos, guarda sin desplazar nada', async () => {
        mocks.preview.mockResolvedValue([]);
        renderFields(panel([successor()]));

        changeDue('2026-10-22');

        await waitFor(() =>
            expect(mocks.save).toHaveBeenCalledWith(
                10,
                {
                    start_date: '2026-10-12',
                    due_date: '2026-10-22',
                    shift_successors: false,
                },
                expect.anything(),
            ),
        );
        expect(mocks.preview).toHaveBeenCalledWith(10, {
            start_date: '2026-10-12',
            due_date: '2026-10-22',
        });
        expect(mocks.patch).not.toHaveBeenCalled();
    });

    it('si alguna sucesora quedaría en conflicto, pregunta antes y «Mover también las sucesoras» las desplaza', async () => {
        const user = userEvent.setup();
        mocks.preview.mockResolvedValue([proposal]);
        renderFields(panel([successor()]));

        changeDue('2026-10-25');

        const dialog = await screen.findByRole('dialog');
        expect(dialog.textContent).toContain('Publicación');
        expect(mocks.save).not.toHaveBeenCalled();

        await user.click(
            screen.getByRole('button', {
                name: 'Mover también las sucesoras',
            }),
        );

        expect(mocks.save).toHaveBeenCalledWith(
            10,
            {
                start_date: '2026-10-12',
                due_date: '2026-10-25',
                shift_successors: true,
            },
            expect.anything(),
        );
        expect(mocks.patch).not.toHaveBeenCalled();
    });

    it('«Cancelar» no guarda nada', async () => {
        const user = userEvent.setup();
        mocks.preview.mockResolvedValue([proposal]);
        renderFields(panel([successor()]));

        changeDue('2026-10-25');
        await screen.findByRole('dialog');
        await user.click(screen.getByRole('button', { name: 'Cancelar' }));

        expect(mocks.save).not.toHaveBeenCalled();
        expect(mocks.patch).not.toHaveBeenCalled();
    });

    it('quitar la fecha no crea conflictos: se guarda sin propuesta', () => {
        renderFields(panel([successor()]));

        changeDue('');

        expect(mocks.patch).toHaveBeenCalledWith('/tareas/10', {
            due_date: null,
        });
        expect(mocks.preview).not.toHaveBeenCalled();
    });
});
