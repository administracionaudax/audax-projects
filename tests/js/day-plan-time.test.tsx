// @vitest-environment jsdom
import { configure, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AddToMyDayButton } from '@/components/day-plan/add-to-my-day';
import { CalendarDayPlan } from '@/components/day-plan/calendar-day-plan';
import { LineTimerButton } from '@/components/day-plan/line-time';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LineTimeActions } from '@/components/day-plan/line-time';
import { markLineDone } from '@/hooks/use-day-plan-prompt';
import type { DayPlanLine } from '@/types/day-plan';

configure({ testIdAttribute: 'data-test' });
vi.setConfig({ testTimeout: 20_000 });

for (const method of [
    'hasPointerCapture',
    'releasePointerCapture',
    'setPointerCapture',
    'scrollIntoView',
] as const) {
    if (!(method in Element.prototype)) {
        Object.defineProperty(Element.prototype, method, {
            configurable: true,
            value: () => false,
        });
    }
}

/*
| Las horas de una línea (D-254): ▶ en su tarea o «¿En qué tarea?», imputar lo previsto, «¿Das por
| hecha la línea?», «Añadir a mi día» y el plan en el calendario.
*/

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    page: {
        url: '/dia',
        props: {
            auth: { user: { id: 7, name: 'Elena' }, can: {} },
            timer: null,
        } as Record<string, unknown>,
    },
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => inertia.page,
    router: {
        post: inertia.post,
        delete: vi.fn(),
        get: vi.fn(),
        on: () => () => {},
    },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

function line(overrides: Partial<DayPlanLine> = {}): DayPlanLine {
    return {
        id: 4,
        user_id: 7,
        date: '2026-10-07',
        position: 0,
        text: 'JS del configurador',
        status: 'pending',
        not_done_reason: null,
        carry_count: 0,
        carried_from_date: null,
        origin: 'manual',
        client: null,
        project: {
            id: 10,
            code: 'KIWI-CONF',
            name: 'Configurador',
            color: '#0171FF',
        },
        task: null,
        created_at: null,
        added_late: false,
        planned_minutes: 120,
        logged_minutes: 0,
        running: false,
        comments: [],
        ...overrides,
    };
}

beforeEach(() => {
    inertia.post.mockReset();
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async () =>
                new Response(JSON.stringify({ tasks: [] }), { status: 200 }),
        ),
    );
});

describe('temporizador de la línea', () => {
    it('con tarea arranca directamente desde la línea', async () => {
        const user = userEvent.setup();
        render(
            <LineTimerButton
                line={line({
                    task: {
                        id: 5,
                        title: 'Paso 3',
                        project_id: 10,
                        is_completed: false,
                    },
                })}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Iniciar el temporizador en «JS del configurador»',
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/lineas/4/temporizador',
            {},
            expect.objectContaining({ errorBag: 'timer' }),
        );
    });

    it('sin tarea pregunta «¿En qué tarea?» y ofrece crearla en el proyecto de la línea', async () => {
        const user = userEvent.setup();
        render(<LineTimerButton line={line()} />);

        await user.click(screen.getByTestId('day-plan-line-timer'));
        expect(
            await screen.findByRole('dialog', { name: '¿En qué tarea?' }),
        ).toBeTruthy();
        expect(inertia.post).not.toHaveBeenCalled();

        await user.click(screen.getByTestId('day-plan-create-task'));
        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/lineas/4/temporizador',
            { create_task: true },
            expect.anything(),
        );
    });

    it('en marcha, el botón lo para', async () => {
        const user = userEvent.setup();
        render(
            <LineTimerButton
                line={line({
                    running: true,
                    task: {
                        id: 5,
                        title: 'Paso 3',
                        project_id: 10,
                        is_completed: false,
                    },
                })}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Parar el temporizador de «JS del configurador»',
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/temporizador/parar',
            expect.anything(),
            expect.anything(),
        );
    });
});

describe('acciones de horas', () => {
    it('«Imputar lo previsto» solo en una línea hecha, con tarea, horas previstas y sin horas', async () => {
        const user = userEvent.setup();
        const done = line({
            status: 'done',
            task: {
                id: 5,
                title: 'Paso 3',
                project_id: 10,
                is_completed: false,
            },
        });
        const { rerender } = render(
            <DropdownMenu open>
                <DropdownMenuTrigger>⋯</DropdownMenuTrigger>
                <DropdownMenuContent>
                    <LineTimeActions
                        line={done}
                        onLog={() => {}}
                        onLink={() => {}}
                    />
                </DropdownMenuContent>
            </DropdownMenu>,
        );

        await user.click(screen.getByTestId('day-plan-line-log-planned'));
        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/lineas/4/imputar-previsto',
            {},
            expect.anything(),
        );

        rerender(
            <DropdownMenu open>
                <DropdownMenuTrigger>⋯</DropdownMenuTrigger>
                <DropdownMenuContent>
                    <LineTimeActions
                        line={{ ...done, logged_minutes: 30 }}
                        onLog={() => {}}
                        onLink={() => {}}
                    />
                </DropdownMenuContent>
            </DropdownMenu>,
        );
        expect(screen.queryByTestId('day-plan-line-log-planned')).toBeNull();
        expect(screen.getByTestId('day-plan-line-link')).toBeTruthy();
    });

    it('«Marcar como hecha» del aviso al parar el temporizador', () => {
        markLineDone({ id: 4, text: 'JS del configurador' });

        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/lineas/4/estado',
            { status: 'done' },
            expect.anything(),
        );
    });
});

describe('integraciones', () => {
    it('«Añadir a mi día» desde Mis tareas, para hoy o mañana', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-07T08:00:00Z'));
        const user = userEvent.setup();
        render(<AddToMyDayButton task={{ id: 9, title: 'Maquetar home' }} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Añadir «Maquetar home» a mi día',
            }),
        );
        await user.click(await screen.findByTestId('add-to-my-day-tomorrow'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/desde-tareas',
            { date: '2026-10-08', task_ids: [9] },
            expect.anything(),
        );
        vi.useRealTimers();
    });

    it('el plan del día en el calendario, plegado con el resumen', () => {
        render(
            <CalendarDayPlan
                name="Ana"
                lines={[
                    line({ status: 'done' }),
                    line({ id: 5, text: 'Moodboard' }),
                ]}
            />,
        );

        expect(screen.getByTestId('calendar-day-plan').textContent).toContain(
            'Plan del día: 1 de 2 hechas',
        );
        expect(screen.getByText('Moodboard')).toBeTruthy();
    });
});
