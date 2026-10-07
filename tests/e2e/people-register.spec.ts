import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { expectReportPdf, login, USERS } from './support';

/*
 * Registro de jornada, entrega R2 (Fase 11; D-346 a D-359): confirmar el resumen del mes,
 * descargar «Mi registro», clasificar una hora extra y generar el «Registro mensual de la jornada».
 * El módulo `people` viene apagado: el primer test lo enciende y el último lo apaga. Datos del
 * DemoDataSeeder (D-359): Elena (empleado@example.com) tiene pendiente el resumen del mes anterior,
 * y Raúl (responsable@example.com) tiene días de su equipo por clasificar (el día largo de Lucía).
 * Nunca contra el servidor (playwright.config.ts).
 */

test.describe.configure({ mode: 'serial' });
test.use({ testIdAttribute: 'data-test' });

async function setPeopleModule(page: Page, on: boolean): Promise<void> {
    await login(page, USERS.admin);
    await page.goto('/admin/ajustes');
    const toggle = page.getByRole('switch', {
        name: 'Personas (registro de jornada)',
        exact: true,
    });

    if ((await toggle.getAttribute('aria-checked')) !== String(on)) {
        await toggle.click();
    }

    await expect(toggle).toHaveAttribute('aria-checked', String(on));
    await page.getByRole('button', { name: 'Guardar los ajustes' }).click();
    await expect(
        page.getByText('Ajustes guardados', { exact: false }).first(),
    ).toBeVisible();
}

async function expectAccessible(page: Page): Promise<void> {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    const serious = results.violations.filter((violation) =>
        ['serious', 'critical'].includes(violation.impact ?? ''),
    );

    expect(
        serious.map(
            (violation) =>
                `${violation.id}: ${violation.nodes
                    .map((node) => node.target.join(' '))
                    .slice(0, 3)
                    .join(' | ')}`,
        ),
    ).toEqual([]);
}

test('se enciende el módulo Personas', async ({ page }) => {
    await setPeopleModule(page, true);
});

test('la persona confirma el resumen del mes anterior desde Mi registro', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/personas/jornada');
    await expect(page.getByTestId('close-banner')).toBeVisible();
    await page.getByTestId('close-banner').getByRole('link').click();

    await expect(page).toHaveURL(/\/personas\/registro/);
    const card = page.getByTestId('close-answer');
    await expect(card).toBeVisible();
    await expect(card.getByTestId('close-status')).toHaveAttribute(
        'data-status',
        'pending',
    );

    await card.getByTestId('close-confirm').click();
    await expect(page.getByText('Mes confirmado').first()).toBeVisible();
    await expect(page.getByTestId('close-answer')).toHaveCount(0);
    await expect(page.getByTestId('close-item').first()).toHaveAttribute(
        'data-status',
        'confirmed',
    );
});

test('la persona descarga su registro del periodo en CSV y en PDF, con su huella', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/personas/registro');

    const [csv] = await Promise.all([
        page.waitForEvent('download'),
        page.getByTestId('register-download-csv').click(),
    ]);
    expect(csv.suggestedFilename()).toMatch(/^mi-registro-de-jornada-.*\.csv$/);
    const text = readFileSync(await csv.path(), 'utf8');
    expect(text).toContain('Huella del contenido');

    const [pdf] = await Promise.all([
        page.waitForEvent('download'),
        page.getByTestId('register-download-pdf').click(),
    ]);
    expectReportPdf({
        name: pdf.suggestedFilename(),
        bytes: readFileSync(await pdf.path()),
    });
});

test('su responsable clasifica una hora extra para compensar con descanso', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/personas/horas-extra');

    await expect(page.getByTestId('overtime-item').first()).toBeVisible();
    await expectAccessible(page);
    // Un día con más de media hora de exceso (el día largo de Lucía, como mínimo).
    const excesses = await page
        .getByTestId('overtime-item')
        .evaluateAll((items) =>
            items.map((item) => Number(item.getAttribute('data-excess'))),
        );
    const item = page
        .getByTestId('overtime-item')
        .nth(excesses.findIndex((excess) => excess >= 30));
    const date = await item.getAttribute('data-date');
    const before = await page.getByTestId('overtime-item').count();

    await item.getByTestId('overtime-minutes').fill('0:20');
    await item.getByTestId('overtime-destination-compensate').click();
    await item.getByTestId('overtime-note').fill('Entrega del cliente (E2E)');
    await item.getByTestId('overtime-save').click();

    await expect(
        page.getByText('Clasificación guardada').first(),
    ).toBeVisible();
    await expect(page.getByTestId('overtime-item')).toHaveCount(before - 1);
    await expect(page.getByTestId('decided-table')).toContainText(
        new RegExp(`${date?.slice(8, 10)}/${date?.slice(5, 7)}`),
    );
});

test('RR. HH. genera el «Registro mensual de la jornada» del mes anterior', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/personas/informes');

    await expect(
        page.getByTestId('report-kind-registro-mensual'),
    ).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('report-preview')).toBeVisible();
    await expectAccessible(page);

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByTestId('report-download-pdf').click(),
    ]);
    expect(download.suggestedFilename()).toMatch(
        /^registro-mensual-de-la-jornada-/,
    );
    expectReportPdf({
        name: download.suggestedFilename(),
        bytes: readFileSync(await download.path()),
    });

    await page.reload();
    await expect(page.getByTestId('report-exports')).toContainText(
        'Registro mensual de la jornada',
    );
});

test('se vuelve a apagar el módulo', async ({ page }) => {
    await setPeopleModule(page, false);
});
