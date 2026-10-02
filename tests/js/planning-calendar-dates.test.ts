import { describe, expect, it } from 'vitest';
import {
    daysBetween,
    daysInMonth,
    modeSwitchPeriod,
    monthGrid,
    monthLabel,
    movedDates,
    periodContaining,
    periodQuery,
    shiftMonth,
    shiftPeriod,
    tasksByDueDate,
    weekOf,
    weekSpans,
} from '@/components/planning/calendar-dates';
import type { CalendarTask } from '@/types/planning';

function task(
    id: number,
    start: string | null,
    due: string | null,
    extra: Partial<CalendarTask> = {},
): CalendarTask {
    return {
        id,
        title: `Tarea ${id}`,
        parent_task_id: null,
        parent_title: null,
        status_id: 1,
        priority: 'normal',
        assignee: null,
        start_date: start,
        due_date: due,
        is_milestone: false,
        is_completed: false,
        ...extra,
    };
}

/** Día de la semana de una fecha local (0 = domingo), sin zona. */
function weekday(date: string): number {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).getUTCDay();
}

describe('rejilla del mes', () => {
    it('empieza en lunes y acaba en domingo, con semanas completas', () => {
        const weeks = monthGrid('2026-10');

        expect(weeks).toHaveLength(5);
        expect(weeks[0][0]).toBe('2026-09-28');
        expect(weeks.at(-1)?.at(-1)).toBe('2026-11-01');

        for (const week of weeks) {
            expect(week).toHaveLength(7);
            expect(weekday(week[0])).toBe(1);
            expect(weekday(week[6])).toBe(0);
        }
    });

    it.each([
        ['2027-02', 28, 4, '2027-02-01', '2027-02-28'],
        ['2028-02', 29, 5, '2028-01-31', '2028-03-05'],
        ['2026-11', 30, 6, '2026-10-26', '2026-12-06'],
        ['2026-08', 31, 6, '2026-07-27', '2026-09-06'],
        ['2026-03', 31, 6, '2026-02-23', '2026-04-05'],
    ])(
        '%s tiene %i días en %i semanas (del %s al %s)',
        (month, days, rows, first, last) => {
            const weeks = monthGrid(month);

            expect(daysInMonth(month)).toBe(days);
            expect(weeks).toHaveLength(rows);
            expect(weeks[0][0]).toBe(first);
            expect(weeks.at(-1)?.at(-1)).toBe(last);
            // Todos los días del mes están, una sola vez y en orden.
            const inMonth = weeks.flat().filter((day) => day.startsWith(month));
            expect(inMonth).toHaveLength(days);
            expect(inMonth[0]).toBe(`${month}-01`);
        },
    );

    it('cruza el cambio de año sin saltarse días', () => {
        const weeks = monthGrid('2026-12');

        expect(weeks[0][0]).toBe('2026-11-30');
        expect(weeks.at(-1)).toEqual([
            '2026-12-28',
            '2026-12-29',
            '2026-12-30',
            '2026-12-31',
            '2027-01-01',
            '2027-01-02',
            '2027-01-03',
        ]);
        expect(monthGrid('2027-01')[0][0]).toBe('2026-12-28');
    });

    it('la semana va de lunes a domingo', () => {
        expect(weekOf('2026-12-28')).toEqual([
            '2026-12-28',
            '2026-12-29',
            '2026-12-30',
            '2026-12-31',
            '2027-01-01',
            '2027-01-02',
            '2027-01-03',
        ]);
    });
});

describe('navegación', () => {
    it('suma y resta meses cruzando el año', () => {
        expect(shiftMonth('2026-12', 1)).toBe('2027-01');
        expect(shiftMonth('2027-01', -1)).toBe('2026-12');
        expect(shiftMonth('2026-10', 14)).toBe('2027-12');
        expect(shiftPeriod('month', '2026-10', -1)).toBe('2026-09');
        expect(shiftPeriod('week', '2026-12-28', 1)).toBe('2027-01-04');
        expect(shiftPeriod('week', '2027-01-04', -1)).toBe('2026-12-28');
    });

    it('«Hoy» lleva al mes o a la semana (desde el lunes) que contiene hoy', () => {
        expect(periodContaining('month', '2026-10-13')).toBe('2026-10');
        expect(periodContaining('week', '2026-10-18')).toBe('2026-10-12');
        expect(periodContaining('week', '2026-10-12')).toBe('2026-10-12');
    });

    it('al cambiar de escala elige un periodo razonable', () => {
        // Del mes de hoy a su semana; de otro mes, a la semana del día 1.
        expect(
            modeSwitchPeriod(
                { mode: 'month', period: '2026-10', today: '2026-10-15' },
                'week',
            ),
        ).toBe('2026-10-12');
        expect(
            modeSwitchPeriod(
                { mode: 'month', period: '2026-12', today: '2026-10-15' },
                'week',
            ),
        ).toBe('2026-11-30');
        // De la semana al mes de su jueves (28/09-04/10 es de octubre).
        expect(
            modeSwitchPeriod(
                { mode: 'week', period: '2026-09-28', today: '2026-10-15' },
                'month',
            ),
        ).toBe('2026-10');
        expect(
            modeSwitchPeriod(
                { mode: 'week', period: '2026-10-26', today: '2026-10-15' },
                'month',
            ),
        ).toBe('2026-10');
    });

    it('los parámetros de la URL están en español', () => {
        expect(periodQuery('month', '2026-10')).toEqual({ mes: '2026-10' });
        expect(periodQuery('week', '2026-10-12')).toEqual({
            semana: '2026-10-12',
        });
    });

    it('nombra el mes en español', () => {
        expect(monthLabel('2026-10')).toBe('octubre de 2026');
    });
});

describe('mover una tarea de día', () => {
    it('conserva la duración: el inicio se desplaza lo mismo que la entrega', () => {
        expect(
            movedDates(task(1, '2026-10-05', '2026-10-09'), '2026-10-14'),
        ).toEqual({ start_date: '2026-10-10', due_date: '2026-10-14' });
        expect(
            movedDates(task(1, '2026-10-05', '2026-10-09'), '2026-10-02'),
        ).toEqual({ start_date: '2026-09-28', due_date: '2026-10-02' });
        // Cruzando el cambio de año.
        expect(
            movedDates(task(1, '2026-12-28', '2026-12-31'), '2027-01-05'),
        ).toEqual({ start_date: '2027-01-02', due_date: '2027-01-05' });
    });

    it('sin inicio solo cambia la entrega; un hito nunca tiene inicio', () => {
        expect(movedDates(task(1, null, '2026-10-09'), '2026-10-14')).toEqual({
            start_date: null,
            due_date: '2026-10-14',
        });
        expect(
            movedDates(
                task(1, '2026-10-01', '2026-10-09', { is_milestone: true }),
                '2026-10-14',
            ),
        ).toEqual({ start_date: null, due_date: '2026-10-14' });
    });

    it('asignar fecha a una sin entrega: mantiene el inicio si no es posterior', () => {
        expect(movedDates(task(1, null, null), '2026-10-14')).toEqual({
            start_date: null,
            due_date: '2026-10-14',
        });
        expect(movedDates(task(1, '2026-10-05', null), '2026-10-14')).toEqual({
            start_date: '2026-10-05',
            due_date: '2026-10-14',
        });
        expect(movedDates(task(1, '2026-10-20', null), '2026-10-14')).toEqual({
            start_date: '2026-10-14',
            due_date: '2026-10-14',
        });
    });

    it('cuenta los días entre dos fechas, también con el cambio de hora', () => {
        expect(daysBetween('2026-10-24', '2026-10-26')).toBe(2);
        expect(daysBetween('2026-03-28', '2026-03-30')).toBe(2);
        expect(daysBetween('2026-10-09', '2026-10-05')).toBe(-4);
    });
});

describe('tareas por día y franjas de la semana', () => {
    it('agrupa por día de entrega y deja fuera las que no tienen', () => {
        const byDay = tasksByDueDate([
            task(1, null, '2026-10-14'),
            task(2, '2026-10-01', '2026-10-14'),
            task(3, null, null),
            task(4, null, '2026-10-15'),
        ]);

        expect(byDay.get('2026-10-14')?.map((item) => item.id)).toEqual([1, 2]);
        expect(byDay.get('2026-10-15')?.map((item) => item.id)).toEqual([4]);
        expect(byDay.size).toBe(2);
    });

    it('reparte las franjas en filas sin solaparse y marca las que siguen fuera de la semana', () => {
        const days = weekOf('2026-10-12');
        const spans = weekSpans(
            [
                task(1, '2026-10-12', '2026-10-14'),
                task(2, '2026-10-13', '2026-10-16'),
                task(3, '2026-10-15', '2026-10-18'),
                task(4, '2026-10-01', '2026-10-13'),
                task(5, '2026-10-16', '2026-10-30'),
                task(6, null, '2026-10-14'),
                task(7, '2026-10-14', '2026-10-14'),
                task(8, '2026-10-20', '2026-10-22'),
            ],
            days,
        );
        const byId = new Map(spans.map((span) => [span.task.id, span]));

        expect([...byId.keys()].sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5]);
        expect(byId.get(4)).toMatchObject({
            startColumn: 0,
            endColumn: 1,
            continuesBefore: true,
            continuesAfter: false,
        });
        expect(byId.get(5)).toMatchObject({
            startColumn: 4,
            endColumn: 6,
            continuesBefore: false,
            continuesAfter: true,
        });

        // Dos franjas de la misma fila nunca comparten un día.
        for (const a of spans) {
            for (const b of spans) {
                if (a !== b && a.lane === b.lane) {
                    expect(
                        a.endColumn < b.startColumn ||
                            b.endColumn < a.startColumn,
                    ).toBe(true);
                }
            }
        }

        // La 3 (jue-dom) cabe en la fila que deja libre la 1 (lun-mié).
        expect(byId.get(3)?.lane).toBe(byId.get(1)?.lane);
    });
});
