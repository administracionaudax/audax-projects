import { describe, expect, it } from 'vitest';
import { splitKeywords, stripKeywords } from '@/components/keyword-text';
import { hasTranslation, t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import messages from '../../lang/es.json';

// Textos del frontend por área (Fase 1): lang/ui/*.json. Laravel no los lee.
const areaFiles = import.meta.glob<Record<string, string>>(
    '../../lang/ui/*.json',
    { eager: true, import: 'default' },
);
const allFiles: Record<string, Record<string, string>> = {
    'lang/es.json': messages,
    ...areaFiles,
};

describe('t()', () => {
    it('devuelve la traducción de una clave', () => {
        expect(t('nav.home')).toBe('Inicio');
        expect(t('user_menu.logout')).toBe('Cerrar sesión');
    });

    it('sustituye los reemplazos al estilo Laravel', () => {
        expect(t('home.greeting', { name: 'Ana' })).toBe('Hola, [[Ana]]');
        expect(t('common.coming_in_phase', { phase: 3 })).toBe(
            'Llega en la Fase 3',
        );
        expect(t('search.empty', { query: 'bolsa' })).toBe(
            'No hay resultados para «bolsa».',
        );
    });

    it('sustituye varios reemplazos, también si uno es prefijo de otro', () => {
        expect(
            t('sessions.device', { browser: 'Firefox', platform: 'Linux' }),
        ).toBe('Firefox en Linux');
    });

    it('respeta :Name y :NAME como Laravel', () => {
        const key = 'test.case' as TranslationKey;
        // Clave inexistente: t() devuelve la clave, que usamos como plantilla.
        expect(t(key)).toBe('test.case');
        expect(
            t(':name / :Name / :NAME' as TranslationKey, { name: 'ana' }),
        ).toBe('ana / Ana / ANA');
    });

    it('detecta si una clave dinámica existe', () => {
        expect(hasTranslation('nav.projects')).toBe(true);
        expect(hasTranslation('nav.nope')).toBe(false);
    });

    it('no usa los prefijos de los grupos PHP de Laravel en las claves de lang/es.json', () => {
        // lang/es.json es el único JSON que lee Laravel: sus claves pisarían los grupos PHP.
        const reserved = /^(auth|pagination|passwords|validation|time)\./;

        expect(
            Object.keys(messages).filter((key) => reserved.test(key)),
        ).toEqual([]);
    });

    it('los nombres accesibles contienen el texto visible (WCAG 2.5.3)', () => {
        expect(t('search.open', { shortcut: 'Ctrl K' })).toContain(
            t('search.trigger'),
        );
        expect(t('portal.home_link')).toContain(t('portal.name'));
    });

    it('no deja textos vacíos en ningún fichero', () => {
        const empty = Object.entries(allFiles).flatMap(([file, entries]) =>
            Object.entries(entries)
                .filter(([, value]) => value.trim() === '')
                .map(([key]) => `${file}: ${key}`),
        );

        expect(empty).toEqual([]);
    });

    it('cada clave está en un solo fichero (lang/es.json o lang/ui/*.json)', () => {
        const seen = new Map<string, string>();
        const duplicated: string[] = [];

        for (const [file, entries] of Object.entries(allFiles)) {
            for (const key of Object.keys(entries)) {
                const previous = seen.get(key);

                if (previous) {
                    duplicated.push(`${key} (${previous} y ${file})`);
                }

                seen.set(key, file);
            }
        }

        expect(Object.keys(areaFiles).length).toBeGreaterThanOrEqual(8);
        expect(duplicated).toEqual([]);
    });

    it('traduce las claves de los ficheros por área', () => {
        expect(t('duration.preview', { duration: '1:30' })).toBe('= 1:30');
        expect(t('project_tabs.summary')).toBe('Resumen');
    });
});

describe('palabras clave de marca', () => {
    it('separa las palabras clave marcadas con [[…]]', () => {
        expect(splitKeywords('Carga de la [[semana que viene]]')).toEqual([
            { text: 'Carga de la ', keyword: false },
            { text: 'semana que viene', keyword: true },
        ]);
    });

    it('quita las marcas para textos planos', () => {
        expect(stripKeywords(t('placeholder.workload.title'))).toBe(
            'Carga de la semana que viene',
        );
    });
});
