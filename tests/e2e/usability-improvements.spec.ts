import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Mejoras de uso del 07/10 (D-320 a D-325) con los datos de ejemplo del DemoDataSeeder: el
 * responsable trabaja en «Web corporativa» (ARR-WEB), de Bodegas Arrieta.
 * - Los estados de la lista de tareas se pliegan y se recuerdan al recargar (D-320).
 * - La hoja de horas «Por días» y «Añadir horas» en un día (D-321).
 * - Buscar un proyecto por el nombre de su cliente, en el listado y en Cmd+K (D-322).
 * - «Añadir horas» desde la tarea, con el foco en la duración (D-323).
 * - Abrir una tarea pulsando en cualquier punto de su fila (D-324).
 * Nunca contra el servidor (playwright.config.ts).
 */

const stamp = Date.now().toString(36);

async function openProjectTasks(page: Page): Promise<string> {
    await page.goto('/proyectos?buscar=ARR-WEB');
    const href = await page
        .getByRole('link', { name: /Web corporativa/ })
        .first()
        .getAttribute('href');
    const id = /\/proyectos\/(\d+)/.exec(href ?? '')?.[1];
    expect(id, 'proyecto «Web corporativa» del DemoDataSeeder').toBeTruthy();
    await page.goto(`/proyectos/${id}/tareas`);

    return id as string;
}

/** Crea una tarea en «Por hacer» con el alta rápida y devuelve su fila. */
async function createTask(page: Page, title: string): Promise<Locator> {
    const input = page.locator('[data-test="quick-add-input"]').first();
    await input.fill(title);
    await input.press('Enter');

    const row = page
        .locator('[data-test="task-row"]')
        .filter({ hasText: title });
    await expect(row).toBeVisible();

    return row;
}

function groupToggle(page: Page, name: string): Locator {
    return page
        .locator('[data-test="task-group-toggle"]')
        .filter({ hasText: name });
}

test('un estado de la lista de tareas se pliega y sigue plegado al recargar (D-320)', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await openProjectTasks(page);

    const toggle = groupToggle(page, 'En revisión');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    const content = page.locator(
        `#${await toggle.getAttribute('aria-controls')}`,
    );
    await expect(content).toBeVisible();

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(content).toBeHidden();

    await page.reload();
    await expect(groupToggle(page, 'En revisión')).toHaveAttribute(
        'aria-expanded',
        'false',
    );

    // Con el teclado se vuelve a desplegar (y queda como estaba para los demás tests).
    await groupToggle(page, 'En revisión').focus();
    await page.keyboard.press('Enter');
    await expect(groupToggle(page, 'En revisión')).toHaveAttribute(
        'aria-expanded',
        'true',
    );
});

test('una tarea se abre pulsando en cualquier punto de su fila y la casilla no la abre (D-324)', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await openProjectTasks(page);
    const title = `Fila E2E ${stamp}`;
    const row = await createTask(page, title);

    // La casilla de selección sigue siendo suya.
    await row.getByRole('checkbox').click();
    await expect(page.locator('[data-test="task-panel"]')).toBeHidden();
    await row.getByRole('checkbox').click();

    // Un clic en la celda de las fechas (no en el título) abre el panel.
    await row.locator('td').nth(4).click();
    const panel = page.locator('[data-test="task-panel"]');
    await expect(panel).toBeVisible();
    await expect(panel.locator('[data-test="task-title-input"]')).toHaveValue(
        title,
    );
    await expect(page).toHaveURL(/[?&]tarea=\d+/);
});

test('«Añadir horas» desde la tarea abre el diálogo con la tarea, hoy y el foco en la duración (D-323)', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await openProjectTasks(page);
    const title = `Imputar E2E ${stamp}`;
    const row = await createTask(page, title);
    await row.getByText(title).click();

    const panel = page.locator('[data-test="task-panel"]');
    const actions = panel.locator('[data-test="task-panel-actions"]');
    await expect(actions.getByRole('button').nth(0)).toHaveText('Iniciar');
    await expect(actions.getByRole('button').nth(1)).toHaveText('Añadir horas');
    await actions.getByRole('button', { name: 'Añadir horas' }).click();

    const dialog = page.getByRole('dialog', { name: 'Añadir horas' });
    await expect(dialog).toBeVisible();
    const duration = dialog.getByLabel('Duración', { exact: true });
    await expect(duration).toBeFocused();
    await expect(dialog.getByLabel('Tarea')).toContainText(title);

    await page.keyboard.type('0:45');
    await dialog.getByRole('button', { name: 'Guardar horas' }).click();
    await expect(dialog).toBeHidden();
    await expect(
        panel.locator('[data-test="task-time-entry"]').filter({
            hasText: '0:45',
        }),
    ).toBeVisible();
});

test('la hoja de horas «Por días»: cada día con su total y «Añadir horas» en un día (D-321)', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await openProjectTasks(page);
    const title = `Por días E2E ${stamp}`;
    await createTask(page, title);

    await page.goto('/horas');
    await page.getByRole('radio', { name: 'Por días' }).click();
    await expect(page).toHaveURL(/vista=dias/);

    const days = page.locator('[data-test="timesheet-day"]');
    await expect(days).toHaveCount(7);
    const today = page.locator('[data-test="timesheet-day"]:has-text("Hoy")');
    await expect(today).toHaveCount(1);
    await expect(today.locator('[data-test="day-total"]')).toBeVisible();

    await today.locator('[data-test="day-add"]').click();
    const dialog = page.getByRole('dialog', { name: 'Añadir horas' });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Tarea').click();
    await page.getByPlaceholder('Busca por tarea o proyecto').fill(title);
    await page
        .locator('[cmdk-group]')
        .getByRole('option')
        .filter({ hasText: title })
        .first()
        .click();
    await dialog.getByLabel('Duración', { exact: true }).fill('0:30');
    await dialog.getByRole('button', { name: 'Guardar horas' }).click();
    await expect(dialog).toBeHidden();

    const entry = today
        .locator('[data-test="day-entry"]')
        .filter({ hasText: title });
    await expect(entry).toBeVisible();
    await expect(entry).toContainText('0:30');

    // Pulsar la entrada la edita.
    await entry.click();
    await expect(
        page.getByRole('dialog', { name: 'Editar horas' }),
    ).toBeVisible();
    await page.keyboard.press('Escape');

    // La vista se recuerda en el navegador: sin ?vista= vuelve por días.
    await page.goto('/horas');
    await expect(page.locator('[data-test="timesheet-days"]')).toBeVisible();
    await page.getByRole('radio', { name: 'Semana' }).click();
    await expect(page.locator('[data-test="timesheet-days"]')).toBeHidden();
});

test('un proyecto se encuentra por el nombre de su cliente, en el listado por clientes y en Cmd+K (D-322)', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/proyectos');

    // Por defecto, por clientes; los internos, bajo el nombre de la empresa.
    await expect(
        page.getByRole('radio', { name: 'Por clientes' }),
    ).toBeChecked();
    await expect(
        page
            .locator('[data-test="project-group-toggle"]')
            .filter({ hasText: 'Audax Studio (interno)' }),
    ).toBeVisible();

    await page.getByRole('searchbox', { name: 'Buscar' }).fill('arrieta');
    await expect(page).toHaveURL(/buscar=arrieta/);

    const groups = page.locator('[data-test="project-group"]');
    await expect(groups).toHaveCount(1);
    await expect(
        groups.locator('[data-test="project-group-toggle"]'),
    ).toContainText('Bodegas Arrieta');
    await expect(
        groups.getByRole('link', { name: /Web corporativa/ }),
    ).toBeVisible();
    await expect(
        groups.locator('[data-test="tree-bank"]').first(),
    ).toBeVisible();

    // Toda la fila del proyecto lo abre.
    await groups
        .locator('[data-test="tree-project"]')
        .filter({ hasText: 'Web corporativa' })
        .locator('td')
        .first()
        .click();
    await expect(page).toHaveURL(/\/proyectos\/\d+$/);

    // Búsqueda global.
    await page.getByRole('button', { name: /^Buscar…/ }).click();
    await page
        .getByPlaceholder('Buscar clientes, proyectos, tareas, personas…')
        .fill('arrieta');
    await expect(
        page.getByRole('option', { name: /Campañas 2026/ }),
    ).toBeVisible();
});

test('la vista plana de proyectos lleva ?vista=lista y se recuerda (D-322)', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/proyectos');

    await page.getByRole('radio', { name: 'Lista' }).click();
    await expect(page).toHaveURL(/vista=lista/);
    await expect(
        page.getByRole('navigation', { name: 'Páginas de proyectos' }),
    ).toBeVisible();

    await page.goto('/proyectos');
    await expect(page).toHaveURL(/vista=lista/);

    // Se deja como estaba.
    await page.getByRole('radio', { name: 'Por clientes' }).click();
    await expect(page).toHaveURL(/\/proyectos$/);
    await expect(page.locator('[data-test="projects-tree"]')).toBeVisible();
});
