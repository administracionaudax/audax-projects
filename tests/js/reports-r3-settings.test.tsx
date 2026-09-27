// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { AdminSettingsProps } from '@/types';

const page = vi.hoisted(() => ({
    url: '/admin/ajustes',
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
        router: { get: vi.fn(), on: () => () => {} },
        Link: ({
            href,
            children,
        }: {
            href: string | { url: string };
            children?: ReactNode;
        }) => (
            <a href={typeof href === 'string' ? href : href.url}>{children}</a>
        ),
    };
});

import AdminSettings from '@/pages/admin/settings';

const props: AdminSettingsProps = {
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
        weekly_digest_enabled: true,
        occupancy_low_threshold: 70,
        occupancy_high_threshold: 110,
    },
    roundings: [1, 5, 10, 15, 30],
    serverUploadLimitMb: null,
};

describe('ajustes del resumen semanal (D-047)', () => {
    it('muestra el interruptor y los umbrales con su explicación', () => {
        render(<AdminSettings {...props} />);

        const toggle = screen.getByRole('switch', {
            name: 'Enviar el resumen semanal de productividad',
        });
        expect(toggle.getAttribute('aria-checked')).toBe('true');
        expect(toggle.getAttribute('aria-describedby')).toBeTruthy();

        const low = screen.getByLabelText('Ocupación baja') as HTMLInputElement;
        const high = screen.getByLabelText(
            'Ocupación alta',
        ) as HTMLInputElement;
        expect(low.value).toBe('70');
        expect(high.value).toBe('110');
        expect(low.getAttribute('aria-describedby')).toContain('-help');
    });

    it('avisa en vivo si la ocupación baja no queda por debajo de la alta', async () => {
        const user = userEvent.setup();
        render(<AdminSettings {...props} />);

        expect(
            screen.queryByText(
                'La ocupación baja tiene que ser menor que la alta.',
            ),
        ).toBeNull();

        const low = screen.getByLabelText('Ocupación baja');
        await user.clear(low);
        await user.type(low, '120');

        expect(
            screen.getByText(
                'La ocupación baja tiene que ser menor que la alta.',
            ),
        ).toBeTruthy();
    });
});
