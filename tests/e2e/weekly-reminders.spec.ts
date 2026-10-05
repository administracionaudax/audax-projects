import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Avisos de la Weekly (Fase 10, entrega 10.5) con los datos de ejemplo del DemoDataSeeder: la semana
 * en curso abierta, con la weekly de Elena enviada y un borrador de Pablo (el resto, pendiente). En
 * la CI el correo va al log y la cola es síncrona. Nunca contra el servidor.
 */

async function expectAccessible(page: Page, label: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    expect(results.violations.map((item) => `${label}: ${item.id}`)).toEqual(
        [],
    );
}

async function expectNoPageScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
}

test('quien gestiona programa un recordatorio, cambia un texto con vista previa y lo restaura', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/weeklies');
    await page.locator('[data-test="weekly-tab-avisos"]').click();
    await expect(page).toHaveURL(/\/weeklies\/avisos$/);
    await expect(page.locator('[data-test="reminders-cycle"]')).toContainText(
        'Semana activa',
    );

    // Un recordatorio nuevo: el jueves a las 10:00 por email; lo pasamos a las 11:30 en la app.
    const before = await page.locator('[data-test="reminder-rule"]').count();
    await page.locator('[data-test="reminder-rule-add"]').click();
    const rule = page.locator('[data-test="reminder-rule"]').nth(before);
    await rule.getByLabel('Hora (Madrid)').fill('11:30');
    await rule.getByLabel('Canal').selectOption('app');
    await expect(
        page.locator('[data-test="reminder-rules-summary"]'),
    ).toContainText('jueves a las 11:30 (En la app)');

    // El texto manual, con su vista previa en vivo.
    const manual = page.locator('[data-test="template-manual"]');
    await manual
        .getByLabel('Asunto')
        .fill('Te falta la weekly {semana}, {nombre}');
    await expect(
        manual.locator('[data-test="template-manual-preview"]'),
    ).toContainText('Te falta la weekly W');
    await expectAccessible(page, 'avisos de la weekly');

    await page.locator('[data-test="reminders-save"]').click();
    await expect(
        page.getByText('Avisos de la weekly guardados.'),
    ).toBeVisible();

    await page.reload();
    await expect(page.locator('[data-test="reminder-rule"]')).toHaveCount(
        before + 1,
    );
    await expect(manual.getByText('Personalizada')).toBeVisible();

    // Restaurar por defecto y guardar.
    await manual.locator('[data-test="template-manual-restore"]').click();
    await expect(manual.getByText('Por defecto')).toBeVisible();
    await page.locator('[data-test="reminders-save"]').click();
    await expect(
        page.getByText('Avisos de la weekly guardados.'),
    ).toBeVisible();
});

test('enviar un recordatorio a las pendientes y verlo en el registro; «Recordar» desde el resumen', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/weeklies/avisos');

    await page.locator('[data-test="reminders-send-open"]').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expectAccessible(page, 'enviar un recordatorio');
    await dialog.locator('[data-test="reminders-send-confirm"]').click();
    await expect(
        page.getByText(/Recordatorio enviado a \d+ persona/),
    ).toBeVisible();

    const log = page.locator('[data-test="reminder-log-row"]');
    await expect(log.first()).toBeVisible();
    await expect(log.first()).toContainText('Recordatorio manual');

    await page.getByLabel('Estado').selectOption('failed');
    await expect(page).toHaveURL(/estado=failed/);

    // «Recordar» a una persona pendiente desde el resumen de /weeklies.
    await page.goto('/weeklies');
    const pending = page.locator('[data-test="weekly-pending-member"]').first();
    await expect(pending).toBeVisible();
    await pending.locator('[data-test="weekly-remind"]').click();
    await expect(
        page.getByText(/Recordatorio enviado a|Ya se le ha enviado/),
    ).toBeVisible();
});

test('la plantilla no ve los avisos ni «Recordar»', async ({ page }) => {
    await login(page, USERS.employee);
    await page.goto('/weeklies');
    await expect(page.locator('[data-test="weekly-tab-avisos"]')).toHaveCount(
        0,
    );
    await expect(page.locator('[data-test="weekly-remind"]')).toHaveCount(0);

    const response = await page.goto('/weeklies/avisos');
    expect(response?.status()).toBe(403);

    await page.goto('/equipo');
    await expect(page.locator('[data-test="weekly-remind"]')).toHaveCount(0);
});

test('en el móvil (375 px) la pestaña de avisos no desborda y el registro va en tarjetas', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.manager);
    await page.goto('/weeklies/avisos');
    await expect(page.locator('[data-test="reminders-save"]')).toBeVisible();
    await expectNoPageScroll(page);
    await expectAccessible(page, 'avisos en el móvil');
});
