import { expect, test } from '@playwright/test';
import {
    expectTheme,
    login,
    saveUserTheme,
    SIDEBAR_PATHS,
    USERS,
} from './support';

/**
 * Aceptación de la Fase 0 (SPEC §17): un usuario inicia sesión, navega, cambia el tema y cierra sesión.
 */
test('iniciar sesión, navegar por la barra lateral, cambiar el tema y cerrar sesión', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await expect(page).toHaveURL(/\/$/);

    await test.step('navegar por la barra lateral', async () => {
        for (const path of SIDEBAR_PATHS) {
            await page.locator(`a[href$="${path}"]:visible`).first().click();
            await expect(page).toHaveURL(new RegExp(`${path}$`));
            await expect(
                page.locator('main, [data-slot="sidebar-inset"]').first(),
            ).toBeVisible();
        }
    });

    await test.step('cambiar el tema a oscuro y comprobar que se mantiene al recargar', async () => {
        await page.goto('/ajustes/apariencia');

        const dark = /^(oscuro|dark)$/i;
        await page
            .getByRole('button', { name: dark })
            .or(page.getByRole('radio', { name: dark }))
            .or(page.getByRole('tab', { name: dark }))
            .first()
            .click();
        await expectTheme(page, 'dark');

        await page.reload();
        await expectTheme(page, 'dark');
    });

    await test.step('volver al tema claro', async () => {
        const light = /^(claro|light)$/i;
        await page
            .getByRole('button', { name: light })
            .or(page.getByRole('radio', { name: light }))
            .or(page.getByRole('tab', { name: light }))
            .first()
            .click();
        await expectTheme(page, 'light');

        // Deja la cuenta como la sembró el seeder («según el sistema»): el login aplica el tema
        // guardado y no debe colarse en otros specs (F09).
        await saveUserTheme(page, 'system');
    });

    await test.step('cerrar sesión', async () => {
        await page
            .locator('[data-test="sidebar-menu-button"]:visible')
            .first()
            .click();
        await page.locator('[data-test="logout-button"]').click();
        await expect(page).toHaveURL(/\/login(?:\?|$)/);

        await page.goto('/proyectos');
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
    });
});

/**
 * UX-03: los errores se explican en español, con el tema de la app, y sin el mensaje en inglés de
 * la excepción (403 por rol de Spatie, 404 de un recurso que no existe).
 */
test('una página sin permiso o que no existe se explica en español', async ({
    page,
}) => {
    await login(page, USERS.employee);

    const forbidden = await page.goto('/admin');
    expect(forbidden?.status()).toBe(403);
    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'No tienes acceso a esta página',
        }),
    ).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'es');
    await expect(
        page.getByText('User does not have the right roles'),
    ).toHaveCount(0);

    const missing = await page.goto('/proyectos/999999');
    expect(missing?.status()).toBe(404);
    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'No encontramos esta página',
        }),
    ).toBeVisible();

    await page.getByRole('link', { name: 'Ir a Inicio' }).click();
    await expect(page).toHaveURL(/\/$/);
});
