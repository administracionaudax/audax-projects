import fs from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import type { Theme } from './support';
import { expectTheme, login, saveUserTheme, USERS } from './support';

/**
 * Auditoría visible (D-074) sobre los datos del DemoDataSeeder: el admin filtra por entidad,
 * despliega el detalle de un cambio y descarga el CSV con los mismos filtros; axe AA en claro y
 * en oscuro y sin scroll horizontal a 375 px. Nunca contra el servidor.
 */

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function expectNoSeriousViolations(
    page: Page,
    label: string,
): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    const serious = results.violations.filter(
        (v) => v.impact === 'serious' || v.impact === 'critical',
    );
    const report = serious
        .map(
            (v) =>
                `${v.id} (${v.impact}): ${v.help}\n  ${v.nodes
                    .map((n) => n.target.join(' '))
                    .slice(0, 5)
                    .join('\n  ')}`,
        )
        .join('\n');

    expect(serious, `${label}\n${report}`).toEqual([]);
}

test('el admin filtra la auditoría, ve el detalle y descarga el CSV', async ({
    page,
}) => {
    await login(page, USERS.admin);

    // Un cambio seguro de encontrar: el admin guarda los ajustes con un redondeo distinto.
    await page.goto('/admin/ajustes');
    const rounding = page.getByLabel('Redondeo al parar');
    const current = await rounding.inputValue();
    await rounding.selectOption(current === '5' ? '10' : '5');
    await page.getByRole('button', { name: 'Guardar los ajustes' }).click();
    await expect(
        page.getByText('Ajustes guardados', { exact: false }).first(),
    ).toBeVisible();

    await page.goto('/admin');
    await page.getByRole('link', { name: 'Abrir la auditoría' }).click();
    await expect(page).toHaveURL(/\/admin\/auditoria$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
        'Auditoría',
    );

    const filters = page.getByRole('search', {
        name: 'Filtros de la auditoría',
    });
    await filters.getByLabel('Entidad').selectOption('settings');
    await expect(page).toHaveURL(/entidad=settings/);
    await filters.getByLabel('Acción').selectOption('updated');
    await expect(page).toHaveURL(/accion=updated/);

    const table = page.getByRole('table', { name: /Cambios registrados/ });
    const first = table.locator('[data-test="audit-entry"]').first();
    await expect(first).toContainText('Ajustes generales');
    await expect(first).toContainText('Ana Administración');

    await first.getByRole('button', { name: /Ver cambios/ }).click();
    const detail = page
        .getByRole('table', { name: 'Cambios de Ajustes generales' })
        .first();
    await expect(detail).toContainText('Redondeo del temporizador');

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('link', { name: 'Exportar CSV' }).click(),
    ]);
    const csv = fs.readFileSync(await download.path()).toString('utf8');

    expect(download.suggestedFilename()).toMatch(
        /^auditoria-\d{4}-\d{2}-\d{2}\.csv$/,
    );
    expect(
        csv.startsWith('﻿Fecha y hora;Persona;Entidad;Elemento;Acción;Cambios'),
    ).toBe(true);
    expect(csv).toContain('Ajustes generales');
    expect(csv).not.toContain(';Tarea;');

    await page.getByRole('button', { name: 'Quitar los filtros' }).click();
    await expect(page).toHaveURL(/\/admin\/auditoria$/);
});

test('una empleada no entra en la auditoría', async ({ page }) => {
    await login(page, USERS.employee);
    const response = await page.goto('/admin/auditoria');

    expect(response?.status()).toBe(403);
});

for (const theme of ['light', 'dark'] as const satisfies readonly Theme[]) {
    test(`auditoría sin violaciones AA graves en tema ${theme === 'light' ? 'claro' : 'oscuro'}`, async ({
        page,
    }) => {
        await login(page, USERS.admin);
        await saveUserTheme(page, theme);
        await page.goto('/admin/auditoria');
        await page.waitForLoadState('networkidle');
        await expectTheme(page, theme);

        const toggle = page
            .getByRole('button', { name: /Ver cambios/ })
            .first();

        if (await toggle.isVisible()) {
            await toggle.click();
        }

        await expectNoSeriousViolations(page, `/admin/auditoria (${theme})`);
    });
}

test('a 375 px la auditoría no tiene scroll horizontal de la página', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.admin);
    await page.goto('/admin/auditoria');
    await page.waitForLoadState('networkidle');

    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
});
