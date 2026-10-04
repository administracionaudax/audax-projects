import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Mis tareas (D-143) con los datos de ejemplo: orden por defecto «Imputadas recientemente»,
 * filtros y orden en la URL, guardados en el navegador y recuperados al volver, «Limpiar filtros»,
 * accesibilidad AA y el móvil de 375 px. Nunca contra el servidor (playwright.config.ts).
 */

async function expectNoPageScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
}

test('ordenar, filtrar, recordar los filtros y limpiarlos', async ({
    page,
}) => {
    test.setTimeout(60_000);
    await login(page, USERS.employee);
    await page.goto('/mis-tareas');

    const sort = page.locator('[data-test="my-tasks-sort"]');
    await expect(sort).toContainText('Imputadas recientemente');
    await expect(page.locator('[data-test="my-task"]').first()).toBeVisible();

    await test.step('ordenar por vencimiento: secciones y orden en la URL', async () => {
        await sort.click();
        await page.getByRole('option', { name: 'Vencimiento' }).click();
        await expect(page).toHaveURL(/[?&]orden=vencimiento/);
        await expect(
            page.getByRole('heading', { level: 2 }).first(),
        ).toBeVisible();
    });

    await test.step('buscar por texto (se aplica sola)', async () => {
        const first = (
            await page.locator('[data-test="my-task"] a').first().innerText()
        ).trim();
        const word =
            first.split(/\s+/).find((part) => part.length > 3) ?? first;
        await page.locator('[data-test="my-tasks-search"]').fill(word);
        await expect(page).toHaveURL(/[?&]q=/);
        await expect(
            page.locator('[data-test="my-task"]').first(),
        ).toContainText(word, { ignoreCase: true });
    });

    await test.step('al volver sin filtros en la URL, se recuperan los guardados', async () => {
        await page.goto('/');
        await page.goto('/mis-tareas');
        await expect(page).toHaveURL(/[?&]orden=vencimiento/);
        await expect(page).toHaveURL(/[?&]q=/);
    });

    await test.step('«Limpiar filtros» quita los filtros y conserva el orden', async () => {
        await page.locator('[data-test="my-tasks-clear"]').click();
        await expect(page).not.toHaveURL(/[?&]q=/);
        await expect(page).toHaveURL(/[?&]orden=vencimiento/);
    });

    await test.step('filtrar por vencimiento: solo las vencidas', async () => {
        await page.locator('[data-test="my-tasks-due"]').click();
        await page.getByRole('option', { name: 'Vencidas' }).click();
        await expect(page).toHaveURL(/[?&]vence=vencidas/);
        await expect(
            page.locator('[data-test="my-tasks-count"]'),
        ).toBeVisible();
    });
});

test('Mis tareas cumple AA y en el móvil no se desplaza en horizontal', async ({
    page,
}) => {
    test.setTimeout(60_000);
    await login(page, USERS.employee);
    await page.goto('/mis-tareas');
    await expect(page.locator('[data-test="my-task"]').first()).toBeVisible();

    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    expect(results.violations.map((item) => item.id)).toEqual([]);

    await page.setViewportSize({ width: 375, height: 812 });
    await page.reload();
    await expect(page.locator('[data-test="my-task"]').first()).toBeVisible();
    // Los filtros se pliegan tras un botón.
    await expect(page.locator('[data-test="my-tasks-filters"]')).toBeHidden();
    await page.getByRole('button', { name: /^Filtros/ }).click();
    await expect(page.locator('[data-test="my-tasks-filters"]')).toBeVisible();
    await expectNoPageScroll(page);

    const mobile = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    expect(mobile.violations.map((item) => item.id)).toEqual([]);
});
