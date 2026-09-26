import { describe, expect, it } from 'vitest';
import {
    normalizeProjectCode,
    suggestProjectCode,
} from '@/components/projects-list/project-code';
import { projectFiltersQuery } from '@/components/projects-list/project-filters';

/**
 * Código sugerido de un proyecto: mismos casos que
 * tests/Feature/Projects/ProjectCodeSuggesterTest.php (App\Domain\Projects\ProjectCodeSuggester).
 */
describe('suggestProjectCode', () => {
    it.each([
        ['Acme', 'Web corporativa', 'ACME-WEB'],
        ['Clínica Dental Sonríe', 'App de citas', 'CLINICA-APP'],
        ['El Corte Inglés', 'La tienda online', 'CORTE-TIENDA'],
        ['Hoteles Mediterráneo', 'Mantenimiento web', 'HOTELES-MANTENIM'],
        ['Peñíscola Açaí', 'Diseño', 'PENISCOL-DISENO'],
        [null, 'Formación interna', 'FORMACIO'],
        ['Acme', 'Acme', 'ACME'],
        ['Bodegas 1920', 'Campaña 2026', 'BODEGAS-CAMPANA'],
    ])('%s + %s → %s', (client, name, expected) => {
        expect(suggestProjectCode(client, name)).toBe(expected);
    });

    it('sin nada aprovechable deja el código vacío (lo genera el servidor)', () => {
        expect(suggestProjectCode(null, 'de la')).toBe('');
    });
});

describe('normalizeProjectCode', () => {
    it('mayúsculas, sin acentos y espacios como guiones, 20 caracteres como mucho', () => {
        expect(normalizeProjectCode('acme web')).toBe('ACME-WEB');
        expect(normalizeProjectCode('diseño')).toBe('DISENO');
        expect(normalizeProjectCode('a'.repeat(30))).toHaveLength(20);
    });
});

describe('projectFiltersQuery', () => {
    it('solo pone en la URL los filtros que no están por defecto', () => {
        expect(
            projectFiltersQuery({
                cliente: null,
                estado: '',
                tipo: null,
                responsable: null,
                departamento: null,
                buscar: '  ',
                mios: false,
            }),
        ).toEqual({});

        expect(
            projectFiltersQuery({
                cliente: 3,
                estado: 'todos',
                tipo: 'hour_bank',
                responsable: 5,
                departamento: 2,
                buscar: ' acme ',
                mios: true,
            }),
        ).toEqual({
            cliente: 3,
            estado: 'todos',
            tipo: 'hour_bank',
            responsable: 5,
            departamento: 2,
            buscar: 'acme',
            mios: 1,
        });
    });
});
