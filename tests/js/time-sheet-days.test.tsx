// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { entriesOfDay, TimesheetDays } from '@/components/time/timesheet-days';
import type { GridRow } from '@/components/time/timesheet-grid';
import type { LoggableTask, TimeEntry, TimesheetDayNote } from '@/types';

/*
| Hoja semanal «Por días» (D-321): un bloque por día con su total frente a la jornada, sus
| entradas, «Añadir horas» y los festivos y ausencias. "Hoy" es el jueves 24/09/2026.
*/

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
        hour_bank: { id: 4, name: 'Bolsa Q4' },
        is_billable: true,
        is_milestone: false,
        is_completed: false,
        is_deleted: false,
    };
}

function entry(
    id: number,
    date: string,
    minutes: number,
    extra: Partial<TimeEntry> = {},
): TimeEntry {
    return {
        id,
        user_id: 7,
        task_id: 12,
        project_id: 3,
        hour_bank_id: 4,
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

const rows: GridRow[] = [
    {
        task: task(12, 'Maquetar la home'),
        cells: cells([
            entry(1, '2026-09-21', 120, { description: 'Cabecera' }),
            entry(2, '2026-09-21', 30, {
                started_at: '2026-09-21T07:00:00Z',
                ended_at: '2026-09-21T07:30:00Z',
            }),
            entry(3, '2026-09-22', 60, { status: 'approved' }),
        ]),
        total: 210,
    },
    {
        task: task(13, 'Menú principal'),
        cells: cells([entry(4, '2026-09-21', 45, { task_id: 13 })]),
        total: 45,
    },
];

const notes: Record<string, TimesheetDayNote> = Object.fromEntries(
    DAYS.map((day) => [day, { holiday: null, absence: null }]),
);
notes['2026-09-23'] = {
    holiday: null,
    absence: { type: null, partial: false },
};
notes['2026-09-25'] = { holiday: 'La Mercè', absence: null };

const capacity = {
    days: {
        '2026-09-21': 480,
        '2026-09-22': 480,
        '2026-09-23': 0,
        '2026-09-24': 480,
        '2026-09-25': 0,
        '2026-09-26': 0,
        '2026-09-27': 0,
    },
    week: 1440,
};

const totals = {
    days: { '2026-09-21': 195, '2026-09-22': 60 },
    week: 255,
};

function renderDays(
    overrides: Partial<Parameters<typeof TimesheetDays>[0]> = {},
) {
    const onAdd = vi.fn();
    const onEdit = vi.fn();

    render(
        <TimesheetDays
            rows={rows}
            days={DAYS}
            totals={totals}
            capacity={capacity}
            dayNotes={notes}
            today="2026-09-24"
            allowFuture={false}
            editable
            canEditEntry={(item) => item.status === 'draft'}
            onAdd={onAdd}
            onEdit={onEdit}
            {...overrides}
        />,
    );

    return { onAdd, onEdit };
}

/** Un elemento con data-test dentro de `root`. */
function byTest(root: ParentNode, name: string): HTMLElement {
    const found = root.querySelector(`[data-test="${name}"]`);

    if (!(found instanceof HTMLElement)) {
        throw new Error(`Sin [data-test="${name}"]`);
    }

    return found;
}

function day(date: string): HTMLElement {
    return document.querySelector(`[data-date="${date}"]`) as HTMLElement;
}

describe('hoja «Por días»', () => {
    it('un bloque por día, con su total frente a la jornada y sus entradas de todas las tareas', () => {
        renderDays();

        expect(
            screen
                .getAllByRole('heading', { level: 2 })
                .map((heading) => heading.textContent),
        ).toEqual([
            'Lunes 21 de septiembre',
            'Martes 22 de septiembre',
            'Miércoles 23 de septiembre',
            'Jueves 24 de septiembreHoy',
            'Viernes 25 de septiembre',
            'Sábado 26 de septiembre',
            'Domingo 27 de septiembre',
        ]);

        const monday = day('2026-09-21');
        expect(byTest(monday, 'day-total').textContent).toContain(
            '3:15 de 8:00',
        );
        const entries = within(monday).getAllByRole('listitem');
        // Primero la que tiene hora de inicio; después, en el orden de la hoja.
        expect(entries.map((item) => item.textContent)).toEqual([
            expect.stringContaining('Maquetar la home'),
            expect.stringContaining('Maquetar la home'),
            expect.stringContaining('Menú principal'),
        ]);
        expect(entries[0].textContent).toContain('0:30');
        expect(entries[1].textContent).toContain('Cabecera');
        expect(entries[1].textContent).toContain('ACME-WEB · Web · Bolsa Q4');
        expect(byTest(document, 'week-total').textContent).toContain(
            '4:15 de 24:00',
        );
    });

    it('«Añadir horas» en cada día que admite horas (no en los futuros sin permiso)', async () => {
        const user = userEvent.setup();
        const { onAdd } = renderDays();

        await user.click(
            within(day('2026-09-22')).getByRole('button', {
                name: /Añadir horas el martes/,
            }),
        );
        expect(onAdd).toHaveBeenCalledWith('2026-09-22');
        expect(within(day('2026-09-25')).queryByRole('button')).toBeNull();
    });

    it('un clic en cualquier punto de una entrada editable la edita; las aprobadas no', async () => {
        const user = userEvent.setup();
        const { onEdit } = renderDays();

        await user.click(within(day('2026-09-21')).getByText('Cabecera'));
        expect(onEdit).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));

        const approved = within(day('2026-09-22')).getByRole('listitem');
        expect(within(approved).queryByRole('button')).toBeNull();
        await user.click(approved);
        expect(onEdit).toHaveBeenCalledTimes(1);
    });

    it('marca los festivos y las ausencias y avisa de los laborables pasados sin horas', () => {
        renderDays({
            capacity: {
                ...capacity,
                days: { ...capacity.days, '2026-09-23': 480 },
            },
        });

        expect(byTest(day('2026-09-25'), 'day-holiday').textContent).toBe(
            'Festivo: La Mercè',
        );
        expect(byTest(day('2026-09-23'), 'day-absence').textContent).toBe(
            'Ausencia',
        );
        expect(byTest(day('2026-09-23'), 'day-missing')).toBeTruthy();
        // Hoy y los días futuros sin horas no avisan.
        expect(byTest(day('2026-09-24'), 'day-empty')).toBeTruthy();
        expect(byTest(day('2026-09-26'), 'day-empty')).toBeTruthy();
    });

    it('solo lectura: sin «Añadir horas» ni entradas editables', () => {
        renderDays({ editable: false, canEditEntry: () => false });

        expect(screen.queryAllByRole('button')).toHaveLength(0);
    });
});

describe('entriesOfDay', () => {
    it('reúne las entradas del día de todas las filas, sin calcular nada nuevo', () => {
        expect(entriesOfDay(rows, 0).map((item) => item.entry.id)).toEqual([
            2, 1, 4,
        ]);
        expect(entriesOfDay(rows, 6)).toEqual([]);
    });
});
