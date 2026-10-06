// @vitest-environment jsdom
import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useLastDefined } from '@/hooks/use-last-defined';

/**
 * D-310 (H-A2, H-A6): tras guardar, una prop diferida vuelve a undefined hasta que llega otra vez;
 * los diálogos y secciones que dependían de ella usan lo último recibido y no se desmontan.
 */
describe('useLastDefined', () => {
    it('conserva el último valor mientras la prop vuelve a pedirse', () => {
        const { result, rerender } = renderHook(
            ({ value }: { value: string[] | undefined }) =>
                useLastDefined(value),
            { initialProps: { value: undefined as string[] | undefined } },
        );

        expect(result.current).toBeUndefined();
        rerender({ value: ['Ana'] });
        expect(result.current).toEqual(['Ana']);
        rerender({ value: undefined });
        expect(result.current).toEqual(['Ana']);
        rerender({ value: ['Ana', 'Raúl'] });
        expect(result.current).toEqual(['Ana', 'Raúl']);
    });
});
