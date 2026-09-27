import { describe, expect, it } from 'vitest';
import {
    isoWeekday,
    MAX_DATE,
    MIN_DATE,
    nextOccurrence,
    occurrencesBetween,
} from '@/components/recurring/recurrence';
import type { Recurrence } from '@/components/recurring/recurrence';
import fixture from '../fixtures/recurrence-cases.json';

/*
| Fechas de las tareas recurrentes en el navegador (vista previa del formulario, D-059): mismos
| casos que App\Models\RecurringTaskRule::occurrencesBetween() en el servidor
| (tests/Feature/Recurring/RecurrenceDatesTest.php).
*/

type Case = {
    name: string;
    rule: Recurrence;
    from: string;
    to: string;
    expected: string[];
};

const cases = fixture.cases as Case[];

describe('occurrencesBetween (gemelo de RecurringTaskRule::occurrencesBetween)', () => {
    it.each(cases.map((item) => [item.name, item] as const))(
        '%s',
        (_, { rule, from, to, expected }) => {
            expect(occurrencesBetween(rule, from, to)).toEqual(expected);
        },
    );

    it('el rango admitido es el del servidor (2000-2100)', () => {
        expect([MIN_DATE, MAX_DATE]).toEqual(['2000-01-01', '2100-12-31']);
    });

    it('una regla que empezó hace siglos no se recorre desde el principio', () => {
        const ancient: Recurrence = {
            frequency: 'weekly',
            interval: 1,
            weekday: 3,
            month_day: null,
            starts_on: '0001-01-01',
            ends_on: null,
        };
        const started = performance.now();

        for (let i = 0; i < 2_000; i++) {
            occurrencesBetween(ancient, '2100-12-01', '2100-12-31');
        }

        // Recorriendo 110.000 semanas desde el año 1 serían minutos; saltando, milisegundos.
        expect(performance.now() - started).toBeLessThan(2_000);
        expect(occurrencesBetween(ancient, '2100-12-01', '2100-12-31')).toEqual(
            [
                '2100-12-01',
                '2100-12-08',
                '2100-12-15',
                '2100-12-22',
                '2100-12-29',
            ],
        );
        expect(nextOccurrence(ancient, '2026-10-05')).toBe('2026-10-07');
    });

    it('el día de la semana vale también para los años 1 a 99', () => {
        // El 1 de enero del año 1 fue lunes (calendario gregoriano proléptico).
        expect(isoWeekday('0001-01-01')).toBe(1);
        expect(isoWeekday('0099-12-31')).toBe(4);
        expect(isoWeekday('1970-01-01')).toBe(4);
        expect(isoWeekday('2026-10-05')).toBe(1);
        expect(isoWeekday('2026-10-11')).toBe(7);
    });
});
