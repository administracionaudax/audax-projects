import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Mejoras de tareas (D-170 a D-173) con los datos de ejemplo del DemoDataSeeder: la responsable
 * crea tareas en «Web corporativa» (ARR-WEB), como en phase1-core.spec.ts.
 * - Crear subtareas con sus datos en un diálogo, con «Crear otra al guardar» (D-173).
 * - Imputar en una subtarea con hora de inicio y fin; la franja sale en la lista de entradas y el
 *   registrado de la tarea padre suma el de sus subtareas, con su desglose (D-170, D-172).
 * - Una franja que cruza la medianoche se rechaza explicando cómo registrarla (D-172).
 * Nunca contra el servidor (playwright.config.ts).
 */

async function openTasks(page: Page): Promise<void> {
    await page.goto('/proyectos?buscar=ARR-WEB');
    await page.getByRole('link', { name: 'Web corporativa' }).first().click();
    await page
        .getByRole('navigation', { name: 'Secciones del proyecto' })
        .getByRole('link', { name: 'Tareas' })
        .click();
}

/** Crea una tarea raíz con el alta rápida y abre su panel. */
async function createParent(page: Page, title: string): Promise<Locator> {
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

    return panel;
}

/** «Añadir subtarea» → diálogo con sus datos. */
async function addSubtasks(
    page: Page,
    panel: Locator,
    subtasks: { title: string; estimate: string }[],
): Promise<void> {
    await panel.locator('[data-test="add-subtask"]').click();
    const dialog = page.locator('[data-test="task-create-dialog"]');
    await expect(
        dialog.getByRole('heading', { name: 'Nueva subtarea' }),
    ).toBeVisible();

    if (subtasks.length > 1) {
        await dialog
            .getByRole('switch', { name: 'Crear otra al guardar' })
            .click();
    }

    for (const [index, subtask] of subtasks.entries()) {
        await dialog.getByLabel('Horas estimadas').fill(subtask.estimate);
        const title = dialog.getByLabel('Título');
        await title.fill(subtask.title);
        await title.press('Enter');

        if (index < subtasks.length - 1) {
            await expect(dialog.getByRole('status')).toHaveText(
                `Creada «${subtask.title}». Escribe la siguiente.`,
            );
            await expect(title).toHaveValue('');
            await expect(title).toBeFocused();
        }
    }

    if (subtasks.length > 1) {
        await dialog.getByRole('button', { name: 'Cancelar' }).click();
    }

    await expect(dialog).toBeHidden();
}

test('crea subtareas con sus datos en un diálogo y la tarea padre suma sus estimaciones', async ({
    page,
}) => {
    test.setTimeout(60_000);
    await login(page, USERS.manager);
    await openTasks(page);

    const parent = `Desarrollo web ${Date.now()}`;
    const panel = await createParent(page, parent);

    await addSubtasks(page, panel, [
        { title: 'Maquetación', estimate: '2' },
        { title: 'Formularios', estimate: '1:30' },
    ]);

    const list = panel.locator('[data-test="subtask-list"]');
    await expect(list).toContainText('Maquetación');
    await expect(list).toContainText('Formularios');
    await expect(
        panel.getByText(
            'Es la suma de las estimaciones de sus subtareas: cámbiala en ellas.',
        ),
    ).toBeVisible();
    await expect(panel).toContainText('3:30');
});

test('imputa en una subtarea con hora de inicio y fin y el padre enseña el registrado total con su desglose', async ({
    page,
}) => {
    test.setTimeout(90_000);
    await login(page, USERS.manager);
    await openTasks(page);

    const parent = `Lanzamiento ${Date.now()}`;
    const panel = await createParent(page, parent);
    await addSubtasks(page, panel, [{ title: 'Copias', estimate: '4' }]);

    await test.step('abre la subtarea e imputa de 00:00 a 01:30', async () => {
        await panel
            .locator('[data-test="subtask-list"]')
            .getByRole('button', { name: 'Copias' })
            .click();
        await expect(panel).toContainText(parent);

        await panel.getByRole('button', { name: 'Añadir horas' }).click();
        const dialog = page.getByRole('dialog', { name: 'Añadir horas' });
        await dialog
            .getByRole('radio', { name: 'Con hora de inicio y fin' })
            .click();
        await dialog.getByLabel('Inicio').fill('00:00');
        await dialog.getByLabel('Fin').fill('01:30');
        await expect(dialog.getByText('Duración: 1:30')).toBeVisible();
        await dialog.getByRole('button', { name: 'Guardar horas' }).click();
        await expect(dialog).toBeHidden();

        await expect(
            panel.locator('[data-test="task-time-range"]').first(),
        ).toHaveText('Franja: 00:00–01:30');
    });

    await test.step('vuelve al padre: 1:30 registradas, todas en subtareas', async () => {
        // «Subtarea de «…»»: el enlace a la tarea padre en la cabecera del panel.
        await page
            .getByRole('button', { name: new RegExp(`«${parent}»`) })
            .first()
            .click();
        const breakdown = panel.locator('[data-test="task-logged-breakdown"]');
        await expect(breakdown).toContainText('Registrado total');
        await expect(breakdown).toContainText('1:30');
        await expect(breakdown).toContainText('de 4:00');
        await expect(breakdown).toContainText('En subtareas');
    });

    await test.step('en la lista, el padre muestra el total', async () => {
        await page.keyboard.press('Escape');
        const row = page
            .locator('[data-test="task-row"]')
            .filter({ hasText: parent })
            .first();
        await expect(row).toContainText('1:30');
    });
});

test('una franja que cruza la medianoche no se guarda y explica cómo registrarla', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await openTasks(page);

    const panel = await createParent(page, `Guardia ${Date.now()}`);
    await panel.getByRole('button', { name: 'Añadir horas' }).click();

    const dialog = page.getByRole('dialog', { name: 'Añadir horas' });
    await dialog
        .getByRole('radio', { name: 'Con hora de inicio y fin' })
        .click();
    await dialog.getByLabel('Inicio').fill('22:00');
    await dialog.getByLabel('Fin').fill('02:00');
    await dialog.getByRole('button', { name: 'Guardar horas' }).click();

    await expect(dialog).toBeVisible();
    await expect(
        dialog.getByText(/regístralo en dos entradas: hasta las 00:00/),
    ).toBeVisible();

    // 22:00–00:00 sí vale: la medianoche cierra el día.
    await dialog.getByLabel('Fin').fill('00:00');
    await expect(dialog.getByText('Duración: 2:00')).toBeVisible();
    await dialog.getByRole('button', { name: 'Guardar horas' }).click();
    await expect(dialog).toBeHidden();
    await expect(
        panel.locator('[data-test="task-time-range"]').first(),
    ).toHaveText('Franja: 22:00–24:00');
});
