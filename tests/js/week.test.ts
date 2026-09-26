import { describe, expect, it } from 'vitest';
import {
    addDays,
    isoWeek,
    todayInMadrid,
    weekDays,
    weekFromIso,
    weekStart,
} from '@/lib/week';

describe('semanas ISO (lunes a domingo)', () => {
    it('calcula el lunes de la semana', () => {
        expect(weekStart('2026-09-24')).toBe('2026-09-21');
        expect(weekStart('2026-09-21')).toBe('2026-09-21');
        expect(weekStart('2026-09-27')).toBe('2026-09-21');
    });

    it('devuelve los 7 días empezando en lunes', () => {
        expect(weekDays('2026-09-24')).toEqual([
            '2026-09-21',
            '2026-09-22',
            '2026-09-23',
            '2026-09-24',
            '2026-09-25',
            '2026-09-26',
            '2026-09-27',
        ]);
    });

    it('cruza el cambio de hora sin saltarse días', () => {
        expect(addDays('2026-10-24', 1)).toBe('2026-10-25');
        expect(addDays('2026-10-25', 1)).toBe('2026-10-26');
        expect(addDays('2026-03-29', 1)).toBe('2026-03-30');
    });

    it('convierte a y desde el formato 2026-W39', () => {
        expect(isoWeek('2026-09-24')).toBe('2026-W39');
        expect(weekFromIso('2026-W39')).toBe('2026-09-21');
        // El 1 de enero de 2027 (viernes) pertenece a la semana 53 de 2026.
        expect(isoWeek('2027-01-01')).toBe('2026-W53');
        expect(weekFromIso('2026-W53')).toBe('2026-12-28');
        expect(isoWeek('2025-12-29')).toBe('2026-W01');
    });

    it('rechaza semanas no válidas', () => {
        expect(weekFromIso('2026-W00')).toBeNull();
        expect(weekFromIso('2025-W53')).toBeNull();
        expect(weekFromIso('semana')).toBeNull();
    });

    it('hoy es el día de Madrid, no el de UTC', () => {
        // 23:30 UTC del 26/09 son las 01:30 del 27/09 en Madrid.
        expect(todayInMadrid(new Date('2026-09-26T23:30:00Z'))).toBe(
            '2026-09-27',
        );
    });
});
