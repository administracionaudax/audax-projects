import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Envíos programados (Fase 9, D-141) sobre los datos del DemoDataSeeder: el admin programa el
 * informe detallado del mes anterior para el día 1 de cada mes, lo ve en la lista, lo pausa y lo
 * reanuda, y al final lo borra (los E2E se pueden repetir). Con MAIL_MAILER=log: aquí no se envía
 * nada, solo se programa. Nunca contra el servidor.
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

    expect(
        serious,
        `${label}\n${serious.map((v) => `${v.id}: ${v.help}`).join('\n')}`,
    ).toEqual([]);
}

test('programar un envío mensual, verlo en la lista, pausarlo y reanudarlo', async ({
    page,
}) => {
    await login(page, USERS.admin);

    await test.step('llegar desde la barra lateral, dentro de Informes', async () => {
        await page.goto('/informes');
        await page
            .getByRole('link', { name: 'Envíos programados' })
            .first()
            .click();
        await expect(page).toHaveURL(/\/informes\/envios$/);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Envíos programados' }),
        ).toBeVisible();
    });

    const dialog = page.getByRole('dialog', { name: 'Programar envío' });

    await test.step('programar el informe detallado el día 1 de cada mes', async () => {
        await page.getByRole('button', { name: /Programar un envío/ }).click();
        await page.getByRole('menuitem', { name: 'Informe detallado' }).click();
        await expect(dialog).toBeVisible();

        await expect(
            dialog.getByRole('radio', { name: 'Cada mes' }),
        ).toBeChecked();
        await dialog.getByLabel('Día del mes').selectOption({ label: 'Día 1' });
        await dialog.getByLabel('Hora', { exact: true }).fill('08:00');
        await dialog
            .getByLabel(/Periodo del informe/)
            .selectOption({ label: 'El mes anterior' });
        await expect(
            dialog.locator('[data-test="schedule-preview"]'),
        ).toContainText(
            'El día 1 de cada mes a las 08:00, con el informe del mes anterior',
        );

        await dialog.getByRole('checkbox', { name: 'Excel' }).check();
        await dialog
            .getByLabel('Correos externos')
            .fill('cliente.e2e@example.com');
        await dialog.getByLabel('Correos externos').press('Enter');
        await expect(
            dialog.getByText('Este informe saldrá de la empresa.'),
        ).toBeVisible();

        await expectNoSeriousViolations(page, 'diálogo «Programar envío»');

        await dialog
            .getByRole('button', { name: 'Programar', exact: true })
            .click();
        await expect(dialog).toBeHidden();
    });

    const row = page
        .locator('[data-test="schedule-row"]')
        .filter({ hasText: 'Informe detallado' })
        .first();

    await test.step('verlo en la lista, activo', async () => {
        await expect(row).toBeVisible();
        await expect(row).toContainText('El día 1 de cada mes a las 08:00');
        await expect(row).toContainText('1 externo');
        await expect(row.locator('[data-test="schedule-status"]')).toHaveText(
            'Activo',
        );

        await expectNoSeriousViolations(page, 'lista de envíos programados');
    });

    await test.step('pausarlo y reanudarlo', async () => {
        await row.getByRole('button', { name: /^Pausar/ }).click();
        await expect(row.locator('[data-test="schedule-status"]')).toHaveText(
            'En pausa',
        );

        await row.getByRole('button', { name: /^Reanudar/ }).click();
        await expect(row.locator('[data-test="schedule-status"]')).toHaveText(
            'Activo',
        );
    });

    await test.step('ver el detalle y borrarlo', async () => {
        await row.getByRole('link', { name: 'Informe detallado' }).click();
        await expect(
            page.getByRole('heading', { level: 1, name: 'Informe detallado' }),
        ).toBeVisible();
        await expect(page.getByText('cliente.e2e@example.com')).toBeVisible();

        await page.getByRole('button', { name: 'Borrar', exact: true }).click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Borrar', exact: true })
            .click();
        await expect(page).toHaveURL(/\/informes\/envios$/);
    });
});
