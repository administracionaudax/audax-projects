import { describe, expect, it } from 'vitest';
import {
    allocationAmountLabel,
    allocationWho,
    cellLevel,
    cellLoad,
    deviationKind,
    deviationLabel,
    deviationPercent,
} from '@/lib/forecast';
import deviation from '../fixtures/forecast-deviation.json';

/*
| Previsión (D-283, D-287): la carga de una celda con las capas encendidas, su nivel y la desviación
| de «estimado frente a real» con los mismos casos que PHP (EstimateVsActual::deviation).
*/

describe('carga de una celda', () => {
    const cell = { capacity: 480, real: 240, firm: 120, tentative: 240 };

    it('suma las capas encendidas', () => {
        expect(cellLoad(cell)).toBe(600);
        expect(
            cellLoad(cell, { real: true, firm: true, tentative: false }),
        ).toBe(360);
        expect(
            cellLoad(cell, { real: false, firm: false, tentative: false }),
        ).toBe(0);
    });

    it('el nivel es el semáforo de la Carga sobre las capas encendidas', () => {
        expect(cellLevel(cell)).toBe('over');
        expect(
            cellLevel(cell, { real: true, firm: true, tentative: false }),
        ).toBe('balanced');
        expect(cellLevel({ ...cell, capacity: 0 })).toBe('none');
    });
});

describe('desviación', () => {
    it.each(deviation.cases)(
        '$estimated → $actual = $percent ($kind)',
        ({ estimated, actual, percent, kind }) => {
            expect(deviationPercent(estimated, actual)).toBe(percent);
            expect(deviationKind(deviationPercent(estimated, actual))).toBe(
                kind,
            );
        },
    );

    it('la etiqueta', () => {
        expect(deviationLabel(null)).toBe('Sin estimado');
        expect(deviationLabel(0.4)).toBe('Igual que lo estimado');
        expect(deviationLabel(15.7)).toMatch(/^\+15,7\s%$/);
        expect(deviationLabel(-6.3)).toMatch(/^−6,3\s%$/);
    });
});

describe('asignaciones', () => {
    it('describe la cantidad según el modo', () => {
        expect(
            allocationAmountLabel({
                mode: 'total',
                minutes: 4800,
                percent: null,
            }),
        ).toBe('80:00 en total');
        expect(
            allocationAmountLabel({
                mode: 'per_day',
                minutes: 240,
                percent: null,
            }),
        ).toBe('4:00 al día');
        expect(
            allocationAmountLabel({
                mode: 'percent',
                minutes: null,
                percent: 50,
            }),
        ).toBe('50 %');
        expect(
            allocationAmountLabel({
                mode: 'monthly',
                minutes: 1200,
                percent: null,
            }),
        ).toBe('20:00 al mes');
    });

    it('quién: la persona o el hueco del departamento', () => {
        expect(
            allocationWho({
                user: { id: 1, name: 'Ana', department_id: 2 },
                department: null,
            }),
        ).toBe('Ana');
        expect(
            allocationWho({
                user: null,
                department: { id: 2, name: 'Diseño', color: '#0171FF' },
            }),
        ).toBe('Diseño (hueco)');
    });
});
