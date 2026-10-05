import { describe, expect, it } from 'vitest';
import {
    formatCurrency,
    formatDate,
    formatDateTime,
    formatMinutes,
    formatNumber,
    formatPercent,
    formatTime,
    formatTimeRange,
} from '@/lib/format';

// Intl usa espacios de no separación (U+00A0 / U+202F) antes de € y %.
const norm = (value: string) => value.replace(/[  ]/g, ' ');

describe('formatMinutes', () => {
    it.each([
        [0, '0:00'],
        [5, '0:05'],
        [60, '1:00'],
        [150, '2:30'],
        [1500, '25:00'],
        [-30, '-0:30'],
    ])('%i minutos → %s', (minutes, expected) => {
        expect(formatMinutes(minutes)).toBe(expected);
    });

    it('devuelve vacío para valores no numéricos', () => {
        expect(formatMinutes(Number.NaN)).toBe('');
    });
});

describe('formatDate', () => {
    it('no convierte de zona las fechas de imputación (sin hora)', () => {
        expect(formatDate('2026-09-26')).toBe('26/09/2026');
        expect(formatDate('2026-01-01')).toBe('01/01/2026');
    });

    it('muestra los instantes UTC en Europe/Madrid (cruce de medianoche)', () => {
        // 22:30 UTC del 26/09 son las 00:30 del 27/09 en Madrid (horario de verano, UTC+2)
        expect(formatDate('2026-09-26T22:30:00Z')).toBe('27/09/2026');
    });

    it('vacío para null, undefined o fechas inválidas', () => {
        expect(formatDate(null)).toBe('');
        expect(formatDate(undefined)).toBe('');
        expect(formatDate('no-es-fecha')).toBe('');
    });
});

describe('formatDateTime', () => {
    it('respeta el cambio de horario de verano a invierno', () => {
        // 25/10/2026 a las 03:00 (verano) vuelven a ser las 02:00: UTC+2 → UTC+1
        expect(formatDateTime('2026-10-24T22:00:00Z')).toBe('25/10/2026 00:00');
        expect(formatDateTime('2026-10-25T12:00:00Z')).toBe('25/10/2026 13:00');
    });

    it('respeta el cambio de invierno a verano', () => {
        expect(formatDateTime('2026-03-29T00:30:00Z')).toBe('29/03/2026 01:30');
        expect(formatDateTime('2026-03-29T01:30:00Z')).toBe('29/03/2026 03:30');
    });
});

describe('formatCurrency', () => {
    it('usa el formato es-ES con separador de miles también en 4 cifras', () => {
        expect(norm(formatCurrency(1234.56))).toBe('1.234,56 €');
        expect(norm(formatCurrency(12))).toBe('12,00 €');
        expect(norm(formatCurrency(1250000))).toBe('1.250.000,00 €');
    });

    it('acepta decimales de Laravel como string', () => {
        expect(norm(formatCurrency('990.50'))).toBe('990,50 €');
    });

    it('vacío para null o valores no numéricos', () => {
        expect(formatCurrency(null)).toBe('');
        expect(formatCurrency('abc')).toBe('');
    });
});

describe('formatNumber y formatPercent', () => {
    it('formatea números en es-ES', () => {
        expect(formatNumber(1234.5)).toBe('1.234,5');
    });

    it('formatea porcentajes, incluidos los mayores del 100 %', () => {
        expect(norm(formatPercent(0.756))).toBe('75,6 %');
        expect(norm(formatPercent(1.04))).toBe('104 %');
    });
});

describe('formatTime y formatTimeRange (D-172)', () => {
    it('da la hora de Madrid de un instante UTC', () => {
        expect(formatTime('2026-09-24T07:05:00Z')).toBe('09:05');
        expect(formatTime('2026-12-24T07:05:00Z')).toBe('08:05');
        expect(formatTime(null)).toBe('');
    });

    it('pinta la franja y la medianoche del final como 24:00', () => {
        expect(
            formatTimeRange('2026-09-24T07:00:00Z', '2026-09-24T09:30:00Z'),
        ).toBe('09:00–11:30');
        expect(
            formatTimeRange('2026-09-24T20:00:00Z', '2026-09-24T22:00:00Z'),
        ).toBe('22:00–24:00');
        expect(formatTimeRange('2026-09-24T07:00:00Z', null)).toBe('');
    });
});
