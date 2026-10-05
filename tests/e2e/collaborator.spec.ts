import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { COLLABORATOR_USER, login } from './support';

/**
 * Colaborador externo (Fase 8, D-134) sobre los datos del DemoDataSeeder: Sara Colaboradora solo es
 * miembro de «Rediseño web» (MIR-WEB, con bolsa) y «Tienda online» (FAR-SHOP), con una tarea suya en
 * cada uno. Recorre: entrar, ver solo sus proyectos, abrir una tarea, imputar con el temporizador,
 * escribir en el chat de su proyecto y no poder entrar en Clientes.
 * Nunca contra el servidor (playwright.config.ts).
 */

const OWN_PROJECT = 'Rediseño web';
const OTHER_OWN_PROJECT = 'Tienda online';
const FOREIGN_PROJECT = 'Web corporativa';
const TASK = 'Retoque de las fotos de habitaciones';

function projectTabs(page: Page) {
    return page.getByRole('navigation', { name: 'Secciones del proyecto' });
}

test('un colaborador externo solo trabaja en sus proyectos: tarea, temporizador y chat', async ({
    page,
}) => {
    // El temporizador tiene que medir más de medio minuto para imputar 0:01.
    test.setTimeout(120_000);

    await test.step('entra y su navegación no tiene lo que no le toca', async () => {
        await login(page, COLLABORATOR_USER);

        const nav = page.getByRole('navigation', {
            name: 'Navegación principal',
        });
        await expect(
            nav.getByRole('link', { name: 'Proyectos' }),
        ).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Chat' })).toBeVisible();

        for (const name of [
            'Mi espacio',
            'Weeklies',
            'Clientes',
            'Equipo',
            'Bolsas',
            'Carga',
            'Ausencias',
            'Informes',
            'Administración',
        ]) {
            await expect(nav.getByRole('link', { name })).toHaveCount(0);
        }
    });

    await test.step('solo ve sus proyectos', async () => {
        await page.goto('/proyectos');

        const main = page.getByRole('main');
        await expect(
            main.getByRole('link', { name: OWN_PROJECT }).first(),
        ).toBeVisible();
        await expect(
            main.getByRole('link', { name: OTHER_OWN_PROJECT }).first(),
        ).toBeVisible();
        await expect(
            main.getByRole('link', { name: FOREIGN_PROJECT }),
        ).toHaveCount(0);
    });

    await test.step('abre su tarea en el proyecto, sin bolsas, horas de todos ni ajustes', async () => {
        await page
            .getByRole('main')
            .getByRole('link', { name: OWN_PROJECT })
            .first()
            .click();

        const tabs = projectTabs(page);
        await expect(tabs.getByRole('link', { name: 'Tareas' })).toBeVisible();
        for (const name of ['Bolsas', 'Horas', 'Ajustes']) {
            await expect(tabs.getByRole('link', { name })).toHaveCount(0);
        }

        await tabs.getByRole('link', { name: 'Tareas' }).click();
        const row = page
            .locator('[data-test="task-row"]')
            .filter({ hasText: TASK });
        await expect(row).toBeVisible();
        await row.getByText(TASK).click();

        await expect(page.locator('[data-test="task-panel"]')).toBeVisible();
        await expect(page).toHaveURL(/[?&]tarea=\d+/);
    });

    await test.step('imputa con el temporizador', async () => {
        await page.goto('/mis-tareas');
        await page
            .getByRole('button', {
                name: `Iniciar el temporizador en «${TASK}»`,
            })
            .first()
            .click();

        const chip = page.locator('[data-test="timer-chip"]');
        await expect(chip).toBeVisible();

        await page.waitForTimeout(35_000);
        await chip
            .getByRole('button', {
                name: `Parar el temporizador de «${TASK}»`,
            })
            .click();
        await expect(
            page.getByText(`Temporizador parado: 0:01 imputadas en «${TASK}».`),
        ).toBeVisible();
        await expect(chip).toBeHidden();
    });

    await test.step('escribe en el chat de su proyecto', async () => {
        await page.goto('/proyectos');
        await page
            .getByRole('main')
            .getByRole('link', { name: OWN_PROJECT })
            .first()
            .click();
        await projectTabs(page).getByRole('link', { name: 'Chat' }).click();
        await expect(page).toHaveURL(/\/proyectos\/\d+\/chat$/);

        const composer = page.getByRole('combobox', {
            name: 'Escribe un mensaje',
        });
        const text = `Fotos retocadas ${Date.now().toString(36)}`;
        await composer.fill(text);
        await composer.press('Enter');
        await expect(
            page
                .locator('[data-test="chat-message"]')
                .filter({ hasText: text }),
        ).toBeVisible();
    });

    await test.step('Clientes le responde 403', async () => {
        const response = await page.goto('/clientes');
        expect(response?.status()).toBe(403);
    });

    await test.step('el Equipo y el estado de proyectos de la Weekly, también 403', async () => {
        for (const path of ['/equipo', '/weeklies/estado-proyectos']) {
            const response = await page.goto(path);
            expect(response?.status()).toBe(403);
        }
    });
});
