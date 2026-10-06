// @vitest-environment jsdom
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useWeeklyAutosave } from '@/components/weeklies/use-weekly-autosave';
import type { WeeklyDraftInput } from '@/types/weeklies';

/** D-310 (H-D5): si falla el envío de «Mi weekly», lo escrito vuelve a guardarse. */
describe('autoguardado de Mi weekly al enviar', () => {
    it('al deshacer la cancelación guarda lo que el envío no llegó a guardar', async () => {
        const save = vi
            .fn()
            .mockResolvedValue({ updated_at: '2026-10-06T10:00:00Z' });
        const first = { entries: [] } as unknown as WeeklyDraftInput;
        const edited = {
            entries: [{ client_id: 1, body: 'Hecho' }],
        } as unknown as WeeklyDraftInput;
        const { result, rerender } = renderHook(
            ({ draft }) =>
                useWeeklyAutosave({
                    cycleId: 4,
                    draft,
                    enabled: true,
                    delay: 10_000,
                    save: save as never,
                }),
            { initialProps: { draft: first } },
        );

        rerender({ draft: edited });
        // «Enviar» para el autoguardado…
        const resume = result.current.cancel();
        // …y el envío falla: lo escrito tiene que guardarse.
        await act(async () => {
            resume();
        });

        expect(save).toHaveBeenCalledTimes(1);
        expect(save.mock.calls[0][1]).toEqual(edited);
    });
});
