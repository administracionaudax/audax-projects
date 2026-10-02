// @vitest-environment jsdom
import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createFakeEcho, jsonResponse, urlOf } from './realtime-fake-echo';
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

import { ReadBy, readByText } from '@/components/realtime/read-by';
import { resetRealtimeConnectionForTests } from '@/hooks/use-realtime-connection';
import { READS_POLL_MS, useReadReceipts } from '@/hooks/use-realtime-reads';
import type { ReadParticipant } from '@/hooks/use-realtime-reads';

const participant = (
    id: number,
    name: string,
    lastRead: number | null,
): ReadParticipant => ({
    id,
    name,
    avatar: null,
    is_active: true,
    last_read_message_id: lastRead,
});

function Receipts({ messageId }: { messageId: number }) {
    const { readersOf, recipientsOf, ready } = useReadReceipts(5);

    return ready ? (
        <ReadBy
            readers={readersOf(messageId, 1)}
            recipients={recipientsOf(1)}
        />
    ) : (
        <p>cargando</p>
    );
}

describe('useReadReceipts', () => {
    let fetchMock: ReturnType<typeof vi.spyOn>;
    let participants: ReadParticipant[];

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mocks.realtime = false;
        mocks.echo = createFakeEcho();
        participants = [
            participant(1, 'Ana', 10),
            participant(2, 'Luis', 10),
            participant(3, 'Eva', 8),
        ];
        fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockImplementation(async () => jsonResponse({ participants }));
    });

    afterEach(() => {
        resetRealtimeConnectionForTests();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('pide hasta dónde ha leído cada uno y dice quién ha leído el mensaje', async () => {
        render(<Receipts messageId={10} />);

        expect(await screen.findByText('Leído por Luis')).toBeTruthy();
        expect(urlOf(fetchMock.mock.calls[0][0])).toBe(
            '/tiempo-real/conversaciones/5/leidos',
        );
    });

    it('en vivo, una lectura nueva se refleja sin consultar', async () => {
        mocks.realtime = true;
        render(<Receipts messageId={10} />);
        await screen.findByText('Leído por Luis');

        await act(async () => {
            mocks.echo
                ?.channel('private-conversation.5')
                ?.emit('.conversation.read', {
                    conversation_id: 5,
                    user_id: 3,
                    last_read_message_id: 12,
                });
        });

        expect(screen.getByText('Leído por todos')).toBeTruthy();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('si lee alguien que aún no estaba en la lista, la vuelve a pedir', async () => {
        mocks.realtime = true;
        render(<Receipts messageId={10} />);
        await screen.findByText('Leído por Luis');

        participants = [...participants, participant(4, 'Marta', 10)];
        await act(async () => {
            mocks.echo
                ?.channel('private-conversation.5')
                ?.emit('.conversation.read', {
                    conversation_id: 5,
                    user_id: 4,
                    last_read_message_id: 10,
                });
        });

        expect(await screen.findByText('Leído por Luis y Marta')).toBeTruthy();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('sin tiempo real consulta cada 30 segundos', async () => {
        render(<Receipts messageId={10} />);
        await screen.findByText('Leído por Luis');

        participants = participants.map((item) => ({
            ...item,
            last_read_message_id: 10,
        }));
        await act(async () => {
            vi.advanceTimersByTime(READS_POLL_MS);
        });

        expect(await screen.findByText('Leído por todos')).toBeTruthy();
    });
});

describe('ReadBy', () => {
    const readers = [
        participant(2, 'Luis', 10),
        participant(3, 'Eva', 10),
        participant(4, 'Marta', 10),
        participant(5, 'Iván', 10),
    ];

    it('enviado, leído por algunos, por todos y en una directa', () => {
        expect(readByText([], 3)).toBe('Enviado');
        expect(readByText(readers.slice(0, 1), 3)).toBe('Leído por Luis');
        expect(readByText(readers.slice(0, 2), 3)).toBe('Leído por Luis y Eva');
        expect(readByText(readers, 6)).toBe('Leído por Luis, Eva y 2 más');
        expect(readByText(readers.slice(0, 3), 3)).toBe('Leído por todos');
        expect(readByText(readers.slice(0, 1), 1, true)).toBe('Leído');
    });

    it('siempre con icono y texto, y la lista completa para el lector de pantalla', () => {
        render(<ReadBy readers={readers} recipients={6} />);

        expect(screen.getByText('Leído por Luis, Eva y 2 más')).toBeTruthy();
        expect(
            screen.getByText('Leído por Luis, Eva, Marta, Iván', {
                selector: '.sr-only',
            }),
        ).toBeTruthy();
    });

    it('no pinta nada si no hay a quién leerlo', () => {
        const { container } = render(<ReadBy readers={[]} recipients={0} />);

        expect(container.innerHTML).toBe('');
    });
});
