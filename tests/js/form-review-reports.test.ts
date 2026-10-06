// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { isReportPageVisit } from '@/components/reports/r1-report-state';
import {
    loadReportOptions,
    rangePatch,
    resetReportOptionsCache,
} from '@/components/reports/report-filter-bar';

/** Revisión de formularios (D-310): filtros de los informes. */
describe('filtros de los informes', () => {
    afterEach(() => {
        resetReportOptionsCache();
        vi.unstubAllGlobals();
    });

    it('un «Desde» posterior al «Hasta» arrastra el «Hasta» (y al revés) en vez de perder el rango', () => {
        expect(
            rangePatch('desde', '2026-10-01', '2026-09-01', '2026-09-15'),
        ).toEqual({
            desde: '2026-10-01',
            hasta: '2026-10-01',
        });
        expect(
            rangePatch('desde', '2026-09-05', '2026-09-01', '2026-09-15'),
        ).toEqual({
            desde: '2026-09-05',
        });
        expect(
            rangePatch('hasta', '2026-08-20', '2026-09-01', '2026-09-15'),
        ).toEqual({
            desde: '2026-08-20',
            hasta: '2026-08-20',
        });
    });

    it('un fallo de red al pedir las opciones no se queda en la caché', async () => {
        const fetch = vi
            .fn()
            .mockRejectedValueOnce(new TypeError('Failed to fetch'))
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ people: [] }), { status: 200 }),
            );
        vi.stubGlobal('fetch', fetch);

        await expect(loadReportOptions()).rejects.toThrow();
        await expect(loadReportOptions()).resolves.toEqual({ people: [] });
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('solo las visitas que vuelven a pedir el informe lo atenúan', () => {
        const url = new URL('/informes/direccion', window.location.origin);
        const base = { method: 'get', url, only: [] as string[] };

        expect(isReportPageVisit(base, '/informes/direccion')).toBe(true);
        expect(
            isReportPageVisit(
                { ...base, prefetch: true },
                '/informes/direccion',
            ),
        ).toBe(false);
        expect(
            isReportPageVisit(
                { ...base, only: ['future_load'] },
                '/informes/direccion',
            ),
        ).toBe(false);
        expect(
            isReportPageVisit(
                { ...base, method: 'post' },
                '/informes/direccion',
            ),
        ).toBe(false);
        expect(isReportPageVisit(base, '/informes/detalle')).toBe(false);
    });
});
