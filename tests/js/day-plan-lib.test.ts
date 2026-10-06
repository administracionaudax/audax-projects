import { describe, expect, it } from 'vitest';
import {
    activeToken,
    carryLabel,
    carryOptions,
    dayLabel,
    matchTargets,
    normalize,
    parseLine,
    pendingLabel,
    removeToken,
} from '@/lib/day-plan';
import type { DayPlanTargets } from '@/types/day-plan';

/*
| Plan del día (D-250): atajos de la línea (@cliente, #proyecto, ~1:30) y etiquetas de días.
*/

const targets: DayPlanTargets = {
    clients: [
        { id: 1, name: 'ACME' },
        { id: 2, name: 'Bodegas Ruiz' },
        { id: 3, name: 'Diseño Ñandú' },
    ],
    projects: [
        { id: 10, code: 'KIWI-CONF', name: 'Configurador', color: '#0171FF', client_id: 4, client_name: 'Kiwi', is_mine: true, is_internal: false },
        { id: 11, code: 'ACME-Q4', name: 'Campaña Q4', color: '#179FA5', client_id: 1, client_name: 'ACME', is_mine: false, is_internal: false },
    ],
};

describe('atajos al escribir', () => {
    it('detecta el @ o el # que se está escribiendo antes del cursor', () => {
        expect(activeToken('Creatividades @ac', 17)).toEqual({ kind: '@', query: 'ac', start: 14, end: 17 });
        expect(activeToken('#kiw', 4)).toEqual({ kind: '#', query: 'kiw', start: 0, end: 4 });
        expect(activeToken('correo@acme.com', 15)).toBeNull();
        expect(activeToken('Reunión @ac y más', 17)).toBeNull();
    });

    it('quita el atajo elegido del texto', () => {
        const text = 'Creatividades @ac campaña';
        const token = activeToken(text, 17);

        expect(token).not.toBeNull();
        expect(removeToken(text, token!)).toBe('Creatividades campaña');
    });

    it('busca clientes con @ y proyectos con # (sin acentos, primero los que empiezan así)', () => {
        expect(matchTargets('@', 'nandu', targets).map((match) => match.type === 'client' && match.client.id)).toEqual([3]);
        expect(matchTargets('@', 'd', targets).map((match) => match.type === 'client' && match.client.name)).toEqual(['Diseño Ñandú', 'Bodegas Ruiz']);
        expect(matchTargets('#', 'acme', targets).map((match) => match.type === 'project' && match.project.id)).toEqual([11]);
        expect(matchTargets('#', 'kiwi', targets)).toHaveLength(1);
        expect(matchTargets('#', 'x', undefined)).toEqual([]);
        expect(normalize('Diseño ÁÉ')).toBe('diseno ae');
    });

    it('separa las horas previstas con ~ y avisa si no se entienden', () => {
        expect(parseLine('JS del configurador ~1:30')).toEqual({ text: 'JS del configurador', minutes: 90, invalidDuration: false });
        expect(parseLine('~90m Revisar textos')).toEqual({ text: 'Revisar textos', minutes: 90, invalidDuration: false });
        expect(parseLine('Moodboard ~1,5')).toEqual({ text: 'Moodboard', minutes: 90, invalidDuration: false });
        expect(parseLine('Llamar ~mañana')).toMatchObject({ minutes: null, invalidDuration: true });
        expect(parseLine('Reunión @ 10')).toEqual({ text: 'Reunión @ 10', minutes: null, invalidDuration: false });
    });
});

describe('días y pendientes', () => {
    it('nombra los días respecto a hoy', () => {
        expect(dayLabel('2026-10-07', '2026-10-07')).toBe('hoy');
        expect(dayLabel('2026-10-08', '2026-10-07')).toBe('mañana');
        expect(dayLabel('2026-10-06', '2026-10-07')).toBe('ayer');
        expect(dayLabel('2026-10-12', '2026-10-07')).toBe('lunes 12/10');
    });

    it('resume las pendientes de días anteriores', () => {
        const line = { id: 1, text: 'x', carry_count: 0, client: null, project: null };

        expect(pendingLabel([{ ...line, date: '2026-10-06' }], '2026-10-07')).toBe('Tienes 1 pendiente de ayer.');
        expect(pendingLabel([{ ...line, date: '2026-10-05' }, { ...line, id: 2, date: '2026-10-05' }], '2026-10-07')).toBe('Tienes 2 pendientes del lunes.');
        expect(pendingLabel([{ ...line, date: '2026-10-05' }, { ...line, id: 2, date: '2026-10-06' }], '2026-10-07')).toBe('Tienes 2 pendientes de días anteriores.');
    });

    it('explica la marca ↻ ×N y ofrece los días hasta el horizonte', () => {
        expect(carryLabel(1)).toBe('Viene arrastrada de 1 día');
        expect(carryLabel(3)).toBe('Viene arrastrada de 3 días');
        expect(carryOptions('2026-10-07', '2026-10-07', '2026-10-10')).toEqual(['2026-10-08', '2026-10-09', '2026-10-10']);
        expect(carryOptions('2026-10-06', '2026-10-07', '2026-10-08')).toEqual(['2026-10-07', '2026-10-08']);
    });
});
