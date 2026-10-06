import AxeBuilder from '@axe-core/playwright';
import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Paridad con WeeklySync (Fase 10, entrega 10.9b) con los datos de ejemplo del DemoDataSeeder:
 * «Estoy fuera» con efecto inmediato (D-228), asignar clientes y el mapa de constancia en la ficha
 * de persona (D-233), los filtros del equipo al volver de una ficha y la foto de perfil (D-234).
 * Cada test deja la base como estaba. Nunca contra el servidor.
 */

async function expectAccessible(page: Page, label: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    expect(results.violations.map((item) => `${label}: ${item.id}`)).toEqual(
        [],
    );
}

/** Abre la semana en curso si no hay ninguna activa (F-040). */
async function ensureActiveWeek(browser: Browser): Promise<void> {
    const context = await browser.newContext();
    const admin = await context.newPage();
    await login(admin, USERS.admin);
    await admin.goto('/weeklies');
    const open = admin.locator('[data-test="weekly-open-cycle"]');

    if (await open.isVisible()) {
        await open.click();
        await expect(
            admin.locator('[data-test="weekly-manage"]'),
        ).toBeVisible();
    }

    await context.close();
}

/** Una imagen PNG de 2 x 2 píxeles. */
const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==',
    'base64',
);

test('«Estoy fuera»: me marco fuera desde «Mi weekly», quedo exento al momento y vuelvo (D-228)', async ({
    page,
    browser,
}) => {
    test.setTimeout(60_000);
    await ensureActiveWeek(browser);
    await login(page, USERS.manager);
    await page.goto('/weeklies');

    const callout = page.locator('[data-test="my-weekly-callout"]');
    await expect(callout).toBeVisible();
    const away = callout.locator('[data-test="my-weekly-away"]');
    await expect(away).toBeVisible();
    await expect(
        away.getByRole('link', { name: 'Solicitar ausencia' }),
    ).toHaveAttribute('href', '/ausencias?solicitar=1');

    await away.getByRole('button', { name: 'Marcar que estoy fuera' }).click();
    const dialog = page.getByRole('dialog', { name: 'Estoy fuera' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('radio', { name: 'Ausente o de baja' }).click();
    await expectAccessible(page, 'estoy fuera');
    await dialog.locator('[data-test="weekly-away-save"]').click();
    await expect(dialog).toHaveCount(0);

    await expect(callout).toHaveAttribute('data-status', 'exempt');
    await expect(callout).toContainText('Weekly exenta: estás fuera');
    await expect(
        page.locator('[data-test="user-away-badge"]:visible'),
    ).toHaveCount(1);

    await callout.getByRole('button', { name: 'Cambiar mi estado' }).click();
    await page
        .getByRole('dialog')
        .locator('[data-test="weekly-away-clear"]')
        .click();
    await expect(callout).not.toHaveAttribute('data-status', 'exempt');
    await expect(page.locator('[data-test="user-away-badge"]')).toHaveCount(0);
});

test('la ficha de persona: constancia, asignar clientes y quitarlos (D-233)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/equipo');
    await page
        .locator('[data-test="team-row"]')
        .filter({ hasText: 'Pablo Ruiz' })
        .getByRole('link', { name: 'Pablo Ruiz' })
        .click();
    await expect(page).toHaveURL(/\/equipo\/\d+$/);

    await expect(
        page.locator('[data-test="weekly-consistency"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="weekly-consistency"] a[data-state]').first(),
    ).toBeVisible();

    await page.locator('[data-test="person-assign-clients"]').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('checkbox').first()).toBeVisible();
    await expectAccessible(page, 'asignar clientes');
    const first = dialog.locator('li').first();
    const client = (
        await first.locator('label span').first().textContent()
    )?.trim() as string;
    await first.getByRole('checkbox').check();
    await dialog.locator('[data-test="join-clients-confirm"]').click();
    await expect(dialog).toHaveCount(0);

    const assigned = page
        .locator('[data-test="person-client"]')
        .filter({ hasText: client });
    await expect(assigned).toBeVisible();
    await assigned.locator('[data-test="person-client-unassign"]').click();
    await expect(
        page.locator('[data-test="person-client"]').filter({ hasText: client }),
    ).toHaveCount(0);
});

test('los filtros de /equipo se conservan al volver de una ficha', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/equipo');
    await page
        .getByLabel('Departamento', { exact: true })
        .selectOption({ index: 1 });
    const rows = page.locator('[data-test="team-row"]');
    const count = await rows.count();
    const value = await page
        .getByLabel('Departamento', { exact: true })
        .inputValue();

    await rows.first().getByRole('link').first().click();
    await expect(page).toHaveURL(/\/equipo\/\d+$/);
    await page.goBack();

    await expect(page.getByLabel('Departamento', { exact: true })).toHaveValue(
        value,
    );
    await expect(rows).toHaveCount(count);
});

test('la foto de perfil: elegirla, encuadrarla, guardarla y quitarla (D-234)', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/ajustes/perfil');

    await page.locator('[data-test="avatar-input"]').setInputFiles({
        name: 'yo.png',
        mimeType: 'image/png',
        buffer: PNG,
    });
    const dialog = page.getByRole('dialog', { name: 'Encuadra tu foto' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-test="avatar-crop"]')).toBeVisible();
    await dialog.locator('[data-test="avatar-zoom"]').fill('2');
    await expectAccessible(page, 'recortar la foto');
    await dialog.locator('[data-test="avatar-save"]').click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByText('Foto de perfil actualizada.')).toBeVisible();

    const remove = page.locator('[data-test="avatar-remove"]');
    await expect(remove).toBeVisible();
    await remove.click();
    await expect(page.getByText('Foto de perfil quitada.')).toBeVisible();
    await expect(remove).toHaveCount(0);
});
