// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { xsrfToken } from '@/lib/xsrf';

/** Borra las cookies que haya puesto cada prueba. */
function clearCookies(): void {
    for (const item of document.cookie.split(';')) {
        const name = item.split('=')[0]?.trim();

        if (name) {
            document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
        }
    }
}

describe('xsrfToken', () => {
    afterEach(clearCookies);

    it('sin la cookie devuelve null', () => {
        document.cookie = 'otra=valor';

        expect(xsrfToken()).toBeNull();
    });

    it('devuelve el valor decodificado de XSRF-TOKEN', () => {
        document.cookie = 'XSRF-TOKEN=eyJpdiI6IkFC%3D%3D';

        expect(xsrfToken()).toBe('eyJpdiI6IkFC==');
    });

    it('la encuentra entre otras cookies, sin confundirla con nombres parecidos', () => {
        document.cookie = 'MI-XSRF-TOKEN=falsa';
        document.cookie = 'laravel_session=abc';
        document.cookie = 'XSRF-TOKEN=buena%2F1';

        expect(xsrfToken()).toBe('buena/1');
    });

    it('una cookie mal codificada se devuelve tal cual, sin lanzar', () => {
        document.cookie = 'XSRF-TOKEN=roto%E0%A4%A';

        expect(xsrfToken()).toBe('roto%E0%A4%A');
    });
});
