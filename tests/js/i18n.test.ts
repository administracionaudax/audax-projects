import { describe, expect, it } from 'vitest';
import { splitKeywords, stripKeywords } from '@/components/keyword-text';
import { hasTranslation, t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import messages from '../../lang/es.json';

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

    it('no usa los prefijos de los grupos PHP de Laravel en las claves del frontend', () => {
        const reserved = /^(auth|pagination|passwords|validation)\./;

        expect(
            Object.keys(messages).filter((key) => reserved.test(key)),
        ).toEqual([]);
    });

    it('no deja textos vacíos', () => {
        const empty = Object.entries(messages)
            .filter(([, value]) => value.trim() === '')
            .map(([key]) => key);

        expect(empty).toEqual([]);
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
