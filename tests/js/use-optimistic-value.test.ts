// @vitest-environment jsdom
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useOptimisticValue } from '@/hooks/use-optimistic-value';

/** D-310 (H-C8): el filtro elegido se ve enseguida y vuelve a seguir a la URL al llegar la respuesta. */
describe('useOptimisticValue', () => {
    it('cambia al elegir y se resincroniza con la prop', () => {
        const { result, rerender } = renderHook(
            ({ prop }: { prop: string }) => useOptimisticValue(prop),
            { initialProps: { prop: '' } },
        );

        act(() => {
            result.current[1]('2026-09');
        });
        expect(result.current[0]).toBe('2026-09');

        rerender({ prop: '2026-08' });
        expect(result.current[0]).toBe('2026-08');
    });
});
