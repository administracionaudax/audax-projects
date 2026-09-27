import type { Locator } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Texto sobre el degradado de marca (UI-02). El cálculo de contraste en toda la superficie
 * está en tests/js/brand-gradient-contrast.test.ts; aquí se comprueba en el navegador real que
 * las superficies con degradado pintan el velo navy encima y que el texto usa los colores
 * validados (blanco al 85 % y la palabra clave en azul claro), en móvil y en escritorio.
 */
const VIEWPORTS = [
    { width: 375, height: 812 },
    { width: 1440, height: 900 },
] as const;

async function expectVeiledGradient(surface: Locator): Promise<void> {
    const image = await surface.evaluate(
        (el) => getComputedStyle(el).backgroundImage,
    );

    // Primera capa: el velo (linear-gradient); debajo, las capas radiales del degradado.
    expect(image.startsWith('linear-gradient(')).toBe(true);
    expect(image).toContain('radial-gradient(');
}

async function colorOf(locator: Locator): Promise<string> {
    return locator.evaluate((el) => getComputedStyle(el).color);
}

async function expectOnGradientText(surface: Locator): Promise<void> {
    for (const item of await surface
        .locator('.text-on-gradient-muted:visible')
        .all()) {
        expect(await colorOf(item)).toBe('rgba(255, 255, 255, 0.85)');
    }

    for (const item of await surface
        .locator('.text-on-gradient-keyword:visible')
        .all()) {
        expect(await colorOf(item)).toBe('rgb(140, 187, 255)');
    }

    // Nada de texto secundario al 60 % (text-muted-foreground) sobre el degradado.
    await expect(
        surface.locator('p.text-muted-foreground:visible'),
    ).toHaveCount(0);
}

// Un solo inicio de sesión: el login está limitado por minuto.
test('degradado con velo y texto AA en móvil y en escritorio', async ({
    page,
}) => {
    for (const viewport of VIEWPORTS) {
        await test.step(`/login a ${viewport.width} px`, async () => {
            await page.setViewportSize(viewport);
            await page.goto('/login');
            const aside = page.locator('aside.bg-brand-gradient');
            await expect(aside).toBeVisible();
            await expectVeiledGradient(aside);
            await expectOnGradientText(aside);
        });
    }

    // Otro usuario que el admin: el límite de intentos de login es por correo e IP.
    await login(page, USERS.employee);

    for (const viewport of VIEWPORTS) {
        await test.step(`estado vacío grande a ${viewport.width} px`, async () => {
            await page.setViewportSize(viewport);
            // Una sección que aún llega en otra fase: su estado vacío grande lleva el degradado.
            await page.goto('/chat');
            const hero = page.locator('section.bg-brand-gradient');
            await expect(hero).toBeVisible();
            await expectVeiledGradient(hero);
            await expectOnGradientText(hero);
        });
    }
});
