// @vitest-environment jsdom
import { configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HomeDayPlanCard } from '@/components/day-plan/home-day-plan-card';
import { LineComposer } from '@/components/day-plan/line-composer';
import MyDayPage from '@/pages/day-plan/index';
import type { DayPlanLine, DayPlanTargets, MyDayData } from '@/types/day-plan';

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
| Mi día (D-250): la página, la caja de escribir con sus atajos, el check, «Pasar a hoy» y la
| tarjeta de Inicio.
*/

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    page: {
        url: '/dia',
        props: {
            auth: { user: { id: 7, name: 'Elena' }, can: { useDayPlan: true } },
            timer: null,
        } as Record<string, unknown>,
    },
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => inertia.page,
    router: {
        post: inertia.post,
        put: inertia.put,
        patch: inertia.patch,
        delete: inertia.delete,
        get: vi.fn(),
        on: () => () => {},
    },
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
}));

const targets: DayPlanTargets = {
    clients: [{ id: 1, name: 'ACME' }],
    projects: [
        {
            id: 10,
            code: 'KIWI-CONF',
            name: 'Configurador',
            color: '#0171FF',
            client_id: 4,
            client_name: 'Kiwi',
            is_mine: true,
            is_internal: false,
        },
    ],
};

function line(overrides: Partial<DayPlanLine> = {}): DayPlanLine {
    return {
        id: 1,
        user_id: 7,
        date: '2026-10-07',
        position: 0,
        text: 'Creatividades campaña otoño',
        status: 'pending',
        not_done_reason: null,
        carry_count: 0,
        carried_from_date: null,
        origin: 'manual',
        client: { id: 1, name: 'ACME' },
        project: null,
        task: null,
        created_at: '2026-10-07T07:00:00Z',
        added_late: false,
        planned_minutes: 120,
        logged_minutes: 0,
        running: false,
        comments: [],
        ...overrides,
    };
}

function day(overrides: Partial<MyDayData> = {}): MyDayData {
    return {
        date: '2026-10-07',
        today: '2026-10-07',
        horizon_end: '2026-10-18',
        deadline: '08:30',
        can: { write: true, close: true },
        plan: { note: null, published_at: '2026-10-07T07:00:00Z' },
        items: [
            line(),
            line({
                id: 2,
                text: 'JS del configurador',
                carry_count: 2,
                status: 'done',
                planned_minutes: 180,
                logged_minutes: 65,
                client: null,
                project: {
                    id: 10,
                    code: 'KIWI-CONF',
                    name: 'Configurador',
                    color: '#0171FF',
                },
            }),
        ],
        summary: {
            capacity_minutes: 480,
            planned_minutes: 300,
            logged_minutes: 130,
            done: 1,
            total: 2,
        },
        pending: [],
        running_item_id: null,
        ...overrides,
    };
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.put.mockReset();
});

describe('Mi día', () => {
    it('pinta las líneas con su marca ↻ ×N, sus horas y las cifras del día', () => {
        render(<MyDayPage day={day()} targets={targets} />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Mi día' }),
        ).toBeTruthy();
        expect(screen.getAllByTestId('day-plan-line')).toHaveLength(2);
        expect(
            screen.getByTestId('day-plan-carry-count').textContent,
        ).toContain('Viene arrastrada de 2 días');
        expect(screen.getByTestId('day-plan-summary').textContent).toContain(
            '1 de 2',
        );
        expect(screen.getByTestId('day-plan-summary').textContent).toContain(
            '5:00',
        );
        expect(
            screen.getAllByTestId('day-plan-line-figures')[1].textContent,
        ).toContain('1:05');
        expect(
            screen
                .getByRole('link', { name: 'Equipo hoy' })
                .getAttribute('href'),
        ).toBe('/dia/equipo');
    });

    it('marcar el check envía «hecha»', async () => {
        const user = userEvent.setup();
        render(<MyDayPage day={day()} targets={targets} />);

        await user.click(
            screen.getByRole('checkbox', {
                name: 'Marcar como hecha: Creatividades campaña otoño',
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/lineas/1/estado',
            { status: 'done' },
            expect.anything(),
        );
    });

    it('con tarea abierta, pregunta si se marca también la tarea', async () => {
        const user = userEvent.setup();
        render(
            <MyDayPage
                day={day({
                    items: [
                        line({
                            task: {
                                id: 5,
                                title: 'Paso 3',
                                project_id: 10,
                                is_completed: false,
                            },
                        }),
                    ],
                })}
                targets={targets}
            />,
        );

        await user.click(
            screen.getByRole('checkbox', { name: /Marcar como hecha/ }),
        );
        expect(inertia.post).not.toHaveBeenCalled();

        await user.click(await screen.findByTestId('day-plan-complete-task'));
        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/lineas/1/estado',
            { status: 'done', complete_task: true },
            expect.anything(),
        );
    });

    it('«Pasar todas a hoy» envía las pendientes de días anteriores', async () => {
        const user = userEvent.setup();
        render(
            <MyDayPage
                day={day({
                    pending: [
                        {
                            id: 30,
                            date: '2026-10-06',
                            text: 'Ayer',
                            carry_count: 0,
                            client: null,
                            project: null,
                        },
                        {
                            id: 31,
                            date: '2026-10-06',
                            text: 'Otra',
                            carry_count: 1,
                            client: 'ACME',
                            project: null,
                        },
                    ],
                })}
                targets={targets}
            />,
        );

        expect(screen.getByTestId('day-plan-pending').textContent).toContain(
            'Tienes 2 pendientes de ayer.',
        );
        await user.click(screen.getByTestId('day-plan-pending-carry-all'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/pendientes/pasar',
            { ids: [30, 31], date: '2026-10-07' },
            expect.anything(),
        );
    });

    it('un día pasado es de solo lectura: sin caja de escribir ni asas', () => {
        render(
            <MyDayPage
                day={day({
                    date: '2026-10-06',
                    can: { write: false, close: true },
                })}
                targets={targets}
            />,
        );

        expect(screen.queryByTestId('day-plan-composer')).toBeNull();
        expect(screen.queryByTestId('day-plan-line-handle')).toBeNull();
        expect(screen.queryByTestId('day-plan-from-tasks')).toBeNull();
    });
});

describe('la caja de escribir', () => {
    it('@ elige un cliente con el teclado e Intro añade la línea con sus horas', async () => {
        const user = userEvent.setup();
        render(<LineComposer date="2026-10-07" targets={targets} />);
        const input = screen.getByTestId('day-plan-composer-input');

        await user.type(input, 'Banners @ac');
        const options = screen.getByTestId('day-plan-composer-options');
        expect(
            within(options)
                .getByRole('option', { name: /ACME/ })
                .getAttribute('aria-selected'),
        ).toBe('true');

        await user.keyboard('{Enter}');
        expect(screen.queryByTestId('day-plan-composer-options')).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Quitar el cliente ACME' }),
        ).toBeTruthy();

        await user.type(input, '~1:30{Enter}');
        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/lineas',
            {
                date: '2026-10-07',
                text: 'Banners',
                client_id: 1,
                project_id: null,
                planned_minutes: 90,
            },
            expect.anything(),
        );
    });

    it('# elige un proyecto (y su cliente) y Escape cierra la lista', async () => {
        const user = userEvent.setup();
        render(<LineComposer date="2026-10-07" targets={targets} />);
        const input = screen.getByTestId('day-plan-composer-input');

        await user.type(input, 'JS #kiwi');
        await user.keyboard('{Escape}');
        expect(screen.queryByTestId('day-plan-composer-options')).toBeNull();

        await user.type(input, '{Backspace}i');
        await user.keyboard('{ArrowDown}{Tab}');
        await user.keyboard('{Enter}');

        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/lineas',
            expect.objectContaining({
                text: 'JS',
                project_id: 10,
                client_id: null,
            }),
            expect.anything(),
        );
    });

    it('sin texto no envía y lo dice', async () => {
        const user = userEvent.setup();
        render(<LineComposer date="2026-10-07" targets={targets} />);

        await user.type(
            screen.getByTestId('day-plan-composer-input'),
            '~1:30{Enter}',
        );

        expect(inertia.post).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toBe(
            'Escribe qué vas a hacer.',
        );
    });
});

describe('tarjeta de Inicio', () => {
    it('enseña el progreso, las líneas y las pendientes', () => {
        render(
            <HomeDayPlanCard
                card={{
                    date: '2026-10-07',
                    deadline: '08:30',
                    items: [line()],
                    done: 0,
                    total: 1,
                    pending: 2,
                    running_item_id: null,
                    can_write: true,
                }}
            />,
        );

        expect(screen.getByTestId('home-day-plan-progress').textContent).toBe(
            '0 de 1 hechas',
        );
        expect(screen.getAllByTestId('home-day-plan-line')).toHaveLength(1);
        expect(
            screen.getByTestId('home-day-plan-pending').getAttribute('href'),
        ).toBe('/dia');
        expect(screen.getByTestId('day-plan-composer')).toBeTruthy();
    });

    it('sin líneas recuerda la hora límite', () => {
        render(
            <HomeDayPlanCard
                card={{
                    date: '2026-10-07',
                    deadline: '08:30',
                    items: [],
                    done: 0,
                    total: 0,
                    pending: 0,
                    running_item_id: null,
                    can_write: true,
                }}
            />,
        );

        expect(
            screen.getByTestId('home-day-plan-progress').textContent,
        ).toContain('antes de las 08:30');
    });
});
