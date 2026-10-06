// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const push = vi.hoisted(() => ({
    supported: true,
    status: 'disabled' as string,
    enable: vi.fn(),
}));

vi.mock('@/components/realtime/push', () => ({
    pushSupported: () => push.supported,
    loadPushConfig: async () => ({
        enabled: true,
        public_key: 'k',
        subscriptions: [],
    }),
    pushStatus: async () => push.status,
    enablePush: push.enable,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
}));

import {
    PUSH_PROMPT_DISMISSED_KEY,
    PushPrompt,
} from '@/components/weeklies/push-prompt';

function setPermission(value: NotificationPermission) {
    vi.stubGlobal('Notification', { permission: value });
}

beforeEach(() => {
    push.supported = true;
    push.status = 'disabled';
    push.enable.mockReset();
    window.localStorage.clear();
    setPermission('default');
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('pedir los avisos del navegador con amabilidad (D-230)', () => {
    it('ofrece activarlos y solo pide el permiso al pulsar', async () => {
        push.enable.mockResolvedValue('enabled');
        render(<PushPrompt />);

        const button = await screen.findByRole('button', {
            name: 'Activar avisos',
        });
        expect(push.enable).not.toHaveBeenCalled();
        await userEvent.click(button);

        expect(push.enable).toHaveBeenCalledWith(
            expect.objectContaining({ public_key: 'k' }),
            7,
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('region', {
                    name: '¿Te avisamos en el navegador?',
                }),
            ).toBeNull(),
        );
    });

    it('«Ahora no» no lo vuelve a ofrecer en este navegador', async () => {
        const { unmount } = render(<PushPrompt />);

        await userEvent.click(
            await screen.findByRole('button', { name: 'Ahora no' }),
        );
        expect(window.localStorage.getItem(PUSH_PROMPT_DISMISSED_KEY)).toBe(
            '1',
        );
        unmount();

        render(<PushPrompt />);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(
            screen.queryByRole('button', { name: 'Activar avisos' }),
        ).toBeNull();
    });

    it('no sale si ya hay permiso (dado o negado) o ya están activados', async () => {
        setPermission('denied');
        const { unmount } = render(<PushPrompt />);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(
            screen.queryByRole('button', { name: 'Activar avisos' }),
        ).toBeNull();
        unmount();

        setPermission('default');
        push.status = 'enabled';
        render(<PushPrompt />);
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(
            screen.queryByRole('button', { name: 'Activar avisos' }),
        ).toBeNull();
    });
});
