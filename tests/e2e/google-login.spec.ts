import { expect, test } from '@playwright/test';
import { login, pageProps, USERS } from './support';

/**
 * Entrar con Google (D-165). Lo que se ve depende de las credenciales de Google del servidor de
 * E2E (prop `googleLogin` de /login):
 * - sin GOOGLE_CLIENT_ID ni GOOGLE_CLIENT_SECRET (la CI), /login no ofrece Google,
 * - con credenciales ficticias, ofrece «Entrar con Google». Nunca se llega a Google: la petición a
 *   accounts.google.com se intercepta y se comprueban sus parámetros.
 * La prueba que no toca se salta con su motivo.
 */
test.describe('Entrar con Google', () => {
    test('sin credenciales, /login solo ofrece correo y contraseña', async ({
        page,
    }) => {
        await page.goto('/login');
        test.skip(
            (await pageProps(page)).googleLogin === true,
            'El servidor de E2E tiene credenciales de Google.',
        );

        await expect(
            page.getByRole('button', { name: 'Iniciar sesión' }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Entrar con Google' }),
        ).toHaveCount(0);
    });

    test('con credenciales, el botón manda a Google con state, PKCE, nonce y hd', async ({
        page,
    }) => {
        await page.goto('/login');
        test.skip(
            (await pageProps(page)).googleLogin !== true,
            'El servidor de E2E no tiene credenciales de Google (GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET).',
        );

        const button = page.getByRole('button', { name: 'Entrar con Google' });
        await expect(button).toBeVisible();
        await expect(page.locator('[data-test="google-logo"]')).toBeVisible();

        let googleUrl: URL | null = null;
        await page.route('https://accounts.google.com/**', async (route) => {
            googleUrl = new URL(route.request().url());
            await route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<p>Google (simulado)</p>',
            });
        });

        await page
            .getByRole('checkbox', { name: 'Mantener la sesión iniciada' })
            .check();
        await button.click();
        await expect(page.getByText('Google (simulado)')).toBeVisible();

        expect(googleUrl).not.toBeNull();
        const params = (googleUrl as unknown as URL).searchParams;
        expect(params.get('scope')).toBe('openid email profile');
        expect(params.get('response_type')).toBe('code');
        expect(params.get('code_challenge_method')).toBe('S256');
        expect(params.get('state')).toHaveLength(40);
        expect(params.get('nonce')).toBeTruthy();
        expect(params.get('hd')).toBe('audaxstudio.com');
        expect(params.get('redirect_uri')).toMatch(
            /\/login\/google\/callback$/,
        );
    });

    test('una vuelta de Google con un state que no vale explica el motivo en /login', async ({
        page,
    }) => {
        await page.goto('/login');
        test.skip(
            (await pageProps(page)).googleLogin !== true,
            'El servidor de E2E no tiene credenciales de Google.',
        );

        await page.goto('/login/google/callback?state=falso&code=falso');

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByRole('alert')).toContainText(
            'El acceso con Google ha caducado o no es válido.',
        );
        await expect(
            page.getByRole('button', { name: 'Entrar con Google' }),
        ).toBeVisible();
    });

    test('el admin ve el interruptor en /admin/ajustes', async ({ page }) => {
        await login(page, USERS.admin);
        await page.goto('/admin/ajustes');

        const toggle = page.getByRole('switch', { name: 'Entrar con Google' });
        await expect(toggle).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-checked', 'true');

        const configured = (
            (await pageProps(page)).googleLogin as
                | { configured: boolean }
                | undefined
        )?.configured;
        await expect(
            page.locator('[data-test="google-login-unconfigured"]'),
        ).toHaveCount(configured ? 0 : 1);
    });
});
