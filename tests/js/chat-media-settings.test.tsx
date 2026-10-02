// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    url: '/admin',
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
    };
});

import AdminSettings from '@/pages/admin/settings';
import AdminIndex from '@/pages/admin/index';
import type { AdminSettingsProps } from '@/types';

function settingsProps(maxAudioSeconds: number): AdminSettingsProps {
    return {
        settings: {
            company_name: 'Audax Studio',
            require_2fa: false,
            timer_rounding_minutes: 1,
            timer_warning_hours: 10,
            hour_bank_alert_thresholds: [75, 90, 100],
            allow_hour_bank_overage: true,
            require_timesheet_approval: true,
            allow_future_time_entries: false,
            time_entry_description_required: false,
            max_attachment_mb: 50,
            default_work_minutes: [480, 480, 480, 480, 480, 0, 0],
            max_audio_seconds: maxAudioSeconds,
        },
        roundings: [1, 5, 10, 15, 30],
        serverUploadLimitMb: null,
    };
}

describe('ajuste de la duración máxima de los audios', () => {
    it('se elige entre 30 segundos y 10 minutos, con 5 minutos por defecto', () => {
        render(<AdminSettings {...settingsProps(300)} />);

        const select = screen.getByRole('combobox', {
            name: 'Duración máxima de los audios',
        }) as HTMLSelectElement;
        expect(select.value).toBe('300');
        expect(
            Array.from(select.options).map((option) => option.textContent),
        ).toEqual([
            '30 segundos',
            '1 minuto',
            '2 minutos',
            '3 minutos',
            '5 minutos',
            '10 minutos',
        ]);
        expect(select.getAttribute('aria-describedby')).toContain('-help');
    });

    it('un valor guardado que no está en la lista también se ofrece', () => {
        render(<AdminSettings {...settingsProps(150)} />);

        const select = screen.getByRole('combobox', {
            name: 'Duración máxima de los audios',
        }) as HTMLSelectElement;
        expect(select.value).toBe('150');
        expect(
            Array.from(select.options).map((option) => option.value),
        ).toEqual(['30', '60', '120', '150', '180', '300', '600']);
        expect(select.selectedOptions[0].textContent).toBe('2:30 (m:ss)');
    });
});

describe('administración', () => {
    it('el admin tiene el acceso a las transcripciones', () => {
        page.props = { auth: { user: null, can: { manageSettings: true } } };
        render(<AdminIndex />);

        const card = screen
            .getByRole('heading', { name: 'Transcripciones' })
            .closest('[data-test="admin-area-transcriptions"]');
        expect(card).not.toBeNull();
        expect(
            screen
                .getByRole('link', { name: 'Ver las transcripciones' })
                .getAttribute('href'),
        ).toBe('/admin/transcripciones');
    });
});
