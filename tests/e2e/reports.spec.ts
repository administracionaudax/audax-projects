import fs from 'node:fs';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { expectReportPdf, login, USERS } from './support';

/**
 * Informes de la Fase 2 (SPEC §10, PLAN-FASE-2 «Tests»): dirección con los filtros en la URL y su
 * exportación, PDF de consumo de bolsa, informe detallado y el acceso por rol (D-044). Sobre los
 * datos del DemoDataSeeder; nunca contra el servidor (playwright.config.ts).
 */

async function download(page: Page, action: () => Promise<void>) {
    const [file] = await Promise.all([page.waitForEvent('download'), action()]);
    const path = await file.path();

    return { name: file.suggestedFilename(), bytes: fs.readFileSync(path) };
}

test('dirección: los filtros viven en la URL y la tabla se exporta a Excel', async ({
    page,
}) => {
    await login(page, USERS.admin);

    await page.goto('/informes/direccion?periodo=trimestre&comparar=1');
    await expect(
        page.getByRole('heading', { level: 1, name: 'Dirección' }),
    ).toBeVisible();
    await expect(page).toHaveURL(/periodo=trimestre/);
    await expect(page).toHaveURL(/comparar=1/);
    await expect(page.getByText('Ocupación').first()).toBeVisible();

    // La URL con los filtros se puede recargar o compartir: la página vuelve igual.
    await page.reload();
    await expect(page).toHaveURL(/periodo=trimestre/);

    await page.getByRole('button', { name: 'Exportar' }).first().click();
    const excel = await download(page, () =>
        page.getByRole('menuitem', { name: 'Excel (.xlsx)' }).click(),
    );

    expect(excel.name).toMatch(/\.xlsx$/);
    // Un XLSX es un ZIP: empieza por «PK».
    expect(excel.bytes.subarray(0, 2).toString()).toBe('PK');
});

test('el PDF de consumo de la bolsa para el cliente se descarga desde su detalle', async ({
    page,
}) => {
    await login(page, USERS.admin);

    await page.goto('/proyectos');
    await page
        .getByRole('link', { name: /Web corporativa/ })
        .first()
        .click();
    await page.getByRole('link', { name: 'Bolsas', exact: true }).click();
    await page
        .locator('a[href*="/bolsas/"]')
        .filter({ hasNotText: 'PDF' })
        .first()
        .click();
    await expect(page).toHaveURL(/\/proyectos\/\d+\/bolsas\/\d+$/);

    await page.getByRole('button', { name: /Descargar el PDF/ }).click();
    const pdf = await download(page, () =>
        page.getByRole('menuitem', { name: /Para el cliente/ }).click(),
    );

    expect(pdf.name).toMatch(/\.(pdf|html)$/);
    expectReportPdf(pdf);
});

test('informe detallado: filas y columnas elegidas en la URL con su total', async ({
    page,
}) => {
    await login(page, USERS.admin);

    await page.goto(
        '/informes/detalle?filas=cliente&columnas=mes&periodo=trimestre',
    );
    await expect(
        page.getByRole('heading', { level: 1, name: 'Informe detallado' }),
    ).toBeVisible();
    await expect(page.getByRole('table').first()).toBeVisible();
    await expect(page.getByText('Total').first()).toBeVisible();
});

test('una empleada no entra en dirección y su índice solo tiene su informe y el detallado (D-044)', async ({
    page,
}) => {
    await login(page, USERS.employee);

    const forbidden = await page.goto('/informes/direccion');
    expect(forbidden?.status()).toBe(403);

    await page.goto('/informes');
    await expect(page.getByRole('link', { name: /Dirección/ })).toHaveCount(0);
    await expect(
        page.getByRole('link', { name: /Informe detallado/ }),
    ).toBeVisible();
});
