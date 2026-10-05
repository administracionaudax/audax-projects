// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { GoogleSignInButton } from '@/components/auth/google-sign-in-button';
import AdminSettings from '@/pages/admin/settings';
import AcceptInvitation from '@/pages/auth/accept-invitation';
import Login from '@/pages/auth/login';
import type { AdminSettingsProps } from '@/types';

/*
| Entrar con Google (D-165): el botón (POST a /login/google con «Mantener la sesión iniciada», sin
| salir de la página hasta que el servidor responde con la URL de Google), el error que devuelve el
| servidor, /login y la invitación según `googleLogin`, y el interruptor de /admin/ajustes.
*/

const page = vi.hoisted(() => ({
    url: '/login',
    props: { errors: {} } as Record<string, unknown>,
}));

const router = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    delete: vi.fn(),
    on: () => () => {},
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        router,
        usePage: () => page,
    };
});

beforeEach(() => {
    router.post.mockReset();
    page.props = { errors: {} };
});

describe('GoogleSignInButton', () => {
    it('muestra la «G» de Google y el texto, y hace POST a /login/google', async () => {
        render(<GoogleSignInButton remember />);

        const button = screen.getByRole('button', {
            name: 'Entrar con Google',
        });
        expect(
            button.querySelector('[data-test="google-logo"]'),
        ).not.toBeNull();
        expect(
            button
                .querySelector('[data-test="google-logo"]')
                ?.getAttribute('aria-hidden'),
        ).toBe('true');

        await userEvent.click(button);

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, data] = router.post.mock.calls[0];
        expect(url).toBe('/login/google');
        expect(data).toEqual({ remember: true });
    });

    it('mientras espera a Google se desactiva y lo dice', async () => {
        router.post.mockImplementation(
            (_url: string, _data: unknown, options: { onStart: () => void }) =>
                options.onStart(),
        );
        render(<GoogleSignInButton />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Entrar con Google' }),
        );

        const button = screen.getByRole('button', {
            name: /Abriendo Google…/,
        }) as HTMLButtonElement;
        expect(button.disabled).toBe(true);
        expect(router.post.mock.calls[0][1]).toEqual({ remember: false });
    });

    it('enseña el motivo del servidor y lo enlaza al botón', () => {
        render(
            <GoogleSignInButton error="Tu cuenta de Google no tiene el correo verificado." />,
        );

        expect(screen.getByRole('alert').textContent).toBe(
            'Tu cuenta de Google no tiene el correo verificado.',
        );
        expect(
            screen
                .getByRole('button', { name: 'Entrar con Google' })
                .getAttribute('aria-describedby'),
        ).toBe('google-error');
    });
});

describe('/login', () => {
    it('sin googleLogin no ofrece Google', () => {
        render(<Login canResetPassword />);

        expect(
            screen.queryByRole('button', { name: 'Entrar con Google' }),
        ).toBeNull();
    });

    it('con googleLogin, ofrece Google con «Mantener la sesión iniciada» y el error del servidor', async () => {
        page.props = {
            errors: {
                google: 'No hay ninguna cuenta en Audax Proyectos con nueva@audaxstudio.com. Pide a la administración que te dé de alta.',
            },
        };
        render(<Login canResetPassword googleLogin />);

        expect(screen.getByRole('alert').textContent).toContain(
            'No hay ninguna cuenta en Audax Proyectos',
        );

        await userEvent.click(
            screen.getByRole('checkbox', {
                name: 'Mantener la sesión iniciada',
            }),
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Entrar con Google' }),
        );

        expect(router.post.mock.calls[0][1]).toEqual({ remember: true });
    });
});

describe('invitación', () => {
    it('con un correo de la empresa ofrece entrar con Google sin crear contraseña', () => {
        render(
            <AcceptInvitation
                token="abc"
                email="elena@audaxstudio.com"
                passwordRules=""
                googleLogin
            />,
        );

        expect(screen.getByText('o, sin crear contraseña')).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Entrar con Google' }),
        ).toBeTruthy();
    });

    it('sin googleLogin, solo la contraseña', () => {
        render(
            <AcceptInvitation
                token="abc"
                email="amparo@gmail.com"
                passwordRules=""
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Entrar con Google' }),
        ).toBeNull();
    });
});

describe('/admin/ajustes', () => {
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
            max_audio_seconds: 300,
            weekly_digest_enabled: true,
            occupancy_low_threshold: 70,
            occupancy_high_threshold: 110,
            week_reminder_enabled: true,
            google_login_enabled: true,
        },
        roundings: [1, 5, 10, 15, 30],
        serverUploadLimitMb: null,
        googleLogin: { configured: true, domains: ['audaxstudio.com'] },
    };

    it('el interruptor «Entrar con Google» explica los dominios y se puede desactivar', async () => {
        render(<AdminSettings {...props} />);

        const toggle = screen.getByRole('switch', {
            name: 'Entrar con Google',
        });
        expect(toggle.getAttribute('aria-checked')).toBe('true');

        const help = document.getElementById(
            toggle.getAttribute('aria-describedby') ?? '',
        );
        expect(help?.textContent).toContain('@audaxstudio.com');
        expect(
            document.querySelector('[data-test="google-login-unconfigured"]'),
        ).toBeNull();

        await userEvent.click(toggle);
        expect(toggle.getAttribute('aria-checked')).toBe('false');
    });

    it('sin credenciales avisa de que no se ofrece', () => {
        render(
            <AdminSettings
                {...props}
                googleLogin={{
                    configured: false,
                    domains: ['audaxstudio.com'],
                }}
            />,
        );

        const warning = document.querySelector(
            '[data-test="google-login-unconfigured"]',
        ) as HTMLElement;
        expect(
            within(warning).getByText(/Faltan las credenciales/),
        ).toBeTruthy();
    });
});
