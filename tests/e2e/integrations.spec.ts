import { expect, test } from '@playwright/test';
import { login, pageProps, USERS } from './support';

/**
 * Ajustes → Integraciones (Fase 9, D-142). Lo que se ve depende de las credenciales de Google del
 * servidor de E2E (prop compartida `integrations.google_sheets`):
 * - sin GOOGLE_CLIENT_ID ni GOOGLE_CLIENT_SECRET (la CI), la página dice que no está disponible,
 * - con credenciales ficticias, ofrece «Conectar con Google». Nunca se sigue hasta Google.
 * En local se ejecuta dos veces, una con cada servidor; la prueba que no toca se salta con su motivo.
 */
test.describe('Integraciones', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, USERS.employee);
        await page.goto('/ajustes/integraciones');
    });

    test('sin credenciales de Google, la integración no está disponible', async ({
        page,
    }) => {
        const integrations = (await pageProps(page)).integrations as
            | { google_sheets: boolean }
            | undefined;
        test.skip(
            integrations?.google_sheets === true,
            'El servidor de E2E tiene credenciales de Google.',
        );

        await expect(
            page.getByRole('heading', { level: 1, name: 'Ajustes' }),
        ).toBeVisible();
        await expect(
            page
                .getByRole('navigation', { name: 'Ajustes' })
                .getByRole('link', { name: 'Integraciones' }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(
            page.locator('[data-test="google-unavailable"]'),
        ).toContainText('Google Sheets no está disponible');
        await expect(
            page.getByRole('button', { name: 'Conectar con Google' }),
        ).toHaveCount(0);
    });

    test('con credenciales ficticias, ofrece conectar con Google', async ({
        page,
    }) => {
        const integrations = (await pageProps(page)).integrations as
            | { google_sheets: boolean; google_connected: boolean }
            | undefined;
        test.skip(
            integrations?.google_sheets !== true,
            'El servidor de E2E no tiene credenciales de Google (GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET).',
        );

        expect(integrations?.google_connected).toBe(false);
        await expect(
            page.locator('[data-test="google-unavailable"]'),
        ).toHaveCount(0);

        const connect = page.getByRole('button', {
            name: 'Conectar con Google',
        });

        await expect(connect).toBeVisible();
        await expect(connect).toBeEnabled();
        await expect(
            page.getByText('Usa tu cuenta de Google de Audax Studio.'),
        ).toBeVisible();
        await expect(
            page.getByText(
                /solo puede ver y cambiar los archivos que crea ella/,
            ),
        ).toBeVisible();
    });
});
