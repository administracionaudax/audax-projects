import { describe, expect, it } from 'vitest';
import {
    assigneeColors,
    NEUTRAL_COLOR,
    statusColors,
} from '@/components/gantt/colors';
import {
    conflictingDependencies,
    isConflict,
} from '@/components/gantt/conflicts';
import {
    addDays,
    daysInMonth,
    diffDays,
    endOfMonth,
    fromDay,
    isWeekend,
    startOfWeek,
    toDay,
    weekdayIndex,
} from '@/components/gantt/dates';
import {
    alignRange,
    applyDelta,
    createTimeline,
    dateToX,
    dayCenterX,
    dependencyPath,
    dragDelta,
    headerCells,
    DAY_WIDTH,
    milestoneBox,
    MILESTONE_SIZE,
    MIN_GRAB_WIDTH,
    resizeHandle,
    spanBox,
    taskSpan,
    unionSpan,
    weekendBands,
    xToDate,
} from '@/components/gantt/geometry';
import { filtersQuery, preferencesQuery } from '@/components/gantt/preferences';
import { buildPortfolioRows, buildTaskRows } from '@/components/gantt/rows';
import type { GanttProject, GanttTask } from '@/components/gantt/types';

function task(overrides: Partial<GanttTask>): GanttTask {
    return {
        id: 0,
        project_id: 1,
        parent_task_id: null,
        title: `Tarea ${overrides.id}`,
        start_date: null,
        due_date: null,
        is_milestone: false,
        is_completed: false,
        status: {
            id: 1,
            name: 'Por hacer',
            color: '#56667A',
            category: 'todo',
        },
        assignee: null,
        estimated_minutes: null,
        logged_minutes: 0,
        subtasks_count: 0,
        can: { update: true },
        ...overrides,
    };
}

describe('fechas sin zona horaria', () => {
    it('convierte fechas en números de día y vuelta', () => {
        expect(fromDay(toDay('2026-10-05'))).toBe('2026-10-05');
        expect(toDay('1970-01-02')).toBe(1);
        expect(() => toDay('05/10/2026')).toThrow(RangeError);
    });

    it('suma días cruzando meses, años, bisiestos y los cambios de hora', () => {
        expect(addDays('2026-01-31', 1)).toBe('2026-02-01');
        expect(addDays('2026-02-28', 1)).toBe('2026-03-01');
        expect(addDays('2028-02-28', 1)).toBe('2028-02-29');
        expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
        // Cambios de hora en Madrid (29/03 y 25/10/2026): un día sigue siendo un día.
        expect(addDays('2026-03-28', 1)).toBe('2026-03-29');
        expect(addDays('2026-10-24', 2)).toBe('2026-10-26');
        expect(diffDays('2026-03-28', '2026-03-30')).toBe(2);
        expect(diffDays('2026-10-26', '2026-10-24')).toBe(-2);
    });

    it('la semana empieza en lunes', () => {
        expect(weekdayIndex('2026-10-05')).toBe(0);
        expect(weekdayIndex('2026-10-11')).toBe(6);
        expect(startOfWeek('2026-10-08')).toBe('2026-10-05');
        expect(startOfWeek('2026-10-11')).toBe('2026-10-05');
        expect(startOfWeek('2026-10-05')).toBe('2026-10-05');
        expect(isWeekend('2026-10-10')).toBe(true);
        expect(isWeekend('2026-10-09')).toBe(false);
    });

    it('meses de 28 a 31 días', () => {
        expect(daysInMonth(2026, 2)).toBe(28);
        expect(daysInMonth(2028, 2)).toBe(29);
        expect(daysInMonth(2026, 4)).toBe(30);
        expect(daysInMonth(2026, 10)).toBe(31);
        expect(endOfMonth('2026-02-10')).toBe('2026-02-28');
        expect(endOfMonth('2028-02-10')).toBe('2028-02-29');
    });
});

describe('escala día', () => {
    const timeline = createTimeline(
        { start: '2026-10-05', end: '2026-10-18' },
        'day',
    );

    it('una columna de 32 px por día', () => {
        expect(timeline.days).toBe(14);
        expect(timeline.width).toBe(14 * 32);
        expect(dateToX(timeline, '2026-10-05')).toBe(0);
        expect(dateToX(timeline, '2026-10-07')).toBe(64);
        expect(dayCenterX(timeline, '2026-10-07')).toBe(80);
        expect(xToDate(timeline, 0)).toBe('2026-10-05');
        expect(xToDate(timeline, 63)).toBe('2026-10-06');
        expect(xToDate(timeline, 64)).toBe('2026-10-07');
    });

    it('las barras incluyen el día de inicio y el de entrega', () => {
        expect(
            spanBox(timeline, { start: '2026-10-06', end: '2026-10-08' }),
        ).toEqual({ x: 32, width: 96 });
        expect(
            spanBox(timeline, { start: '2026-10-06', end: '2026-10-06' }),
        ).toEqual({ x: 32, width: 32 });
    });

    it('el rombo del hito se centra en su día', () => {
        expect(milestoneBox(timeline, '2026-10-06')).toEqual({
            x: 48 - MILESTONE_SIZE / 2,
            width: MILESTONE_SIZE,
        });
    });

    it('sombrea sábados y domingos', () => {
        expect(weekendBands(timeline)).toEqual([
            { x: 5 * 32, width: 64 },
            { x: 12 * 32, width: 64 },
        ]);

        // Si el rango empieza en domingo, la primera banda es solo ese día.
        const fromSunday = createTimeline(
            { start: '2026-10-11', end: '2026-10-17' },
            'day',
        );
        expect(weekendBands(fromSunday)).toEqual([
            { x: 0, width: 32 },
            { x: 6 * 32, width: 32 },
        ]);
    });

    it('cabecera: meses arriba y días (inicial y número) abajo, con el fin de semana marcado', () => {
        const crossing = createTimeline(
            { start: '2026-10-29', end: '2026-11-02' },
            'day',
        );
        const { top, bottom } = headerCells(crossing);

        expect(top.map((cell) => [cell.label, cell.x, cell.width])).toEqual([
            ['octubre de 2026', 0, 3 * 32],
            ['noviembre de 2026', 3 * 32, 2 * 32],
        ]);
        expect(bottom.map((cell) => cell.label)).toEqual([
            'J 29',
            'V 30',
            'S 31',
            'D 1',
            'L 2',
        ]);
        expect(bottom.map((cell) => cell.weekend)).toEqual([
            false,
            false,
            true,
            true,
            false,
        ]);
        expect(bottom[0].title).toBe('jueves, 29 de octubre de 2026');
    });
});

describe('escala semana', () => {
    it('alinea el rango de lunes a domingo, 16 px por día', () => {
        expect(
            alignRange({ start: '2026-10-07', end: '2026-10-20' }, 'week'),
        ).toEqual({ start: '2026-10-05', end: '2026-10-25' });

        const timeline = createTimeline(
            { start: '2026-10-07', end: '2026-10-20' },
            'week',
        );

        expect(timeline.days).toBe(21);
        expect(timeline.width).toBe(21 * 16);
        expect(dateToX(timeline, '2026-10-12')).toBe(7 * 16);

        const { bottom } = headerCells(timeline);
        expect(bottom.map((cell) => [cell.label, cell.x, cell.width])).toEqual([
            ['5 oct', 0, 112],
            ['12 oct', 112, 112],
            ['19 oct', 224, 112],
        ]);
        expect(weekendBands(timeline)).toEqual([]);
    });

    it('las semanas que cruzan de mes parten la cabecera de meses', () => {
        const timeline = createTimeline(
            { start: '2026-10-28', end: '2026-11-03' },
            'week',
        );
        const { top } = headerCells(timeline);

        // Del lunes 26/10 al domingo 08/11.
        expect(top.map((cell) => [cell.label, cell.width])).toEqual([
            ['octubre de 2026', 6 * 16],
            ['noviembre de 2026', 8 * 16],
        ]);
    });
});

describe('escala mes', () => {
    const timeline = createTimeline(
        { start: '2026-01-15', end: '2026-03-10' },
        'month',
    );

    it('alinea a meses completos, 4 px por día, con meses de 28 a 31 días', () => {
        expect(timeline.start).toBe('2026-01-01');
        expect(timeline.end).toBe('2026-03-31');
        expect(timeline.days).toBe(31 + 28 + 31);
        expect(dateToX(timeline, '2026-02-01')).toBe(31 * 4);
        expect(dateToX(timeline, '2026-03-01')).toBe((31 + 28) * 4);

        const { top, bottom } = headerCells(timeline);
        expect(top.map((cell) => cell.label)).toEqual(['2026']);
        expect(bottom.map((cell) => [cell.label, cell.width])).toEqual([
            ['ene', 31 * 4],
            ['feb', 28 * 4],
            ['mar', 31 * 4],
        ]);
    });

    it('en un año bisiesto febrero tiene 29 días y el cambio de año parte la cabecera', () => {
        const leap = createTimeline(
            { start: '2027-12-10', end: '2028-02-10' },
            'month',
        );
        const { top, bottom } = headerCells(leap);

        expect(top.map((cell) => [cell.label, cell.width])).toEqual([
            ['2027', 31 * 4],
            ['2028', (31 + 29) * 4],
        ]);
        expect(bottom.map((cell) => cell.width)).toEqual([
            31 * 4,
            31 * 4,
            29 * 4,
        ]);
    });
});

describe('arrastrar y redimensionar', () => {
    it('redondea el desplazamiento al día más cercano', () => {
        expect(dragDelta(15, 32)).toBe(0);
        expect(dragDelta(17, 32)).toBe(1);
        expect(dragDelta(-17, 32)).toBe(-1);
        expect(dragDelta(-3, 32)).toBe(0);
        expect(Object.is(dragDelta(-3, 32), -0)).toBe(false);
        expect(dragDelta(30, 4)).toBe(8);
    });

    const dates = { start_date: '2026-10-05', due_date: '2026-10-07' };

    it('mover desplaza inicio y entrega', () => {
        expect(applyDelta(dates, 3, 'move')).toEqual({
            start_date: '2026-10-08',
            due_date: '2026-10-10',
        });
        expect(applyDelta(dates, -5, 'move')).toEqual({
            start_date: '2026-09-30',
            due_date: '2026-10-02',
        });
    });

    it('la entrega nunca queda antes del inicio, ni el inicio después de la entrega', () => {
        expect(applyDelta(dates, 2, 'end')).toEqual({
            start_date: '2026-10-05',
            due_date: '2026-10-09',
        });
        expect(applyDelta(dates, -5, 'end')).toEqual({
            start_date: '2026-10-05',
            due_date: '2026-10-05',
        });
        expect(applyDelta(dates, -2, 'start')).toEqual({
            start_date: '2026-10-03',
            due_date: '2026-10-07',
        });
        expect(applyDelta(dates, 5, 'start')).toEqual({
            start_date: '2026-10-07',
            due_date: '2026-10-07',
        });
    });

    it('con una sola fecha, cambiar la otra la crea desde la que hay', () => {
        const onlyDue = { start_date: null, due_date: '2026-10-07' };

        expect(applyDelta(onlyDue, 1, 'move')).toEqual({
            start_date: null,
            due_date: '2026-10-08',
        });
        expect(applyDelta(onlyDue, -2, 'start')).toEqual({
            start_date: '2026-10-05',
            due_date: '2026-10-07',
        });
        expect(
            applyDelta({ start_date: '2026-10-05', due_date: null }, 2, 'end'),
        ).toEqual({ start_date: '2026-10-05', due_date: '2026-10-07' });
    });

    it('un hito solo mueve su entrega', () => {
        const milestone = { start_date: null, due_date: '2026-10-16' };

        for (const mode of ['move', 'start', 'end'] as const) {
            expect(applyDelta(milestone, -1, mode, true)).toEqual({
                start_date: null,
                due_date: '2026-10-15',
            });
        }
    });
});

describe('tiradores de los bordes de la barra', () => {
    it('las barras muy estrechas no tienen tiradores: se mueven enteras', () => {
        // Escala mes: de 1 a 3 días (4 a 12 px).
        for (const days of [1, 2, 3]) {
            expect(resizeHandle(days * DAY_WIDTH.month)).toBeNull();
        }

        expect(MIN_GRAB_WIDTH).toBeGreaterThanOrEqual(16);
    });

    it('cada tirador ocupa como mucho un cuarto: queda al menos la mitad central para mover', () => {
        // Escala semana: una tarea de un día (16 px) deja 8 px para moverla, no 4.
        expect(resizeHandle(DAY_WIDTH.week)).toEqual({ size: 4, overhang: 0 });
        expect(resizeHandle(32)).toEqual({ size: 8, overhang: 0 });
        // Las anchas, como siempre: 10 px, 4 de ellos por fuera de la barra.
        expect(resizeHandle(40)).toEqual({ size: 10, overhang: 4 });
        expect(resizeHandle(3 * DAY_WIDTH.day)).toEqual({
            size: 10,
            overhang: 4,
        });

        for (let width = 16; width <= 400; width++) {
            const handle = resizeHandle(width);

            expect(handle).not.toBeNull();

            if (handle) {
                const inside = handle.size - handle.overhang;
                expect(width - 2 * inside).toBeGreaterThanOrEqual(width / 2);
            }
        }
    });
});

describe('marcas y flechas', () => {
    it('los días de una tarea y de un hito', () => {
        expect(
            taskSpan({ start_date: '2026-10-05', due_date: '2026-10-07' }),
        ).toEqual({ start: '2026-10-05', end: '2026-10-07' });
        expect(taskSpan({ start_date: null, due_date: '2026-10-07' })).toEqual({
            start: '2026-10-07',
            end: '2026-10-07',
        });
        expect(taskSpan({ start_date: null, due_date: null })).toBeNull();
        expect(
            taskSpan(
                { start_date: '2026-10-01', due_date: '2026-10-07' },
                true,
            ),
        ).toEqual({ start: '2026-10-07', end: '2026-10-07' });
        expect(
            unionSpan([
                null,
                { start: '2026-10-05', end: '2026-10-07' },
                { start: '2026-10-01', end: '2026-10-03' },
            ]),
        ).toEqual({ start: '2026-10-01', end: '2026-10-07' });
    });

    it('la flecha fin → inicio usa tramos ortogonales y rodea si la sucesora empieza antes', () => {
        expect(dependencyPath({ x: 100, y: 18 }, { x: 160, y: 54 })).toBe(
            'M 100 18 H 108 V 54 H 160',
        );
        expect(dependencyPath({ x: 100, y: 18 }, { x: 160, y: 18 })).toBe(
            'M 100 18 H 160',
        );
        expect(dependencyPath({ x: 100, y: 18 }, { x: 80, y: 54 })).toBe(
            'M 100 18 H 108 V 36 H 72 V 54 H 80',
        );
    });
});

describe('conflictos (D-057)', () => {
    it('una sucesora que empieza el mismo día o antes del fin de su predecesora está en conflicto', () => {
        const predecessor = {
            start_date: '2026-10-05',
            due_date: '2026-10-07',
        };

        expect(
            isConflict(predecessor, {
                start_date: '2026-10-07',
                due_date: '2026-10-09',
            }),
        ).toBe(true);
        expect(
            isConflict(predecessor, {
                start_date: '2026-10-08',
                due_date: '2026-10-09',
            }),
        ).toBe(false);
        // Sin inicio, cuenta su entrega.
        expect(
            isConflict(predecessor, {
                start_date: null,
                due_date: '2026-10-06',
            }),
        ).toBe(true);
        // Sin fechas, no hay conflicto.
        expect(
            isConflict(
                { start_date: '2026-10-05', due_date: null },
                { start_date: '2026-10-01', due_date: null },
            ),
        ).toBe(false);
        expect(
            isConflict(predecessor, { start_date: null, due_date: null }),
        ).toBe(false);
    });

    it('marca las dependencias en conflicto', () => {
        const tasks = new Map([
            [
                1,
                task({
                    id: 1,
                    start_date: '2026-10-05',
                    due_date: '2026-10-07',
                }),
            ],
            [
                2,
                task({
                    id: 2,
                    start_date: '2026-10-07',
                    due_date: '2026-10-08',
                }),
            ],
            [
                3,
                task({
                    id: 3,
                    start_date: '2026-10-09',
                    due_date: '2026-10-10',
                }),
            ],
        ]);

        expect(
            conflictingDependencies(
                [
                    {
                        id: 10,
                        predecessor_task_id: 1,
                        successor_task_id: 2,
                        type: 'finish_to_start',
                    },
                    {
                        id: 11,
                        predecessor_task_id: 2,
                        successor_task_id: 3,
                        type: 'finish_to_start',
                    },
                ],
                tasks,
            ),
        ).toEqual(new Set([10]));
    });
});

describe('filas', () => {
    it('tareas raíz, sus subtareas sangradas, resúmenes y la lista «Sin fechas»', () => {
        const { rows, unscheduled } = buildTaskRows([
            task({ id: 1, start_date: '2026-10-05', due_date: '2026-10-06' }),
            task({ id: 2 }),
            task({
                id: 3,
                parent_task_id: 2,
                start_date: '2026-10-08',
                due_date: '2026-10-09',
            }),
            task({ id: 4, parent_task_id: 2, due_date: '2026-10-12' }),
            task({ id: 5, parent_task_id: 2 }),
            task({ id: 6, due_date: '2026-10-16', is_milestone: true }),
            task({ id: 7 }),
            task({ id: 8, parent_task_id: 1 }),
        ]);

        expect(
            rows.map((row) => [row.task.id, row.depth, row.summary]),
        ).toEqual([
            [1, 0, null],
            [2, 0, { start: '2026-10-08', end: '2026-10-12' }],
            [3, 1, null],
            [4, 1, null],
            [6, 0, null],
        ]);
        expect(unscheduled.map((item) => item.id)).toEqual([8, 5, 7]);
    });

    it('el Gantt multiproyecto agrupa por proyecto, con su resumen, y los plegados ocultan sus tareas', () => {
        const project = (id: number): GanttProject => ({
            id,
            code: `P${id}`,
            name: `Proyecto ${id}`,
            color: '#0171FF',
            status: 'active',
            uses_hour_banks: false,
            client: null,
            owner: { id: 1, name: 'Ana' },
            start_date: null,
            due_date: null,
            can: { update: true },
        });
        const tasks = [
            task({
                id: 1,
                project_id: 1,
                start_date: '2026-10-05',
                due_date: '2026-10-06',
            }),
            task({ id: 2, project_id: 1, due_date: '2026-10-20' }),
            task({ id: 3, project_id: 1 }),
            task({
                id: 4,
                project_id: 2,
                start_date: '2026-11-02',
                due_date: '2026-11-03',
            }),
        ];

        const open = buildPortfolioRows(
            [project(1), project(2)],
            tasks,
            new Set(),
        );
        expect(open.rows.map((row) => row.key)).toEqual([
            'p-1',
            't-1',
            't-2',
            'p-2',
            't-4',
        ]);
        expect(open.rows[0]).toMatchObject({
            kind: 'project',
            span: { start: '2026-10-05', end: '2026-10-20' },
            scheduledCount: 2,
            unscheduledCount: 1,
        });

        const folded = buildPortfolioRows(
            [project(1), project(2)],
            tasks,
            new Set([1]),
        );
        expect(folded.rows.map((row) => row.key)).toEqual([
            'p-1',
            'p-2',
            't-4',
        ]);
        expect(folded.rows[0]).toMatchObject({ collapsed: true });
    });
});

describe('colores', () => {
    it('por responsable: --chart-1..6 en orden de aparición, «Otros» y «Sin responsable» en gris', () => {
        const people = Array.from({ length: 8 }, (_, index) => ({
            id: index + 1,
            name: `Persona ${index + 1}`,
            avatar: null,
        }));
        const tasks = [
            task({ id: 1, assignee: people[2] }),
            task({ id: 2, assignee: people[0] }),
            task({ id: 3, assignee: people[2] }),
            ...people
                .slice(1)
                .map((person, index) =>
                    task({ id: 10 + index, assignee: person }),
                ),
            task({ id: 30 }),
        ];

        const colors = assigneeColors(tasks);

        expect(colors.colorOf(tasks[0])).toEqual({
            color: 'var(--chart-1)',
            dashed: false,
        });
        expect(colors.colorOf(tasks[1])).toEqual({
            color: 'var(--chart-2)',
            dashed: false,
        });
        expect(
            colors.legend.map((entry) => [entry.label, entry.color]),
        ).toEqual([
            ['Persona 3', 'var(--chart-1)'],
            ['Persona 1', 'var(--chart-2)'],
            ['Persona 2', 'var(--chart-3)'],
            ['Persona 4', 'var(--chart-4)'],
            ['Persona 5', 'var(--chart-5)'],
            ['Persona 6', 'var(--chart-6)'],
            ['Otros', NEUTRAL_COLOR],
            ['Sin responsable', NEUTRAL_COLOR],
        ]);
        expect(colors.legend.at(-1)?.dashed).toBe(true);
        expect(colors.colorOf(task({ id: 99, assignee: people[7] }))).toEqual({
            color: NEUTRAL_COLOR,
            dashed: false,
        });
        expect(colors.colorOf(task({ id: 98 }))).toEqual({
            color: NEUTRAL_COLOR,
            dashed: true,
        });
    });

    it('por estado: el color de cada estado, en el orden de los estados y solo los usados', () => {
        const statuses = [
            {
                id: 1,
                name: 'Por hacer',
                color: '#56667A',
                category: 'todo' as const,
            },
            {
                id: 2,
                name: 'En curso',
                color: '#0171FF',
                category: 'in_progress' as const,
            },
            {
                id: 3,
                name: 'Hecha',
                color: '#179FA5',
                category: 'done' as const,
            },
        ];
        const colors = statusColors(
            [
                task({ id: 1, status: statuses[2] }),
                task({ id: 2, status: statuses[0] }),
            ],
            statuses,
        );

        expect(colors.legend.map((entry) => [entry.label, entry.done])).toEqual(
            [
                ['Por hacer', false],
                ['Hecha', true],
            ],
        );
        expect(colors.colorOf(task({ id: 3, status: statuses[1] })).color).toBe(
            '#0171FF',
        );
    });
});

describe('preferencias y filtros en la URL', () => {
    it('solo lleva a la URL lo que no está por defecto, en español', () => {
        expect(preferencesQuery({ scale: 'week', color: 'status' })).toEqual(
            {},
        );
        expect(preferencesQuery({ scale: 'day', color: 'assignee' })).toEqual({
            escala: 'dia',
            color: 'responsable',
        });
        expect(preferencesQuery({ scale: 'month', color: 'status' })).toEqual({
            escala: 'mes',
        });
        expect(
            filtersQuery({
                cliente: 3,
                departamento: null,
                responsable: 5,
                estado: 'active',
            }),
        ).toEqual({ cliente: '3', responsable: '5' });
        expect(
            filtersQuery({
                cliente: null,
                departamento: 2,
                responsable: null,
                estado: 'todos',
            }),
        ).toEqual({ departamento: '2', estado: 'todos' });
    });
});
