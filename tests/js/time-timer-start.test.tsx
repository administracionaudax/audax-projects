// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TimerStartButton } from '@/components/time/timer-start-button';
import type { LoggableTask } from '@/types';

const post = vi.hoisted(() => vi.fn());
const toastError = vi.hoisted(() => vi.fn());

vi.mock('sonner', async (importOriginal) => ({
    ...(await importOriginal<typeof import('sonner')>()),
    toast: Object.assign(vi.fn(), { error: toastError }),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { timer: null } }),
    router: {
        post: (...args: unknown[]) => post(...args),
        delete: vi.fn(),
        on: () => () => {},
    },
}));

const task: LoggableTask = {
    id: 12,
    title: 'Maquetar la home',
    project_id: 3,
    project: {
        id: 3,
        code: 'ACME-WEB',
        name: 'Web de ACME',
        color: '#3366ff',
        is_internal: false,
    },
    hour_bank: null,
    is_billable: true,
    is_milestone: false,
    is_completed: false,
    is_deleted: false,
};

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
    post.mockReset();
    toastError.mockReset();
    fetchMock.mockReset();
    fetchMock.mockImplementation(
        async () =>
            new Response(JSON.stringify({ tasks: [task] }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
    );
    vi.stubGlobal('fetch', fetchMock);
    // cmdk usa scrollIntoView, que jsdom no implementa.
    Element.prototype.scrollIntoView = vi.fn();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('«Iniciar temporizador» de la cabecera', () => {
    it('busca la tarea y arranca el temporizador sin ir a ella', async () => {
        const user = userEvent.setup();
        render(<TimerStartButton />);

        await user.click(
            screen.getByRole('button', { name: 'Iniciar temporizador' }),
        );
        await waitFor(() =>
            expect(fetchMock.mock.calls[0]?.[0]).toContain('/horas/tareas'),
        );
        await user.click(await screen.findByText('Maquetar la home'));

        expect(post).toHaveBeenCalledWith(
            '/temporizador',
            { task_id: 12 },
            expect.objectContaining({ errorBag: 'timer' }),
        );
    });

    it('mientras responde el servidor, muestra la carga y no admite otro clic', async () => {
        post.mockImplementation(
            (_url: string, _data: unknown, options: { onStart?: () => void }) =>
                options.onStart?.(),
        );
        const user = userEvent.setup();
        render(<TimerStartButton />);

        await user.click(
            screen.getByRole('button', { name: 'Iniciar temporizador' }),
        );
        await user.click(await screen.findByText('Maquetar la home'));

        const button = screen.getByRole('button', {
            name: 'Iniciar temporizador',
        });
        expect(button.getAttribute('aria-busy')).toBe('true');
        expect((button as HTMLButtonElement).disabled).toBe(true);
    });

    it('los errores al iniciar llegan como avisos', async () => {
        post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onError?: (errors: Record<string, string>) => void },
            ) =>
                options.onError?.({ task_id: 'No eres miembro del proyecto.' }),
        );
        const user = userEvent.setup();
        render(<TimerStartButton />);

        await user.click(
            screen.getByRole('button', { name: 'Iniciar temporizador' }),
        );
        await user.click(await screen.findByText('Maquetar la home'));

        expect(toastError).toHaveBeenCalledWith(
            'No eres miembro del proyecto.',
        );
    });
});
