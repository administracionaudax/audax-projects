// @vitest-environment jsdom
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const channel = vi.hoisted(() => ({
    handlers: new Map<string, (payload: unknown) => void>(),
    name: '',
    reconnect: null as null | (() => void),
}));

const router = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@/lib/realtime', () => ({ realtimeEnabled: () => true }));

vi.mock('@/hooks/use-realtime-connection', () => ({
    acquireChannel: (name: string) => {
        channel.name = name;

        return {
            listen: (event: string, handler: (payload: unknown) => void) =>
                channel.handlers.set(event, handler),
            stopListening: (event: string) => channel.handlers.delete(event),
        };
    },
    releaseChannel: () => undefined,
    onRealtimeReconnect: (callback: () => void) => {
        channel.reconnect = callback;

        return () => {
            channel.reconnect = null;
        };
    },
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router,
}));

import {
    useWeeklyLive,
    WEEKLY_LIVE_DEBOUNCE_MS,
} from '@/components/weeklies/use-weekly-live';

beforeEach(() => {
    vi.useFakeTimers();
    router.reload.mockReset();
    channel.handlers.clear();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('la Weekly en vivo (D-229)', () => {
    it('al llegar envíos de su semana recarga los datos, juntando los seguidos', () => {
        const { unmount } = renderHook(() =>
            useWeeklyLive({ cycleId: 7, only: ['team', 'stale'] }),
        );

        expect(channel.name).toBe('weeklies');
        const handler = channel.handlers.get('.weekly.changed')!;

        act(() => {
            handler({ cycle_id: 7, reason: 'submission' });
            handler({ cycle_id: 7, reason: 'submission' });
            handler({ cycle_id: 8, reason: 'submission' });
            vi.advanceTimersByTime(WEEKLY_LIVE_DEBOUNCE_MS);
        });

        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(router.reload).toHaveBeenCalledWith({ only: ['team', 'stale'] });

        // Otra semana no recarga; al recuperar la conexión, sí.
        act(() => {
            handler({ cycle_id: 8, reason: 'cycle' });
            vi.advanceTimersByTime(WEEKLY_LIVE_DEBOUNCE_MS);
        });
        expect(router.reload).toHaveBeenCalledTimes(1);

        act(() => {
            channel.reconnect?.();
            vi.advanceTimersByTime(WEEKLY_LIVE_DEBOUNCE_MS);
        });
        expect(router.reload).toHaveBeenCalledTimes(2);

        unmount();
        expect(channel.handlers.has('.weekly.changed')).toBe(false);
    });

    it('sin semana, cualquier cambio recarga toda la página', () => {
        renderHook(() => useWeeklyLive());

        act(() => {
            channel.handlers.get('.weekly.changed')!({
                cycle_id: 3,
                reason: 'exemption',
            });
            vi.advanceTimersByTime(WEEKLY_LIVE_DEBOUNCE_MS);
        });

        expect(router.reload).toHaveBeenCalledWith({});
    });
});
