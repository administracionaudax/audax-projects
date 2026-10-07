import { describe, expect, it } from 'vitest';
import {
    capLevel,
    capPercent,
    closeStatusLabel,
    destinationLabel,
    exportKindLabel,
    flexFor,
    formatBytes,
    reportDownloadUrl,
    reportQuery,
    restMinutes,
    shortHash,
    signedMinutes,
} from '@/lib/people-register';

/*
| Utilidades del registro de R2 (D-346 a D-359): el tope de horas extra (gemelo de
| OvertimeService::level), el descanso que genera una hora extra compensada (80 minutos por hora),
| la flexibilidad, las huellas cortas y las URL de los informes.
*/

describe('horas extra', () => {
    it('el nivel del tope de 80 h: aviso desde 60 h, tope a las 80 h', () => {
        expect(capLevel(0)).toBe('ok');
        expect(capLevel(59 * 60 + 59)).toBe('ok');
        expect(capLevel(60 * 60)).toBe('near');
        expect(capLevel(80 * 60 - 1)).toBe('near');
        expect(capLevel(80 * 60)).toBe('over');
    });

    it('la barra nunca pasa del 100 %', () => {
        expect(capPercent(40 * 60, 80 * 60)).toBe(50);
        expect(capPercent(120 * 60, 80 * 60)).toBe(100);
        expect(capPercent(10, 0)).toBe(0);
    });

    it('cada hora extra compensada da 80 minutos de descanso', () => {
        expect(restMinutes(60)).toBe(80);
        expect(restMinutes(90)).toBe(120);
        expect(restMinutes(45)).toBe(60);
        expect(restMinutes(60, 60)).toBe(60);
    });

    it('la flexibilidad es el exceso que no es hora extra; fuera de rango, null', () => {
        expect(flexFor(120, 90)).toBe(30);
        expect(flexFor(120, 0)).toBe(120);
        expect(flexFor(120, 120)).toBe(0);
        expect(flexFor(120, 121)).toBeNull();
        expect(flexFor(120, -1)).toBeNull();
        expect(flexFor(120, 1.5)).toBeNull();
    });

    it('los textos del destino y del estado del cierre', () => {
        expect(destinationLabel('compensate')).toBe('Compensar con descanso');
        expect(destinationLabel('pay')).toBe('Pagar');
        expect(destinationLabel(null)).toBe('Flexibilidad');
        expect(closeStatusLabel('confirmed')).toBe('Confirmado');
        expect(closeStatusLabel('missing')).toBe('Sin generar');
    });
});

describe('formatos', () => {
    it('minutos con signo, huellas cortas y tamaños', () => {
        expect(signedMinutes(120)).toBe('+2:00');
        expect(signedMinutes(-100)).toBe('-1:40');
        expect(signedMinutes(0)).toBe('0:00');
        expect(shortHash('a'.repeat(64))).toBe(`${'a'.repeat(12)}…`);
        expect(shortHash(null)).toBe('');
        expect(formatBytes(900)).toBe('900 B');
        expect(formatBytes(2048)).toBe('2 KB');
        expect(formatBytes(1.5 * 1024 * 1024)).toBe('1,5 MB');
        expect(exportKindLabel('itss')).toBe('Exportación para la Inspección');
        expect(exportKindLabel('desconocido')).toBe('desconocido');
    });
});

describe('informes', () => {
    const base = {
        kind: 'registro-mensual' as const,
        monthly: true,
        month: '2026-09',
        from: '2026-09-01',
        to: '2026-09-30',
        userIds: [] as number[],
        departmentId: null,
    };

    it('los mensuales van por mes y el resto por periodo', () => {
        expect(reportQuery(base)).toEqual({ mes: '2026-09' });
        expect(
            reportQuery({ ...base, kind: 'fichajes', monthly: false }),
        ).toEqual({ desde: '2026-09-01', hasta: '2026-09-30' });
        expect(
            reportQuery({ ...base, userIds: [3, 5], departmentId: 2 }),
        ).toEqual({
            mes: '2026-09',
            'personas[]': ['3', '5'],
            departamento: '2',
        });
    });

    it('la URL de descarga lleva el informe, el ámbito y el formato', () => {
        expect(reportDownloadUrl(base, 'pdf')).toBe(
            '/personas/informes/registro-mensual?mes=2026-09&formato=pdf',
        );
        expect(
            reportDownloadUrl(
                { ...base, kind: 'fichajes', monthly: false, userIds: [7] },
                'csv',
            ),
        ).toBe(
            '/personas/informes/fichajes?desde=2026-09-01&hasta=2026-09-30&personas%5B%5D=7&formato=csv',
        );
    });
});
