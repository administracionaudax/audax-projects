// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    RetentionFields,
    retentionValues,
} from '@/components/privacy/retention-fields';
import AdminPrivacy from '@/pages/admin/privacy';
import type { AdminPrivacyProps } from '@/types/privacy';

/**
 * /admin/privacidad (D-075): el texto con su vista previa saneada y su versión, y el formulario de
 * retención con sus mínimos y máximos y «Sin límite» solo donde se admite.
 */

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
}));

afterEach(() => {
    vi.restoreAllMocks();
});

const props = (overrides: Partial<AdminPrivacyProps> = {}): AdminPrivacyProps => ({
    notice: {
        markdown: '## Tus datos\n\nTexto **vigente**.',
        version: 2,
        is_draft: false,
        max_length: 20000,
    },
    readers: { read: 3, total: 10 },
    settings: {
        retention_login_events_months: 12,
        retention_read_notifications_months: 6,
        retention_activity_log_months: 60,
        retention_chat_messages_months: null,
        personal_data_export_days: 7,
        disk_warning_percent: 85,
        attachments_warning_gb: null,
    },
    retention: [
        { type: 'login_events', key: 'retention_login_events_months', min: 1, max: 120, unlimited_allowed: false },
        { type: 'read_notifications', key: 'retention_read_notifications_months', min: 1, max: 120, unlimited_allowed: false },
        { type: 'activity_log', key: 'retention_activity_log_months', min: 12, max: 120, unlimited_allowed: true },
        { type: 'chat_messages', key: 'retention_chat_messages_months', min: 1, max: 120, unlimited_allowed: true },
    ],
    limits: {
        export_days: { min: 1, max: 30 },
        disk_percent: { min: 50, max: 99 },
        attachments_gb: { min: 1, max: 100000 },
    },
    ...overrides,
});

describe('texto informativo', () => {
    it('muestra la versión, quién lo ha leído y una vista previa saneada que sigue al texto', async () => {
        render(<AdminPrivacy {...props()} />);

        expect(screen.getByText('Versión 2')).toBeTruthy();
        expect(screen.getByText('Personas que han leído la versión vigente: 3 de 10')).toBeTruthy();
        expect(screen.queryByText('Borrador pendiente de asesor')).toBeNull();

        const preview = screen.getByRole('region', { name: 'Vista previa' });
        expect(within(preview).getByRole('heading', { level: 3, name: 'Tus datos' })).toBeTruthy();

        const textarea = screen.getByLabelText('Texto (markdown)');
        await userEvent.clear(textarea);
        await userEvent.type(textarea, 'Nuevo <img src=x onerror=alert(1)> [enlace](javascript:alert(1))');

        expect(preview.querySelector('img')).toBeNull();
        expect(preview.querySelector('a')).toBeNull();
        expect(preview.textContent).toContain('<img src=x onerror=alert(1)>');
        // Cambiar el texto avisa de la versión nueva.
        expect(screen.getByRole('status').textContent).toContain('pasará a la versión 3');
    });

    it('marca el borrador pendiente de asesor', () => {
        render(<AdminPrivacy {...props({ notice: { markdown: 'Borrador', version: 1, is_draft: true, max_length: 20000 } })} />);

        expect(screen.getByText('Borrador pendiente de asesor')).toBeTruthy();
    });
});

describe('formulario de retención', () => {
    it('un campo por tipo con sus mínimos y máximos; «Sin límite» solo en auditoría y chat', () => {
        render(<AdminPrivacy {...props()} />);

        const login = screen.getByRole('spinbutton', { name: 'Registros de acceso' }) as HTMLInputElement;
        const audit = screen.getByRole('spinbutton', { name: 'Registro de cambios (auditoría)' }) as HTMLInputElement;
        const chat = screen.getByRole('spinbutton', { name: 'Mensajes del chat' }) as HTMLInputElement;

        expect(login.value).toBe('12');
        expect(login.min).toBe('1');
        expect(login.max).toBe('120');
        expect(audit.min).toBe('12');
        expect(audit.value).toBe('60');
        // El chat está sin límite: el número desactivado y la casilla marcada.
        expect(chat.disabled).toBe(true);

        const unlimited = screen.getAllByRole('checkbox', { name: 'Sin límite' });
        expect(unlimited).toHaveLength(2);
        expect(within(screen.getByRole('group', { name: 'Registros de acceso' })).queryByRole('checkbox')).toBeNull();
        expect(within(screen.getByRole('group', { name: 'Mensajes del chat' })).getByRole('checkbox').getAttribute('aria-checked')).toBe('true');
        expect(screen.getByText('Entre 12 y 120 meses.')).toBeTruthy();
    });

    it('envía los meses como números y null con «Sin límite»', async () => {
        const put = vi.spyOn(coreRouter, 'put').mockImplementation(() => {});
        render(<AdminPrivacy {...props()} />);

        const login = screen.getByRole('spinbutton', { name: 'Registros de acceso' });
        await userEvent.clear(login);
        await userEvent.type(login, '24');

        // La auditoría pasa a sin límite y el chat vuelve a tener plazo.
        await userEvent.click(within(screen.getByRole('group', { name: 'Registro de cambios (auditoría)' })).getByRole('checkbox'));
        expect((screen.getByRole('spinbutton', { name: 'Registro de cambios (auditoría)' }) as HTMLInputElement).disabled).toBe(true);
        await userEvent.click(within(screen.getByRole('group', { name: 'Mensajes del chat' })).getByRole('checkbox'));
        await userEvent.type(screen.getByRole('spinbutton', { name: 'Mensajes del chat' }), '36');

        await userEvent.type(screen.getByRole('spinbutton', { name: /Aviso de adjuntos/ }), '200');
        await userEvent.click(screen.getByRole('button', { name: 'Guardar' }));

        expect(put).toHaveBeenCalledTimes(1);
        const [url, data] = put.mock.calls[0] as unknown as [string, Record<string, unknown>];
        expect(url).toBe('/admin/privacidad');
        expect(data).toEqual({
            notice: '## Tus datos\n\nTexto **vigente**.',
            retention_login_events_months: 24,
            retention_read_notifications_months: 6,
            retention_activity_log_months: null,
            retention_chat_messages_months: 36,
            personal_data_export_days: 7,
            disk_warning_percent: 85,
            attachments_warning_gb: 200,
        });
    });

    it('enseña los errores del servidor junto a cada campo', () => {
        const fields = props().retention;
        render(
            <RetentionFields
                fields={fields}
                values={retentionValues(fields, props().settings)}
                errors={{
                    retention_activity_log_months:
                        'Indica un número de meses entre 12 y 120.',
                }}
                onChange={() => {}}
            />,
        );

        const audit = screen.getByRole('spinbutton', { name: 'Registro de cambios (auditoría)' });
        const error = screen.getByText('Indica un número de meses entre 12 y 120.');

        expect(audit.getAttribute('aria-invalid')).toBe('true');
        expect(audit.getAttribute('aria-describedby')?.split(' ')).toContain(error.id);
        expect(screen.getByRole('spinbutton', { name: 'Registros de acceso' }).getAttribute('aria-invalid')).toBeNull();
    });
});
