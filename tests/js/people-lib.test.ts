import { describe, expect, it } from 'vitest';
import { missingMinutes } from '@/components/people/use-clock-summary';
import {
    countChanges,
    formatDifference,
    liveWorkedSeconds,
    madridDate,
    newRow,
    nextKind,
    rowsFromEvents,
    rowsPayload,
    segmentsLabel,
    shiftMonth,
    tCount,
} from '@/lib/people';
import type { ClockShared, DayWorkday } from '@/types/people';

/*
| Utilidades del registro de jornada (Fase 11, R1): la diferencia con signo, los tramos del día, el
| tiempo en vivo con el reloj del servidor y las filas del formulario de corrección.
*/

const clock = (overrides: Partial<ClockShared> = {}): ClockShared => ({
    status: 'working',
    since: '2026-10-05T07:00:00Z',
    running_since: '2026-10-05T07:00:00Z',
    worked_seconds: 0,
    work_mode: 'on_site',
    unclosed_date: null,
    server_now: '2026-10-05T09:00:00Z',
    ...overrides,
});

describe('registro de jornada: utilidades', () => {
    it('la diferencia lleva signo y se queda vacía sin dato', () => {
        expect(formatDifference(30)).toBe('+0:30');
        expect(formatDifference(-75)).toBe('-1:15');
        expect(formatDifference(0)).toBe('0:00');
        expect(formatDifference(null)).toBe('');
    });

    it('el día de Madrid de un instante (también al cruzar la medianoche UTC)', () => {
        expect(madridDate('2026-10-05T22:30:00Z')).toBe('2026-10-06');
        expect(madridDate('2026-01-05T22:30:00Z')).toBe('2026-01-05');
    });

    it('los tramos del día, con la salida del día siguiente y la jornada en curso', () => {
        const workdays: DayWorkday[] = [
            {
                clock_in: '2026-10-05T20:00:00Z',
                clock_out: '2026-10-06T00:30:00Z',
                open: false,
                stale: false,
                segments: [
                    {
                        kind: 'work',
                        from: '2026-10-05T20:00:00Z',
                        to: '2026-10-06T00:30:00Z',
                        work_mode: 'remote',
                    },
                ],
            },
        ];

        expect(segmentsLabel(workdays, '2026-10-05')).toBe('22:00–02:30 (+1)');

        expect(
            segmentsLabel(
                [
                    {
                        clock_in: '2026-10-05T07:00:00Z',
                        clock_out: null,
                        open: true,
                        stale: false,
                        segments: [
                            {
                                kind: 'work',
                                from: '2026-10-05T07:00:00Z',
                                to: '2026-10-05T12:00:00Z',
                                work_mode: 'on_site',
                            },
                            {
                                kind: 'pause',
                                from: '2026-10-05T12:00:00Z',
                                to: '2026-10-05T13:00:00Z',
                                work_mode: null,
                            },
                            {
                                kind: 'work',
                                from: '2026-10-05T13:00:00Z',
                                to: null,
                                work_mode: 'on_site',
                            },
                        ],
                    },
                ],
                '2026-10-05',
            ),
        ).toBe('09:00–14:00 · 15:00–en curso');
    });

    it('el tiempo trabajado en vivo usa la hora del servidor, no la del dispositivo', () => {
        const deviceNow = new Date('2026-10-05T08:00:00Z').getTime();

        // El dispositivo va una hora atrasado: con la diferencia, cuenta 2 h.
        expect(
            liveWorkedSeconds(
                clock({ worked_seconds: 600 }),
                deviceNow,
                3600_000,
            ),
        ).toBe(600 + 7200);
        expect(
            liveWorkedSeconds(
                clock({ running_since: null, worked_seconds: 900 }),
                deviceNow,
            ),
        ).toBe(900);
    });

    it('cambia de mes', () => {
        expect(shiftMonth('2026-01', -1)).toBe('2025-12');
        expect(shiftMonth('2026-12', 1)).toBe('2027-01');
    });

    it('singular, plural y cero', () => {
        expect(tCount('people.workday.incident_days', 0)).toBe(
            'Sin incidencias',
        );
        expect(tCount('people.workday.incident_days', 1)).toBe(
            '1 día con incidencias',
        );
        expect(tCount('people.workday.incident_days', 3)).toBe(
            '3 días con incidencias',
        );
        expect(tCount('people.correction.changes', 2)).toBe('2 cambios');
        expect(tCount('people.nav.pending_count', 0)).toBe(
            '0 pendientes de decidir',
        );
    });

    it('las filas del formulario: hora de Madrid, día siguiente y cambios como en el servidor', () => {
        const original = rowsFromEvents(
            [
                {
                    id: 1,
                    kind: 'clock_in',
                    at: '2026-10-05T20:00:00Z',
                    work_mode: 'remote',
                },
                {
                    id: 2,
                    kind: 'clock_out',
                    at: '2026-10-06T00:15:00Z',
                    work_mode: null,
                },
            ],
            '2026-10-05',
        );

        expect(original.map((row) => [row.time, row.next_day])).toEqual([
            ['22:00', false],
            ['02:15', true],
        ]);
        expect(countChanges(original, original)).toBe(0);

        // Mover la salida: anular una y añadir otra.
        const moved = [original[0], { ...original[1], time: '02:45' }];
        expect(countChanges(original, moved)).toBe(2);

        // Quitar la salida: una anulación.
        expect(countChanges(original, [original[0]])).toBe(1);

        // Añadir una pausa: un añadido.
        expect(
            countChanges(original, [
                ...original,
                newRow('pause_start', '23:00'),
            ]),
        ).toBe(1);

        expect(rowsPayload(moved)).toEqual([
            {
                id: 1,
                kind: 'clock_in',
                time: '22:00',
                next_day: false,
                work_mode: 'remote',
            },
            {
                id: 2,
                kind: 'clock_out',
                time: '02:45',
                next_day: true,
                work_mode: null,
            },
        ]);
    });

    it('propone el siguiente fichaje lógico y el modo solo donde va', () => {
        expect(nextKind([])).toBe('clock_in');
        expect(nextKind([newRow('clock_in')])).toBe('clock_out');
        expect(nextKind([newRow('clock_in'), newRow('pause_start')])).toBe(
            'pause_end',
        );
        expect(newRow('pause_end', '15:00', 'remote').work_mode).toBe('remote');
        expect(newRow('clock_out', '18:00', 'remote').work_mode).toBeNull();
    });

    it('lo que falta por imputar al cerrar la jornada', () => {
        expect(
            missingMinutes({
                date: '2026-10-05',
                worked_minutes: 470,
                logged_minutes: 370,
            }),
        ).toBe(100);
        expect(
            missingMinutes({
                date: '2026-10-05',
                worked_minutes: 300,
                logged_minutes: 360,
            }),
        ).toBe(0);
    });
});
