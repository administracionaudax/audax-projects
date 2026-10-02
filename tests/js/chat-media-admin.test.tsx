// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: {} }),
    router: { post: inertia.post, reload: inertia.reload },
    Link: ({
        href,
        children,
        preserveScroll: _preserveScroll,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        preserveScroll?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

import type {
    AdminTranscriptionRow,
    AdminTranscriptionsPageProps,
} from '@/components/chat/media/types';
import AdminTranscriptions from '@/pages/admin/transcriptions';

function row(overrides: Partial<AdminTranscriptionRow>): AdminTranscriptionRow {
    return {
        id: 1,
        message_id: 100,
        status: 'done',
        attempts: 1,
        last_error: null,
        audio_duration_ms: 65_000,
        processing_ms: 212_000,
        engine: 'whisper.cpp',
        model: 'small',
        created_at: '2026-09-27T08:00:00Z',
        queued_at: '2026-09-27T08:00:00Z',
        transcribed_at: '2026-09-27T08:04:00Z',
        admin_notified: false,
        conversation: { type: 'project', label: 'ARR-WEB · Web corporativa' },
        author: 'Ana',
        message_deleted: false,
        message_hidden: false,
        over_limit: false,
        can_retry: false,
        url: '/chat/3?mensaje=100',
        ...overrides,
    };
}

function props(
    overrides: Partial<AdminTranscriptionsPageProps> = {},
): AdminTranscriptionsPageProps {
    return {
        filters: { status: null },
        counts: { pending: 1, processing: 0, failed: 2, done: 5 },
        maxAudioSeconds: 300,
        transcriptions: [
            row({
                id: 3,
                message_id: 103,
                status: 'failed',
                attempts: 9,
                last_error: 'El transcriptor no responde',
                audio_duration_ms: null,
                processing_ms: null,
                can_retry: true,
                admin_notified: true,
                url: '/chat/3?mensaje=103',
            }),
            row({
                id: 2,
                message_id: 102,
                status: 'pending',
                attempts: 0,
                can_retry: true,
                conversation: { type: 'direct', label: null },
                author: null,
                url: null,
                processing_ms: null,
            }),
            row({ id: 1, over_limit: true, audio_duration_ms: 400_000 }),
        ],
        pagination: {
            current_page: 1,
            last_page: 1,
            total: 3,
            prev_url: null,
            next_url: null,
        },
        ...overrides,
    };
}

describe('AdminTranscriptions', () => {
    beforeEach(() => {
        inertia.post.mockReset();
    });

    it('pinta cada transcripción con su estado (icono y texto), intentos, error, duración y proceso', () => {
        render(<AdminTranscriptions {...props()} />);

        const rows = screen.getAllByRole('row').slice(1);
        expect(rows).toHaveLength(3);

        expect(rows[0].textContent).toContain('Fallida');
        expect(rows[0].textContent).toContain('Aviso enviado');
        expect(rows[0].textContent).toContain('El transcriptor no responde');
        expect(within(rows[0]).getByText('9')).toBeTruthy();
        expect(
            within(rows[0])
                .getByRole('link', { name: 'ARR-WEB · Web corporativa' })
                .getAttribute('href'),
        ).toBe('/chat/3?mensaje=103');

        // Directa: ni quién ni dónde, y sin enlace.
        expect(rows[1].textContent).toContain('Conversación directa');
        expect(rows[1].textContent).not.toContain('Ana');
        expect(within(rows[1]).queryByRole('link')).toBeNull();
        expect(rows[1].textContent).toContain('Pendiente');

        expect(rows[2].textContent).toContain('Hecha');
        expect(rows[2].textContent).toContain('Supera el máximo');
        expect(rows[2].textContent).toContain('6:40');
        expect(rows[2].textContent).toContain('3 min 32 s');

        for (const badge of screen.getAllByText(
            /^(Fallida|Pendiente|Hecha)$/,
        )) {
            expect(badge.parentElement?.querySelector('svg')).not.toBeNull();
        }
    });

    it('solo se relanzan las que lo admiten, y relanzar llama a su ruta', async () => {
        const user = userEvent.setup();
        render(<AdminTranscriptions {...props()} />);

        const buttons = screen.getAllByRole('button', {
            name: /Relanzar la transcripción del mensaje/,
        });
        expect(
            buttons.map((button) => button.getAttribute('aria-label')),
        ).toEqual([
            'Relanzar la transcripción del mensaje 103',
            'Relanzar la transcripción del mensaje 102',
        ]);

        await user.click(buttons[0]);

        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/transcripciones/3/relanzar',
            {},
            expect.objectContaining({
                only: ['transcriptions', 'counts', 'pagination'],
            }),
        );
    });

    it('relanzar todas las fallidas pide confirmación', async () => {
        const user = userEvent.setup();
        render(<AdminTranscriptions {...props()} />);

        await user.click(
            screen.getByRole('button', { name: 'Relanzar las fallidas (2)' }),
        );
        const dialog = screen.getByRole('dialog', {
            name: '¿Relanzar todas las transcripciones fallidas?',
        });
        await user.click(
            within(dialog).getByRole('button', { name: 'Relanzar' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/transcripciones/relanzar-fallidas',
            {},
            expect.any(Object),
        );
    });

    it('sin fallidas, el botón en bloque está desactivado', () => {
        render(
            <AdminTranscriptions
                {...props({
                    counts: { pending: 0, processing: 0, failed: 0, done: 5 },
                })}
            />,
        );

        expect(
            (
                screen.getByRole('button', {
                    name: 'Relanzar las fallidas (0)',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('los filtros por estado llevan su recuento y marcan el activo', () => {
        render(
            <AdminTranscriptions
                {...props({ filters: { status: 'fallidas' } })}
            />,
        );

        const nav = screen.getByRole('navigation', {
            name: 'Filtrar por estado',
        });
        const links = within(nav).getAllByRole('link');
        expect(links.map((link) => link.textContent)).toEqual([
            'Todas8',
            'Pendientes1',
            'En curso0',
            'Fallidas2',
            'Hechas5',
        ]);
        expect(
            within(nav)
                .getByRole('link', { current: 'page' })
                .getAttribute('href'),
        ).toBe('/admin/transcripciones?estado=fallidas');
    });

    it('sin transcripciones muestra un estado vacío', () => {
        render(
            <AdminTranscriptions
                {...props({
                    transcriptions: [],
                    filters: { status: 'fallidas' },
                })}
            />,
        );

        expect(
            screen.getByText('No hay transcripciones en «fallidas»'),
        ).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();
    });
});
