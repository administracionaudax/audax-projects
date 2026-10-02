// @vitest-environment jsdom
import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createFakeEcho } from './realtime-fake-echo';
import type { FakeEcho } from './realtime-fake-echo';

const mocks = vi.hoisted(() => ({
    realtime: false,
    echo: null as FakeEcho | null,
    page: { props: { auth: { user: { id: 1, name: 'Ana' } } } },
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => mocks.page }));
vi.mock('@laravel/echo-react', () => ({ echo: () => mocks.echo }));
vi.mock('@/lib/realtime', () => ({
    realtimeEnabled: () => mocks.realtime,
    configureRealtime: () => undefined,
}));

import {
    TypingIndicator,
    typingText,
} from '@/components/realtime/typing-indicator';
import { RealtimeRoot } from '@/components/realtime/realtime-root';
import { resetPresenceForTests } from '@/hooks/use-presence';
import { resetRealtimeConnectionForTests } from '@/hooks/use-realtime-connection';
import {
    TYPING_THROTTLE_MS,
    TYPING_TIMEOUT_MS,
    useTyping,
} from '@/hooks/use-realtime-typing';

function Composer({
    id,
    participants,
}: {
    id: number;
    participants?: Array<{ id: number; name: string }>;
}) {
    const { typers, notifyTyping, stopTyping, live } = useTyping(
        id,
        participants,
    );

    return (
        <div>
            <p>{live ? 'en vivo' : 'sin tiempo real'}</p>
            <button type="button" onClick={notifyTyping}>
                teclear
            </button>
            <button type="button" onClick={stopTyping}>
                enviar
            </button>
            <TypingIndicator typers={typers} />
        </div>
    );
}

describe('useTyping', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mocks.realtime = true;
        mocks.echo = createFakeEcho();
    });

    afterEach(() => {
        resetPresenceForTests();
        resetRealtimeConnectionForTests();
        vi.useRealTimers();
    });

    it('sin tiempo real es un no-op', () => {
        mocks.realtime = false;
        render(<Composer id={5} />);

        screen.getByRole('button', { name: 'teclear' }).click();

        expect(screen.getByText('sin tiempo real')).toBeTruthy();
        expect(mocks.echo?.channels.size).toBe(0);
    });

    it('avisa de que escribes como mucho cada 3 segundos y de que paras al enviar', async () => {
        render(<Composer id={5} />);
        const channel = mocks.echo?.channel('private-conversation.5');
        const type = () =>
            screen.getByRole('button', { name: 'teclear' }).click();

        type();
        type();
        type();
        expect(channel?.whispers).toEqual([
            {
                event: 'typing',
                data: { user_id: 1, name: 'Ana', typing: true },
            },
        ]);

        await act(async () => {
            vi.advanceTimersByTime(TYPING_THROTTLE_MS);
        });
        type();
        expect(channel?.whispers).toHaveLength(2);

        screen.getByRole('button', { name: 'enviar' }).click();
        expect(channel?.whispers.at(-1)).toEqual({
            event: 'typing',
            data: { user_id: 1, name: 'Ana', typing: false },
        });

        // Sin haber escrito, «enviar» no avisa otra vez.
        screen.getByRole('button', { name: 'enviar' }).click();
        expect(channel?.whispers).toHaveLength(3);
    });

    it('muestra quién escribe y lo quita a los 6 segundos o cuando para', async () => {
        render(<Composer id={5} />);
        const channel = mocks.echo?.channel('private-conversation.5');

        await act(async () => {
            channel?.whisperFrom('typing', {
                user_id: 2,
                name: 'Luis',
                typing: true,
            });
        });
        expect(screen.getByText('Luis está escribiendo…')).toBeTruthy();

        await act(async () => {
            channel?.whisperFrom('typing', {
                user_id: 3,
                name: 'Eva',
                typing: true,
            });
        });
        expect(screen.getByText('Luis y Eva están escribiendo…')).toBeTruthy();

        await act(async () => {
            channel?.whisperFrom('typing', {
                user_id: 3,
                name: 'Eva',
                typing: false,
            });
        });
        expect(screen.getByText('Luis está escribiendo…')).toBeTruthy();

        await act(async () => {
            vi.advanceTimersByTime(TYPING_TIMEOUT_MS);
        });
        expect(screen.queryByText(/escribiendo/)).toBeNull();
    });

    it('ignora sus propios whispers y los mal formados', async () => {
        render(<Composer id={5} />);
        const channel = mocks.echo?.channel('private-conversation.5');

        await act(async () => {
            channel?.whisperFrom('typing', {
                user_id: 1,
                name: 'Ana',
                typing: true,
            });
            channel?.whisperFrom('typing', { user_id: 'x', typing: true });
            channel?.whisperFrom('typing', {});
        });

        expect(screen.queryByText(/escribiendo/)).toBeNull();
    });

    it('solo cuenta a los participantes (según el servidor) conectados (según la presencia), con su nombre (D-120)', async () => {
        render(
            <>
                <RealtimeRoot />
                <Composer
                    id={5}
                    participants={[
                        { id: 1, name: 'Ana' },
                        { id: 2, name: 'Luis Gil' },
                        { id: 4, name: 'Sara' },
                    ]}
                />
            </>,
        );
        const presence = mocks.echo?.presence('online');
        const channel = mocks.echo?.channel('private-conversation.5');

        await act(async () => {
            presence?.hereCallback?.([
                { id: 1, name: 'Ana', avatar: null },
                { id: 2, name: 'Luis Gil', avatar: null },
                { id: 3, name: 'Eva', avatar: null },
            ]);
        });

        await act(async () => {
            // Eva está conectada, pero no participa en esta conversación.
            channel?.whisperFrom('typing', {
                user_id: 3,
                name: 'Eva',
                typing: true,
            });
            // Sara participa, pero no está conectada: alguien se hace pasar por ella.
            channel?.whisperFrom('typing', {
                user_id: 4,
                name: 'Sara',
                typing: true,
            });
            // Luis, con un nombre falso en el whisper: se enseña el del servidor.
            channel?.whisperFrom('typing', {
                user_id: 2,
                name: 'Administrador',
                typing: true,
            });
        });

        expect(screen.getByText('Luis Gil está escribiendo…')).toBeTruthy();
        expect(screen.queryByText(/Eva|Sara|Administrador/)).toBeNull();
    });

    it('la región del indicador existe siempre para los lectores de pantalla', () => {
        const { container } = render(<TypingIndicator typers={[]} />);

        expect(container.querySelector('[aria-live="polite"]')).toBeTruthy();
        expect(
            typingText([
                { id: 1, name: 'Ana' },
                { id: 2, name: 'Luis' },
                { id: 3, name: 'Eva' },
            ]),
        ).toBe('Varias personas están escribiendo…');
    });
});
