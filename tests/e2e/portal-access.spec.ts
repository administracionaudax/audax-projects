import AxeBuilder from '@axe-core/playwright';
import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Acceso al portal y proyectos en el portal (Fase 5, P2: D-063, D-064, D-067) sobre los datos del
 * DemoDataSeeder:
 * - cliente@example.com es de «Bodegas Arrieta»,
 * - ARR-WEB («Web corporativa») está abierto al portal (tareas, horas por tarea y Gantt),
 * - ARR-MKT («Campañas 2026») sigue cerrado.
 * La persona invitada lleva un sello único, así que el spec no depende de otras ejecuciones.
 * Nunca contra el servidor (playwright.config.ts).
 */

const CLIENT_NAME = 'Bodegas Arrieta';
const OPEN_PROJECT = 'Web corporativa';
const CLOSED_PROJECT = 'Campañas 2026';
const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function asUser(
    browser: Browser,
    email: string,
    viewport?: { width: number; height: number },
): Promise<Page> {
    const context = await browser.newContext({
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
        ...(viewport ? { viewport } : {}),
    });
    const page = await context.newPage();
    await login(page, email);

    return page;
}

/** Ficha del cliente de cliente@example.com, desde el listado de clientes. */
async function openClient(page: Page): Promise<void> {
    await page.goto('/clientes');
    await page.getByRole('link', { name: CLIENT_NAME, exact: true }).click();
    await expect(
        page.getByRole('heading', { level: 1, name: CLIENT_NAME }),
    ).toBeVisible();
}

test('el admin invita a una persona del cliente desde su ficha y le revoca el acceso', async ({
    browser,
}) => {
    const page = await asUser(browser, USERS.admin);

    try {
        await openClient(page);
        const portal = page.locator('[data-test="client-portal"]');
        await expect(portal).toBeVisible();
        // El resumen enseña el proyecto abierto al portal por los datos de ejemplo.
        await expect(
            portal.locator('[data-test="portal-open-projects"]'),
        ).toContainText(OPEN_PROJECT);

        const email = `portal-e2e-${Date.now()}@example.com`;
        await portal.getByRole('button', { name: 'Invitar al portal' }).click();
        const dialog = page.getByRole('dialog', {
            name: `Invitar al portal de ${CLIENT_NAME}`,
        });
        await dialog.getByLabel('Nombre').fill('Persona E2E');
        await dialog.getByLabel('Correo electrónico').fill(email);
        await dialog
            .getByRole('button', { name: 'Enviar la invitación' })
            .click();
        await expect(dialog).toBeHidden();

        const row = portal.locator('[data-test="portal-user"]', {
            hasText: email,
        });
        await expect(row).toContainText('Invitación pendiente');
        await expect(row).toContainText('El enlace caduca el');

        // Un correo del equipo no se puede invitar.
        await portal.getByRole('button', { name: 'Invitar al portal' }).click();
        await dialog.getByLabel('Nombre').fill('Elena');
        await dialog.getByLabel('Correo electrónico').fill(USERS.employee);
        await dialog
            .getByRole('button', { name: 'Enviar la invitación' })
            .click();
        await expect(dialog).toContainText(
            'Este correo es de una persona del equipo',
        );
        await page.keyboard.press('Escape');

        // Revocar pide confirmación y la persona se queda sin acceso.
        await row
            .getByRole('button', { name: 'Acciones de Persona E2E' })
            .click();
        await page.getByRole('menuitem', { name: 'Revocar el acceso' }).click();
        const confirm = page.getByRole('dialog', {
            name: '¿Revocar el acceso de Persona E2E?',
        });
        await confirm
            .getByRole('button', { name: 'Revocar el acceso' })
            .click();
        await expect(confirm).toBeHidden();
        await expect(row).toContainText('Sin acceso');
    } finally {
        await page.context().close();
    }
});

test('el cliente ve las tareas y el Gantt de solo lectura de un proyecto abierto, y no uno cerrado (404)', async ({
    browser,
}) => {
    // Id del proyecto cerrado al portal, desde la ficha del cliente (como admin).
    const admin = await asUser(browser, USERS.admin);
    let closedId = '';

    try {
        await openClient(admin);
        const href = await admin
            .getByRole('link', { name: CLOSED_PROJECT, exact: true })
            .getAttribute('href');
        closedId = /\/proyectos\/(\d+)/.exec(href ?? '')?.[1] ?? '';
    } finally {
        await admin.context().close();
    }

    expect(closedId, 'id de ARR-MKT').not.toBe('');

    const page = await asUser(browser, USERS.client);

    try {
        await expect(page).toHaveURL(/\/portal\/?$/);

        const nav = page.getByRole('navigation', { name: 'Portal' });
        await nav.getByRole('link', { name: 'Proyectos' }).click();
        await expect(page).toHaveURL(/\/portal\/proyectos$/);
        await expect(
            page.getByRole('link', { name: CLOSED_PROJECT }),
        ).toHaveCount(0);

        await page
            .getByRole('link', { name: OPEN_PROJECT, exact: true })
            .click();
        await expect(page).toHaveURL(/\/portal\/proyectos\/\d+$/);
        await expect(
            page.getByRole('heading', { level: 1, name: OPEN_PROJECT }),
        ).toBeVisible();

        // Tareas con estado y entrega, y las horas por tarea (abiertas en los datos de ejemplo).
        const tasks = page.getByRole('region', {
            name: `Tareas de ${OPEN_PROJECT}`,
        });
        await expect(
            tasks.locator('[data-test="portal-task"]').first(),
        ).toBeVisible();
        await expect(
            tasks.getByRole('columnheader', { name: 'Estado' }),
        ).toBeVisible();
        await expect(
            tasks.getByRole('columnheader', { name: 'Horas' }),
        ).toBeVisible();
        // Nunca personas ni importes.
        await expect(page.getByText('Responsable')).toHaveCount(0);
        await expect(page.getByText('€')).toHaveCount(0);

        // Gantt de solo lectura: sin colores por responsable, sin conectores y con su tabla.
        await page
            .getByRole('navigation', { name: 'Secciones del proyecto' })
            .getByRole('link', { name: 'Gantt' })
            .click();
        await expect(page).toHaveURL(/\/portal\/proyectos\/\d+\/gantt$/);
        await expect(
            page.getByRole('region', { name: `Gantt de ${OPEN_PROJECT}` }),
        ).toBeVisible();
        const firstBar = page.locator('[data-test="gantt-bar"]').first();
        await expect(firstBar).toBeVisible();
        await expect(firstBar).toHaveAttribute('aria-label', /Solo lectura/);
        await expect(
            page.locator('[data-test="gantt-color-assignee"]'),
        ).toHaveCount(0);
        await expect(page.locator('[data-test="gantt-connector"]')).toHaveCount(
            0,
        );

        await page.getByRole('radio', { name: 'Tabla' }).click();
        await expect(page.locator('[data-test="gantt-table"]')).toBeVisible();
        await expect(
            page.getByRole('columnheader', { name: 'Responsable' }),
        ).toHaveCount(0);

        // Un proyecto de su cliente que no está abierto al portal: 404, también su Gantt.
        const view = await page.goto(`/portal/proyectos/${closedId}`);
        expect(view?.status()).toBe(404);
        const gantt = await page.goto(`/portal/proyectos/${closedId}/gantt`);
        expect(gantt?.status()).toBe(404);
    } finally {
        await page.context().close();
    }
});

test('las páginas de proyectos del portal no tienen violaciones de axe y a 375 px no hay scroll horizontal', async ({
    browser,
}) => {
    const page = await asUser(browser, USERS.client, {
        width: 375,
        height: 812,
    });

    try {
        await page.goto('/portal/proyectos');
        await page
            .getByRole('link', { name: OPEN_PROJECT, exact: true })
            .click();
        await expect(page).toHaveURL(/\/portal\/proyectos\/\d+$/);
        const projectUrl = page.url();

        for (const url of [
            '/portal/proyectos',
            projectUrl,
            `${projectUrl}/gantt`,
        ]) {
            await page.goto(url);
            await expect(page.locator('main h1')).toBeVisible();

            const results = await new AxeBuilder({ page })
                .withTags(WCAG_AA)
                .analyze();
            expect(
                results.violations.map(
                    (violation) =>
                        `${violation.id}: ${violation.nodes
                            .slice(0, 3)
                            .map((node) => node.target.join(' '))
                            .join(' | ')}`,
                ),
                url,
            ).toEqual([]);

            const sizes = await page.evaluate(() => ({
                page: document.documentElement.scrollWidth,
                viewport: document.documentElement.clientWidth,
            }));
            expect(sizes.page, url).toBeLessThanOrEqual(sizes.viewport);
        }
    } finally {
        await page.context().close();
    }
});
