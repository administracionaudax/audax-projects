import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Flujos críticos de la Fase 1 (SPEC §17) sobre los datos de ejemplo del DemoDataSeeder:
 * - Bodegas Arrieta · «ARR-WEB» tiene la bolsa de Diseño agotada con política allow (exceso),
 * - Clínica Dental Sonrisas · «SON-SEO» tiene una bolsa de Marketing con política block y 0:45 libres,
 * - Elena (Diseño) y Raúl (su responsable) e Irene (Marketing) tienen la contraseña de ejemplo.
 * Nunca contra el servidor (playwright.config.ts).
 */

const MARKETING = 'irene.castro@example.com';

async function openLogDialog(page: Page) {
    await page.locator('[data-test="header-log-time"]').click();
    // Por nombre: el buscador de tareas también es un diálogo (popover).
    const dialog = page.getByRole('dialog', { name: 'Añadir horas' });
    await expect(dialog).toBeVisible();

    return dialog;
}

async function pickTask(page: Page, search: string, projectCode: string) {
    await page
        .getByRole('dialog', { name: 'Añadir horas' })
        .getByLabel('Tarea')
        .click();
    await page.getByPlaceholder('Busca por tarea o proyecto').fill(search);
    await page
        .locator('[cmdk-group]')
        .filter({ hasText: projectCode })
        .getByRole('option')
        .first()
        .click();
}

async function asUser(browser: Browser, email: string): Promise<Page> {
    const context = await browser.newContext({
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
    });
    const page = await context.newPage();
    await login(page, email);

    return page;
}

test('imputar en una bolsa agotada con política allow avisa de que va como exceso', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/horas');

    const dialog = await openLogDialog(page);
    await pickTask(page, 'Prototipo', 'ARR-WEB');
    await dialog.getByLabel('Duración').fill('1');
    await dialog.getByRole('button', { name: 'Guardar horas' }).click();

    await expect(dialog).toBeHidden();
    await expect(page.getByText(/Horas guardadas: 1:00/)).toBeVisible();
    await expect(
        page.getByText(/bolsa está agotada.*exceso/).first(),
    ).toBeVisible();
});

test('una bolsa con política block rechaza la imputación que no cabe e indica el saldo', async ({
    page,
}) => {
    await login(page, MARKETING);
    await page.goto('/horas');

    const dialog = await openLogDialog(page);
    await pickTask(page, 'Auditoría SEO', 'SON-SEO');
    await dialog.getByLabel('Duración').fill('1');
    await dialog.getByRole('button', { name: 'Guardar horas' }).click();

    await expect(dialog).toBeVisible();
    await expect(
        dialog.getByText(/no admite exceso\. Saldo disponible: 0:45/),
    ).toBeVisible();
});

test('el temporizador se inicia desde Mis tareas, aparece en la cabecera y se puede descartar', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/mis-tareas');

    await page
        .getByRole('button', { name: /Iniciar el temporizador en/ })
        .first()
        .click();

    const chip = page.locator('[data-test="timer-chip"]');
    await expect(chip).toBeVisible();
    await expect(page.locator('[data-test="timer-elapsed"]')).toHaveText(
        /\d+:\d{2}:\d{2}/,
    );

    await page
        .getByRole('button', { name: 'Más opciones del temporizador' })
        .click();
    await page
        .getByRole('menuitem', { name: 'Descartar el temporizador' })
        .click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Descartar el temporizador' })
        .click();

    await expect(chip).toBeHidden();
    await expect(page.locator('[data-test="header-log-time"]')).toBeVisible();
});

test.describe.serial('aprobación de la semana (D-020)', () => {
    test('la persona envía su semana y su responsable la aprueba', async ({
        page,
        browser,
    }) => {
        await login(page, USERS.employee);
        await page.goto('/horas');
        await page.locator('[data-test="submit-week"]').click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: 'Enviar la semana' })
            .click();
        await expect(page.locator('[data-test="week-status"]')).toContainText(
            'Enviada',
        );

        const manager = await asUser(browser, USERS.manager);
        await manager.goto('/horas/aprobaciones');
        const week = manager
            .locator('[data-test="pending-week"]')
            .filter({ hasText: 'Elena Empleada' })
            .first();
        await expect(week).toBeVisible();
        await week
            .getByRole('button', {
                name: /^Aprobar la semana .* de Elena Empleada$/,
            })
            .click();
        await expect(
            manager
                .locator('[data-test="pending-week"]')
                .filter({ hasText: 'Elena Empleada' }),
        ).toHaveCount(0);
        await manager.context().close();

        await page.reload();
        await expect(page.locator('[data-test="week-status"]')).toContainText(
            'Aprobada por Raúl Responsable',
        );
    });
});

test('tareas: creación rápida, panel lateral y comentario', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/proyectos?buscar=ARR-WEB');
    await page.getByRole('link', { name: 'Web corporativa' }).first().click();
    await page
        .getByRole('navigation', { name: 'Secciones del proyecto' })
        .getByRole('link', { name: 'Tareas' })
        .click();

    const title = `Revisar textos legales ${Date.now()}`;
    const input = page.locator('[data-test="quick-add-input"]').first();
    await input.fill(title);
    await input.press('Enter');

    const row = page
        .locator('[data-test="task-row"]')
        .filter({ hasText: title });
    await expect(row).toBeVisible();

    await row.getByText(title).click();
    const panel = page.locator('[data-test="task-panel"]');
    await expect(panel).toBeVisible();
    await expect(page).toHaveURL(/[?&]tarea=\d+/);

    await panel.locator('[data-test="comment-composer-open"]').click();
    await panel
        .locator('[contenteditable="true"]')
        .last()
        .fill('Listo para revisar');
    await panel.locator('[data-test="comment-submit"]').click();
    await expect(
        panel
            .locator('[data-test="comment"]')
            .filter({ hasText: 'Listo para revisar' }),
    ).toBeVisible();
});

test('clientes y proyectos: alta de un cliente y de un proyecto por horas', async ({
    page,
}) => {
    const name = `Cliente E2E ${Date.now()}`;

    await login(page, USERS.admin);
    await page.goto('/clientes');
    await page.locator('[data-test="new-client"]').click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Nombre').fill(name);
    await dialog.getByRole('button', { name: 'Crear el cliente' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText(name).first()).toBeVisible();

    await page.goto('/proyectos/nuevo');
    await page.getByLabel('Nombre', { exact: true }).fill('Mantenimiento web');
    await page.getByLabel('Tipo de facturación').click();
    await page.getByRole('option', { name: 'Por horas' }).click();
    await page.getByLabel('Cliente', { exact: true }).click();
    await page.getByRole('option', { name }).click();
    await page.getByRole('button', { name: 'Crear el proyecto' }).click();

    await expect(
        page.getByRole('heading', { name: 'Mantenimiento web' }),
    ).toBeVisible();
    await expect(
        page.getByRole('navigation', { name: 'Secciones del proyecto' }),
    ).toBeVisible();
});
