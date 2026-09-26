// @vitest-environment jsdom
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TimesheetGrid } from '@/components/time/timesheet-grid';
import type { GridRow } from '@/components/time/timesheet-grid';
import type { LoggableTask, TimeEntry } from '@/types';

type VisitOptions = {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

const server = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    /** Qué responde el servidor a la siguiente visita. */
    respond: (visit: VisitOptions) => {
        visit.onSuccess?.();
        visit.onFinish?.();
    },
}));

const toastError = vi.hoisted(() => vi.fn());

vi.mock('sonner', async (importOriginal) => ({
    ...(await importOriginal<typeof import('sonner')>()),
    toast: Object.assign(vi.fn(), { error: toastError, success: vi.fn() }),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: {
        post: (url: string, data: unknown, visit: VisitOptions) => {
            server.post(url, data, visit);
            server.respond(visit);
        },
        put: (url: string, data: unknown, visit: VisitOptions) => {
            server.put(url, data, visit);
            server.respond(visit);
        },
        delete: (url: string, visit: VisitOptions) => {
            server.delete(url, visit);
            server.respond(visit);
        },
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

const DAYS = [
    '2026-09-21',
    '2026-09-22',
    '2026-09-23',
    '2026-09-24',
    '2026-09-25',
    '2026-09-26',
    '2026-09-27',
];

function task(id: number, title: string): LoggableTask {
    return {
        id,
        title,
        project_id: 3,
        project: {
            id: 3,
            code: 'ACME-WEB',
            name: 'Web',
            color: '#0171FF',
            is_internal: false,
        },
        is_billable: true,
        is_milestone: false,
        is_completed: false,
        is_deleted: false,
    };
}

function entry(
    id: number,
    taskId: number,
    date: string,
    minutes: number,
    extra: Partial<TimeEntry> = {},
): TimeEntry {
    return {
        id,
        user_id: 7,
        task_id: taskId,
        project_id: 3,
        hour_bank_id: null,
        date,
        minutes,
        overage_minutes: 0,
        in_bank_minutes: minutes,
        started_at: null,
        ended_at: null,
        description: null,
        is_billable: true,
        status: 'draft',
        approved_at: null,
        created_by: 7,
        logged_on_behalf: false,
        ...extra,
    };
}

function cells(entries: TimeEntry[]): TimeEntry[][] {
    return DAYS.map((day) => entries.filter((item) => item.date === day));
}

const home = task(12, 'Maquetar la home');
const menu = task(13, 'Menú principal');

function rows(): GridRow[] {
    const homeEntries = [
        entry(1, 12, '2026-09-21', 120),
        entry(2, 12, '2026-09-21', 30),
        entry(3, 12, '2026-09-22', 60),
    ];
    const menuEntries = [entry(4, 13, '2026-09-23', 45)];

    return [
        { task: home, cells: cells(homeEntries), total: 210 },
        { task: menu, cells: cells(menuEntries), total: 45 },
    ];
}

const totals = {
    days: {
        '2026-09-21': 150,
        '2026-09-22': 60,
        '2026-09-23': 45,
        '2026-09-24': 0,
        '2026-09-25': 0,
        '2026-09-26': 0,
        '2026-09-27': 0,
    },
    week: 255,
};

const capacity = {
    days: {
        '2026-09-21': 480,
        '2026-09-22': 480,
        '2026-09-23': 480,
        '2026-09-24': 480,
        '2026-09-25': 480,
        '2026-09-26': 0,
        '2026-09-27': 0,
    },
    week: 2400,
};

function renderGrid(
    overrides: Partial<Parameters<typeof TimesheetGrid>[0]> = {},
) {
    const onOpenCell = vi.fn();

    render(
        <TimesheetGrid
            rows={rows()}
            days={DAYS}
            totals={totals}
            capacity={capacity}
            editable
            today="2026-09-25"
            allowFuture={false}
            personId={7}
            onOpenCell={onOpenCell}
            onRemoveRow={vi.fn()}
            {...overrides}
        />,
    );

    return { onOpenCell };
}

function cell(taskTitle: string, day: string): HTMLInputElement {
    return screen.getByRole('textbox', {
        name: `Horas de «${taskTitle}» el ${day}`,
    }) as HTMLInputElement;
}

beforeEach(() => {
    server.post.mockReset();
    server.put.mockReset();
    server.delete.mockReset();
    toastError.mockReset();
    server.respond = (visit) => {
        visit.onSuccess?.();
        visit.onFinish?.();
    };
});

describe('hoja semanal', () => {
    it('pinta los totales por día y de la semana frente a la capacidad', () => {
        renderGrid();

        const dayTotals = [
            ...document.querySelectorAll('[data-test="day-total"]'),
        ].map((node) => node.textContent);
        expect(dayTotals[0]).toContain('2:30 / 8:00');
        expect(dayTotals[1]).toContain('1:00 / 8:00');
        // Sábado sin jornada: solo las horas.
        expect(dayTotals[5]).toContain('0:00');
        expect(
            document.querySelector('[data-test="week-total"]')?.textContent,
        ).toContain('4:15 / 40:00');

        const [homeRow] = [
            ...document.querySelectorAll<HTMLElement>(
                '[data-test="timesheet-row"]',
            ),
        ];
        expect(within(homeRow).getByText('3:30')).toBeTruthy();
    });

    it('una celda con varias entradas abre su lista; con una, se edita directamente', async () => {
        const user = userEvent.setup();
        const { onOpenCell } = renderGrid();

        await user.click(
            screen.getByRole('button', {
                name: /Horas de «Maquetar la home» el lunes 21\/09: 2:30 en 2 entradas/u,
            }),
        );
        expect(onOpenCell).toHaveBeenCalledWith(
            expect.objectContaining({ task: home }),
            0,
        );

        expect(cell('Maquetar la home', 'martes 22/09').value).toBe('1:00');
    });

    it('Enter guarda una celda vacía como entrada nueva y baja a la fila siguiente', async () => {
        const user = userEvent.setup();
        renderGrid();

        const input = cell('Maquetar la home', 'miércoles 23/09');
        await user.click(input);
        await user.type(input, '1,5');
        await user.keyboard('{Enter}');

        await waitFor(() => expect(server.post).toHaveBeenCalledTimes(1));
        expect(server.post.mock.calls[0][0]).toBe('/horas/entradas');
        expect(server.post.mock.calls[0][1]).toEqual({
            task_id: 12,
            user_id: 7,
            date: '2026-09-23',
            minutes: 90,
            quiet: 1,
        });
        expect(document.activeElement).toBe(
            cell('Menú principal', 'miércoles 23/09'),
        );
    });

    it('cambiar una celda con una entrada la actualiza; vaciarla la borra', async () => {
        const user = userEvent.setup();
        renderGrid();

        const tuesday = cell('Maquetar la home', 'martes 22/09');
        await user.tripleClick(tuesday);
        await user.keyboard('2{Tab}');

        await waitFor(() => expect(server.put).toHaveBeenCalledTimes(1));
        expect(server.put.mock.calls[0][0]).toBe('/horas/entradas/3');
        expect(server.put.mock.calls[0][1]).toMatchObject({
            task_id: 12,
            date: '2026-09-22',
            minutes: 120,
            quiet: 1,
        });

        const wednesday = cell('Menú principal', 'miércoles 23/09');
        await user.clear(wednesday);
        await user.keyboard('{Tab}');

        await waitFor(() => expect(server.delete).toHaveBeenCalledTimes(1));
        expect(server.delete.mock.calls[0][0]).toBe(
            '/horas/entradas/4?quiet=1',
        );
    });

    it('no guarda si no cambia nada ni si el formato no es válido', async () => {
        const user = userEvent.setup();
        renderGrid();

        const tuesday = cell('Maquetar la home', 'martes 22/09');
        await user.click(tuesday);
        await user.keyboard('{Tab}');

        const thursday = cell('Maquetar la home', 'jueves 24/09');
        await user.type(thursday, 'mucho');
        await user.keyboard('{Tab}');

        expect(server.post).not.toHaveBeenCalled();
        expect(server.put).not.toHaveBeenCalled();
        expect(thursday.getAttribute('aria-invalid')).toBe('true');
    });

    it('las flechas se mueven entre celdas y Escape deshace', async () => {
        const user = userEvent.setup();
        renderGrid();

        const tuesday = cell('Maquetar la home', 'martes 22/09');
        await user.click(tuesday);
        await user.keyboard('{ArrowDown}');
        expect(document.activeElement).toBe(
            cell('Menú principal', 'martes 22/09'),
        );

        await user.keyboard('{ArrowUp}');
        expect(document.activeElement).toBe(tuesday);

        // Con el texto seleccionado entero no está en el borde: la flecha no cambia de celda.
        (document.activeElement as HTMLInputElement).setSelectionRange(4, 4);
        await user.keyboard('{ArrowRight}');
        expect(document.activeElement).toBe(
            cell('Maquetar la home', 'miércoles 23/09'),
        );

        await user.keyboard('{ArrowLeft}');
        expect(document.activeElement).toBe(tuesday);

        await user.keyboard('{Control>}a{/Control}5');
        expect(tuesday.value).toBe('5');
        await user.keyboard('{Escape}');

        await waitFor(() =>
            expect(cell('Maquetar la home', 'martes 22/09').value).toBe('1:00'),
        );
        expect(server.put).not.toHaveBeenCalled();
    });

    it('si el servidor rechaza el cambio, lo avisa y deja el valor anterior', async () => {
        const user = userEvent.setup();
        server.respond = (visit) => {
            visit.onError?.({
                minutes:
                    'La bolsa «Q3» no admite exceso. Saldo disponible: 0:30.',
            });
            visit.onFinish?.();
        };
        renderGrid();

        const tuesday = cell('Maquetar la home', 'martes 22/09');
        await user.tripleClick(tuesday);
        await user.keyboard('3{Tab}');

        await waitFor(() =>
            expect(toastError).toHaveBeenCalledWith(
                'La bolsa «Q3» no admite exceso. Saldo disponible: 0:30.',
            ),
        );
        await waitFor(() =>
            expect(cell('Maquetar la home', 'martes 22/09').value).toBe('1:00'),
        );
    });

    it('si el ajuste exige descripción, abre el diálogo de la entrada con la duración escrita', async () => {
        const user = userEvent.setup();
        server.respond = (visit) => {
            visit.onError?.({
                description: 'Escribe una descripción de lo que has hecho.',
            });
            visit.onFinish?.();
        };
        const onNeedsDescription = vi.fn();
        renderGrid({ onNeedsDescription });

        // Celda vacía: entrada nueva.
        const wednesday = cell('Maquetar la home', 'miércoles 23/09');
        await user.click(wednesday);
        await user.type(wednesday, '1:30');
        await user.keyboard('{Tab}');

        await waitFor(() =>
            expect(onNeedsDescription).toHaveBeenCalledWith(
                expect.objectContaining({ task: home }),
                2,
                null,
                90,
            ),
        );
        // No se avisa del error: lo explica el diálogo.
        expect(toastError).not.toHaveBeenCalled();

        // Celda con una entrada sin descripción: esa entrada.
        const tuesday = cell('Maquetar la home', 'martes 22/09');
        await user.tripleClick(tuesday);
        await user.keyboard('2{Tab}');

        await waitFor(() =>
            expect(onNeedsDescription).toHaveBeenLastCalledWith(
                expect.objectContaining({ task: home }),
                1,
                expect.objectContaining({ id: 3 }),
                120,
            ),
        );
    });

    it('los días futuros y las semanas cerradas no se editan', () => {
        renderGrid({ editable: false });

        expect(screen.queryAllByRole('textbox')).toHaveLength(0);
        expect(
            screen.getByRole('button', {
                name: /Horas de «Maquetar la home» el martes 22\/09: 1:00 en 1 entradas/u,
            }),
        ).toBeTruthy();
    });

    it('el domingo (futuro) no admite horas si no se permiten fechas futuras', () => {
        renderGrid();

        expect(
            screen.queryByRole('textbox', {
                name: 'Horas de «Maquetar la home» el domingo 27/09',
            }),
        ).toBeNull();
        expect(
            screen.getByRole('textbox', {
                name: 'Horas de «Maquetar la home» el viernes 25/09',
            }),
        ).toBeTruthy();
    });
});
