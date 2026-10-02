// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PrivacyNoticeBanner } from '@/components/privacy/privacy-notice-banner';
import MyData from '@/pages/settings/my-data';
import PrivacyShow from '@/pages/privacy/show';
import type { PersonalDataExportRow, PrivacyShowProps } from '@/types/privacy';

/**
 * Aviso de lectura del texto de privacidad (D-075), la página /privacidad con su «He leído la
 * información» y /ajustes/mis-datos con sus exportaciones.
 */

const page = vi.hoisted(() => ({
    url: '/',
    props: {} as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
        router: {
            post: inertia.post,
            reload: inertia.reload,
            on: () => () => {},
        },
        Link: ({
            href,
            children,
            prefetch: _prefetch,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            prefetch?: boolean;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

beforeEach(() => {
    page.url = '/';
    page.props = {};
    inertia.post.mockReset();
    inertia.reload.mockReset();
});

describe('aviso de privacidad', () => {
    it('aparece mientras falta leer la versión vigente y lleva a /privacidad', () => {
        page.props = { privacy: { needs_acknowledgement: true } };
        render(<PrivacyNoticeBanner />);

        const banner = screen.getByRole('region', {
            name: 'Información sobre tus datos',
        });
        const link = within(banner).getByRole('link', {
            name: 'Leer la información',
        });

        expect(link.getAttribute('href')).toBe('/privacidad');
        expect(banner.textContent).toContain('Léelo y confirma');
    });

    it('no aparece si ya la ha leído, si no hay prop (clientes) ni en /privacidad', () => {
        page.props = { privacy: { needs_acknowledgement: false } };
        const { container, rerender } = render(<PrivacyNoticeBanner />);
        expect(container.innerHTML).toBe('');

        page.props = {};
        rerender(<PrivacyNoticeBanner />);
        expect(container.innerHTML).toBe('');

        page.props = { privacy: { needs_acknowledgement: true } };
        page.url = '/privacidad?x=1';
        rerender(<PrivacyNoticeBanner />);
        expect(container.innerHTML).toBe('');
    });
});

const showProps = (
    overrides: Partial<PrivacyShowProps> = {},
): PrivacyShowProps => ({
    notice: {
        markdown:
            '> **Borrador pendiente de revisión por el asesor.**\n\n## Tus datos\n\nTexto.',
        version: 3,
        is_draft: true,
    },
    acknowledgement: { needed: true, version: 2, at: '2026-09-01T08:00:00Z' },
    retention: [
        { type: 'login_events', months: 12 },
        { type: 'read_notifications', months: 6 },
        { type: 'activity_log', months: 60 },
        { type: 'chat_messages', months: null },
    ],
    exportDays: 7,
    ...overrides,
});

describe('/privacidad', () => {
    it('pinta el texto saneado, la versión, el borrador y los plazos vigentes', () => {
        const { container } = render(<PrivacyShow {...showProps()} />);

        expect(container.querySelectorAll('h1')).toHaveLength(1);
        expect(
            screen.getByRole('heading', { level: 2, name: 'Tus datos' }),
        ).toBeTruthy();
        expect(
            screen.getByText('Versión 3 del texto informativo'),
        ).toBeTruthy();
        expect(screen.getByText('Borrador pendiente de asesor')).toBeTruthy();
        expect(screen.getByText('Pendiente de leer')).toBeTruthy();

        const terms = [...container.querySelectorAll('dt')].map(
            (dt) => dt.textContent,
        );
        const values = [...container.querySelectorAll('dd')].map(
            (dd) => dd.textContent,
        );
        expect(terms).toEqual([
            'Registros de acceso',
            'Notificaciones leídas',
            'Registro de cambios (auditoría)',
            'Mensajes del chat',
        ]);
        expect(values).toEqual(['1 año', '6 meses', '5 años', 'Sin límite']);
        expect(
            screen
                .getByRole('link', { name: 'Ir a Mis datos' })
                .getAttribute('href'),
        ).toBe('/ajustes/mis-datos');
    });

    it('«He leído la información» registra la lectura con un POST', async () => {
        render(<PrivacyShow {...showProps()} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'He leído la información' }),
        );

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post.mock.calls[0][0]).toBe('/privacidad/lectura');
    });

    it('ya leída, dice cuándo y no muestra el botón', () => {
        render(
            <PrivacyShow
                {...showProps({
                    notice: { markdown: 'Texto', version: 3, is_draft: false },
                    acknowledgement: {
                        needed: false,
                        version: 3,
                        at: '2026-10-05T08:30:00Z',
                    },
                })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'He leído la información' }),
        ).toBeNull();
        expect(
            screen.getByText('Leíste la versión 3 el 05/10/2026 10:30.'),
        ).toBeTruthy();
        expect(screen.getByText('Leída')).toBeTruthy();
        expect(screen.queryByText('Borrador pendiente de asesor')).toBeNull();
    });
});

const exportRow = (
    overrides: Partial<PersonalDataExportRow> = {},
): PersonalDataExportRow => ({
    id: 1,
    status: 'ready',
    status_label: 'Lista para descargar',
    requested_by_subject: true,
    requester: 'Elena Empleada',
    created_at: '2026-10-05T08:00:00Z',
    finished_at: '2026-10-05T08:01:00Z',
    expires_at: '2026-10-12T08:01:00Z',
    downloaded_at: null,
    size_bytes: 1536,
    download_url: '/datos-personales/1/descargar?expires=1&signature=abc',
    ...overrides,
});

describe('/ajustes/mis-datos', () => {
    it('lista las exportaciones con su estado (icono y texto) y la descarga si está lista', () => {
        render(
            <MyData
                exports={[
                    exportRow(),
                    exportRow({
                        id: 2,
                        status: 'failed',
                        status_label: 'Ha fallado',
                        download_url: null,
                        requested_by_subject: false,
                        requester: 'Ana Admin',
                    }),
                ]}
                canRequest
                exportDays={7}
            />,
        );

        const items = screen
            .getAllByRole('listitem')
            .filter(
                (item) =>
                    item.getAttribute('data-test') === 'personal-data-export',
            );
        expect(items).toHaveLength(2);

        const download = within(items[0]).getByRole('link', {
            name: 'Descargar (1,5 KB)',
        });
        expect(download.getAttribute('href')).toBe(
            '/datos-personales/1/descargar?expires=1&signature=abc',
        );
        expect(items[0].textContent).toContain('Lista para descargar');
        expect(items[0].textContent).toContain(
            'Se puede descargar hasta el 12/10/2026 10:01',
        );
        expect(items[0].querySelector('svg')).not.toBeNull();

        expect(items[1].textContent).toContain('Ha fallado');
        expect(items[1].textContent).toContain('Pedida por Ana Admin');
        expect(within(items[1]).queryByRole('link')).toBeNull();
        expect(screen.getByRole('status').textContent).toBe(
            'Estado de tu última exportación: Lista para descargar',
        );
    });

    it('pide los datos con un POST; con una en curso, el botón se desactiva y se refresca sola', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const { rerender } = render(
            <MyData exports={[]} canRequest exportDays={7} />,
        );

        expect(
            screen.getByText('Aún no has pedido ninguna exportación'),
        ).toBeTruthy();

        await userEvent.click(
            screen.getByRole('button', { name: 'Preparar mis datos' }),
        );
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post.mock.calls[0][0]).toBe('/ajustes/mis-datos');

        rerender(
            <MyData
                exports={[
                    exportRow({
                        status: 'pending',
                        status_label: 'En cola',
                        download_url: null,
                    }),
                ]}
                canRequest={false}
                exportDays={7}
            />,
        );

        const button = screen.getByRole('button', {
            name: 'Preparar mis datos',
        }) as HTMLButtonElement;
        expect(button.disabled).toBe(true);
        expect(button.getAttribute('aria-describedby')).toBeTruthy();
        expect(
            screen.getByText(
                'Ya hay una exportación en curso: podrás pedir otra cuando termine.',
            ),
        ).toBeTruthy();

        vi.advanceTimersByTime(5000);
        expect(inertia.reload).toHaveBeenCalledWith({
            only: ['exports', 'canRequest'],
        });

        vi.useRealTimers();
    });
});
