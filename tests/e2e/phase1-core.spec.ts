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
    await dialog.getByLabel('Duración', { exact: true }).fill('1');
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
    await dialog.getByLabel('Duración', { exact: true }).fill('1');
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

test('el temporizador se inicia desde la cabecera eligiendo la tarea, sin ir a ella', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/horas');

    await page.locator('[data-test="header-start-timer"]').click();
    await page
        .getByPlaceholder('Busca por tarea o proyecto')
        .waitFor({ state: 'visible' });
    const option = page.locator('[cmdk-group]').getByRole('option').first();
    const title = (await option.innerText()).trim();
    await option.click();

    const chip = page.locator('[data-test="timer-chip"]');
    await expect(chip).toBeVisible();
    await expect(chip).toContainText(title);
    await expect(page.locator('[data-test="header-start-timer"]')).toBeHidden();

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
    await expect(
        page.locator('[data-test="header-start-timer"]'),
    ).toBeVisible();
});

/**
 * Flujo crítico del plan de la Fase 1 (BRN-09): crear la bolsa y una tarea, imputar con el
 * temporizador y a mano, comprobar el consumo y el exceso en la tarjeta (política allow) y renovar.
 */
test('bolsa de principio a fin: crearla, tarea, temporizador imputado, consumo y exceso, renovación', async ({
    page,
}) => {
    test.setTimeout(120_000);
    const stamp = Date.now();
    const bankName = `Bolsa E2E ${stamp}`;
    const taskTitle = `Tarea E2E ${stamp}`;
    const sections = page.getByRole('navigation', {
        name: 'Secciones del proyecto',
    });

    await login(page, USERS.manager);
    await page.goto('/proyectos?buscar=ARR-WEB');
    await page.getByRole('link', { name: 'Web corporativa' }).first().click();

    await test.step('crear una bolsa de 1 h (de cualquier departamento, admite exceso)', async () => {
        await sections.getByRole('link', { name: 'Bolsas' }).click();
        await page.getByRole('button', { name: 'Nueva bolsa' }).click();
        const form = page.getByRole('dialog', { name: 'Nueva bolsa de horas' });
        await form.getByLabel('Nombre').fill(bankName);
        await form.getByLabel('Total de horas').fill('1:00');
        await form.getByRole('button', { name: 'Crear la bolsa' }).click();
        await expect(form).toBeHidden();
        await expect(
            page
                .locator('[data-test="hour-bank-card"]')
                .filter({ hasText: bankName }),
        ).toContainText('0:00 / 1:00');
    });

    await test.step('crear una tarea en la bolsa', async () => {
        await sections.getByRole('link', { name: 'Tareas' }).click();
        await page
            .getByRole('combobox', { name: 'Bolsa de la nueva tarea' })
            .first()
            .click();
        // Con el teclado (búsqueda por texto del Select): con muchas bolsas, la opción puede
        // quedar fuera de la pantalla y el Select no desplaza su lista con la rueda.
        const option = page.getByRole('option', { name: bankName });
        await expect(option).toBeAttached();
        await page.keyboard.type(bankName);
        await expect(option).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(
            page
                .getByRole('combobox', { name: 'Bolsa de la nueva tarea' })
                .first(),
        ).toContainText(bankName);
        const input = page.locator('[data-test="quick-add-input"]').first();
        await input.fill(taskTitle);
        await input.press('Enter');
        await expect(
            page
                .locator('[data-test="task-row"]')
                .filter({ hasText: taskTitle }),
        ).toBeVisible();
    });

    await test.step('el temporizador imputa lo medido al pararlo', async () => {
        await page
            .getByRole('button', {
                name: `Iniciar el temporizador en «${taskTitle}»`,
            })
            .first()
            .click();
        await expect(page.locator('[data-test="timer-chip"]')).toBeVisible();

        // Más de medio minuto: con el redondeo por defecto (al minuto) se imputa 0:01.
        await page.waitForTimeout(35_000);
        await page
            .locator('[data-test="timer-chip"]')
            .getByRole('button', {
                name: `Parar el temporizador de «${taskTitle}»`,
            })
            .click();
        await expect(
            page.getByText(
                `Temporizador parado: 0:01 imputadas en «${taskTitle}».`,
            ),
        ).toBeVisible();
        await expect(page.locator('[data-test="timer-chip"]')).toBeHidden();
    });

    await test.step('una imputación que cruza el límite va en parte como exceso', async () => {
        const dialog = await openLogDialog(page);
        await pickTask(page, taskTitle, 'ARR-WEB');
        await dialog.getByLabel('Duración', { exact: true }).fill('1:30');
        await dialog.getByRole('button', { name: 'Guardar horas' }).click();
        await expect(dialog).toBeHidden();
        await expect(
            page.getByText(/0:31 de esta entrada se registrarán como exceso/),
        ).toBeVisible();
    });

    await test.step('la tarjeta de la bolsa refleja el consumo y el exceso', async () => {
        await sections.getByRole('link', { name: 'Bolsas' }).click();
        const card = page
            .locator('[data-test="hour-bank-card"]')
            .filter({ hasText: bankName });
        await expect(card).toContainText('1:31 / 1:00');
        await expect(card).toContainText('+0:31 de exceso');
        await expect(card).toContainText('Agotada');
    });

    await test.step('renovar crea una bolsa nueva y la anterior queda renovada', async () => {
        const card = page
            .locator('[data-test="hour-bank-card"]')
            .filter({ hasText: bankName });
        await card.getByRole('button', { name: 'Renovar' }).click();
        const dialog = page.getByRole('dialog', {
            name: `Renovar «${bankName}»`,
        });
        await expect(dialog).toContainText(
            'Las horas ya imputadas (1:31) se quedan en la bolsa actual',
        );
        await dialog.getByRole('button', { name: 'Renovar la bolsa' }).click();
        await expect(dialog).toBeHidden();

        // Se abre el detalle de la bolsa nueva: sin consumo, activa y enlazada a la anterior, que
        // queda renovada (las horas no se mueven).
        await expect(page).toHaveURL(/\/bolsas\/\d+$/);
        const detail = page.getByRole('region').filter({
            has: page.getByRole('heading', { level: 2, name: bankName }),
        });
        await expect(detail).toContainText('0:00 / 1:00');
        await expect(detail).toContainText('Activa');
        await expect(detail).toContainText('Renueva a');
        await expect(detail).toContainText('Renovada');
    });
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
