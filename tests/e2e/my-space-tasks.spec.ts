import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Tareas de «Mi espacio» y asistente IA (Fase 10, entrega 10.6) con los datos de ejemplo del
 * DemoDataSeeder: tres semanas cerradas con los envíos de la plantilla y la IA de prueba
 * (GEMINI_DRIVER=fake, FakeLlm::demo). En la CI la cola es síncrona. Nunca contra el servidor.
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

/** Elige el primer proyecto de un selector si aún no tiene ninguno. */
async function pickProject(page: Page, scope = page.locator('body')) {
    const trigger = scope
        .locator('[data-test="my-space-task-project"]')
        .first();

    if ((await trigger.textContent())?.includes('Elige un proyecto')) {
        await trigger.click();
        await page
            .getByRole('option')
            .filter({ hasNotText: 'Elige un proyecto' })
            .first()
            .click();
    }
}

test('crear una tarea en Mi espacio, escribir su nota, marcarla hecha y archivarla solo para mí', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/mi-espacio');
    await page.locator('[data-test="weekly-tab-tareas"]').click();
    await expect(page).toHaveURL(/pestana=tareas/);
    await expect(page.locator('[data-test="my-space-tasks"]')).toBeVisible();

    const title = `Preparar el moodboard ${Date.now().toString(36)}`;

    await test.step('nueva tarea con proyecto obligatorio', async () => {
        await page.locator('[data-test="my-space-tasks-new"]').click();
        const dialog = page.locator('[data-test="my-space-task-dialog"]');
        await dialog.locator('[data-test="my-space-task-save"]').click();
        await expect(
            dialog.getByText('Escribe qué hay que hacer.'),
        ).toBeVisible();

        await dialog.locator('[data-test="my-space-task-title"]').fill(title);
        await pickProject(page, dialog);
        await expectAccessible(page, 'nueva tarea de Mi espacio');
        await dialog
            .locator('[data-test="my-space-task-title"]')
            .press('Enter');
        await expect(dialog).toBeHidden();
        await expect(page.getByRole('link', { name: title })).toBeVisible();
    });

    const row = page.locator('li', {
        has: page.getByRole('link', { name: title }),
    });

    await test.step('la nota se guarda sola', async () => {
        await row
            .getByLabel(`Notas de «${title}»`)
            .fill('Referencias en la carpeta del cliente');
        await expect(row.locator('[data-test="task-notes-state"]')).toHaveText(
            'Guardado',
        );
        await page.reload();
        await expect(row.getByLabel(`Notas de «${title}»`)).toHaveValue(
            'Referencias en la carpeta del cliente',
        );
    });

    await test.step('marcarla hecha y verla en Completadas', async () => {
        await row
            .getByRole('checkbox', { name: `Marcar «${title}» como hecha` })
            .click();
        await page.locator('[data-test="my-space-tasks-filter-done"]').click();
        await expect(page.getByRole('link', { name: title })).toBeVisible();
        await page.locator('[data-test="my-space-tasks-filter-all"]').click();
    });

    await test.step('archivarla solo para mí y recuperarla', async () => {
        await row
            .getByRole('button', { name: `Archivar «${title}» en mi lista` })
            .click();
        await expect(page.getByRole('link', { name: title })).toBeHidden();
        await page.locator('[data-test="my-space-tasks-archived"]').click();
        await expect(page.getByRole('link', { name: title })).toBeVisible();
        await page
            .getByRole('button', { name: `Recuperar «${title}»` })
            .click();
        await page.locator('[data-test="my-space-tasks-archived"]').click();
        await expect(page.getByRole('link', { name: title })).toBeVisible();
    });

    await expectAccessible(page, 'tareas de Mi espacio');
});

test('generar tareas con IA desde la última weekly cerrada: se revisan antes de crearlas', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/mi-espacio?pestana=tareas');
    await expect(page.getByText(/Fuente: Semana \d+/)).toBeVisible();

    await page.locator('[data-test="my-space-tasks-generate"]').click();
    const panel = page.locator('[data-test="task-suggestions"]');
    await expect(panel).toBeVisible();
    await expect(panel.getByText(/Son propuestas de la IA/)).toBeVisible({
        timeout: 20_000,
    });

    // Ninguna tarea se ha creado todavía: la propuesta es de prueba (FakeLlm::demo).
    const suggestion = panel
        .locator('[data-test^="task-suggestion-"]')
        .filter({ has: page.locator('[data-test="task-suggestion-title"]') })
        .first();
    await expect(
        suggestion.locator('[data-test="task-suggestion-title"]'),
    ).toHaveValue(/GEMINI_DRIVER=fake/);
    await expect(
        page.getByRole('link', { name: /GEMINI_DRIVER=fake/ }),
    ).toHaveCount(0);
    await expectAccessible(page, 'tareas sugeridas');

    await suggestion
        .locator('[data-test="task-suggestion-title"]')
        .fill('Revisar la propuesta de prueba');
    await pickProject(page, suggestion);
    await panel.locator('[data-test="task-suggestions-create"]').click();

    await expect(page.getByText('Se ha creado 1 tarea.')).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Revisar la propuesta de prueba' }),
    ).toBeVisible();
});

test('el asistente IA responde con lo que puede ver quien pregunta y conserva la conversación', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.locator('a[href$="/ia"]:visible').first().click();
    await expect(page).toHaveURL(/\/ia$/);
    await expect(page.locator('[data-test="assistant-scope"]')).toContainText(
        'que has registrado tú.',
    );
    await expectAccessible(page, 'asistente IA');

    await page.locator('[data-test="assistant-suggestion-0"]').click();
    await expect(
        page.locator('[data-test="assistant-message-user"]'),
    ).toHaveCount(1);
    await expect(
        page.locator('[data-test="assistant-message-assistant"]'),
    ).toContainText('GEMINI_DRIVER=fake', { timeout: 20_000 });

    // Intro envía con la conversación anterior.
    await page
        .locator('[data-test="assistant-input"]')
        .fill('¿Y la semana pasada?');
    await page.locator('[data-test="assistant-input"]').press('Enter');
    await expect(
        page.locator('[data-test="assistant-message-assistant"]'),
    ).toHaveCount(2, { timeout: 20_000 });

    // La conversación es de la sesión: sigue al recargar y se puede empezar otra.
    await page.reload();
    await expect(
        page.locator('[data-test="assistant-message-user"]'),
    ).toHaveCount(2);
    await page.locator('[data-test="assistant-reset"]').click();
    await expect(page.getByText('¿Qué quieres saber hoy?')).toBeVisible();
});

test('Mi espacio (Tareas) y el asistente en el móvil, sin desplazamiento lateral', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.manager);

    await page.goto('/mi-espacio?pestana=tareas');
    await expect(page.locator('[data-test="my-space-tasks"]')).toBeVisible();
    await expectNoPageScroll(page);

    await page.goto('/ia');
    await expect(page.locator('[data-test="assistant-input"]')).toBeVisible();
    await expectNoPageScroll(page);
    await expectAccessible(page, 'asistente IA en el móvil');
});
