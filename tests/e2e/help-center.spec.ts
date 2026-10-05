import AxeBuilder from '@axe-core/playwright';
import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Centro de ayuda y sugerencias (Fase 10, entrega 10.7) sobre los datos del DemoDataSeeder (la
 * migración de sugerencias precarga el tablero «Sugerencias» y la categoría «Bugs»). Cada prueba
 * crea lo que necesita con un texto único, así que se pueden repetir. Nunca contra el servidor.
 */

const stamp = () => Date.now().toString(36);

/** Axe solo analiza páginas abiertas con browser.newContext(), no con browser.newPage(). */
async function newPage(browser: Browser): Promise<Page> {
    const context = await browser.newContext();

    return context.newPage();
}

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

test('quien gestiona publica una novedad y la plantilla le da «me gusta» y la encuentra', async ({
    browser,
}) => {
    const title = `Novedad E2E ${stamp()}`;
    const manager = await newPage(browser);
    await login(manager, USERS.manager);
    await manager.goto('/ayuda');
    await expect(manager.getByRole('heading', { level: 1 })).toContainText(
        'Centro de ayuda',
    );

    await manager.getByRole('button', { name: 'Añadir novedad' }).click();
    const dialog = manager.getByRole('dialog');
    await dialog.getByLabel('Título').fill(title);
    await dialog.getByLabel('Descripción breve').fill('Resumen de prueba');
    await dialog
        .getByRole('textbox', { name: 'Contenido detallado' })
        .fill('Todo el detalle');
    await dialog.getByRole('button', { name: 'Guardar' }).click();
    await expect(
        manager.getByText('Actualización puntual creada.'),
    ).toBeVisible();
    await expectAccessible(manager, 'ayuda general');
    await manager.context().close();

    const employee = await newPage(browser);
    await login(employee, USERS.employee);
    await employee.goto('/ayuda');
    await expect(
        employee.getByRole('button', { name: 'Añadir novedad' }),
    ).toHaveCount(0);
    await employee
        .getByRole('searchbox', { name: 'Buscar en las novedades' })
        .fill(title);
    const list = employee.locator('[data-test="help-updates"]');
    await expect(list.getByRole('heading')).toHaveCount(1);

    await list.locator('[data-test="help-like"]').click();
    await expect(list.locator('[data-test="help-like"]')).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await employee
        .getByRole('button', { name: `Ver el detalle de «${title}»` })
        .click();
    await expect(employee.getByRole('dialog')).toContainText('Todo el detalle');
    await employee.context().close();
});

test('preguntas frecuentes: crear una sección y una pregunta, desplegarla y buscarla', async ({
    page,
}) => {
    const section = `Sección ${stamp()}`;
    const question = `¿Cómo se usa la ayuda ${stamp()}?`;
    await login(page, USERS.manager);
    await page.goto('/ayuda?pestana=preguntas');

    await page.getByRole('button', { name: 'Gestionar secciones' }).click();
    await page.getByRole('button', { name: 'Añadir sección' }).click();
    await page.getByLabel('Nombre de la sección').fill(section);
    await page.getByRole('button', { name: 'Guardar' }).click();
    await expect(page.getByText('Sección creada.')).toBeVisible();
    // Se cierra el diálogo de la sección y queda el de «Gestionar secciones», que se cierra con Escape.
    await expect(page.getByRole('dialog')).toHaveCount(1);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);

    await page
        .getByRole('navigation', { name: 'Secciones' })
        .getByRole('button', { name: section })
        .click();
    await page.getByRole('button', { name: 'Añadir pregunta' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Pregunta').fill(question);
    await dialog
        .getByRole('textbox', { name: 'Respuesta' })
        .fill('Así, paso a paso.');
    await dialog.getByRole('button', { name: 'Guardar' }).click();
    await expect(page.getByText('Pregunta frecuente creada.')).toBeVisible();

    await page
        .getByRole('searchbox', { name: 'Buscar en las preguntas frecuentes' })
        .fill('paso a paso');
    await page.getByRole('button', { name: question, exact: true }).click();
    await expect(page.getByText('Así, paso a paso.')).toBeVisible();
    await expectAccessible(page, 'preguntas frecuentes');
});

test('una persona propone una sugerencia y reporta un bug; quien gestiona la mueve en el roadmap', async ({
    browser,
}) => {
    const idea = `Idea E2E ${stamp()}`;
    const bug = `Bug E2E ${stamp()}`;
    const employee = await newPage(browser);
    await login(employee, USERS.employee);

    // Una sugerencia con «similares» y un comentario.
    await employee.goto('/ayuda?pestana=sugerencias&vista=feedback');
    await employee.getByRole('button', { name: 'Añadir' }).click();
    let dialog = employee.getByRole('dialog');
    await dialog.getByLabel('Título').fill(idea);
    await dialog
        .getByRole('textbox', { name: 'Detalle' })
        .fill('Que la ayuda tenga modo oscuro.');
    await dialog.getByRole('button', { name: 'Publicar' }).click();
    await expect(employee).toHaveURL(/\/ayuda\/sugerencias\/\d+/);
    await expect(employee.getByRole('heading', { name: idea })).toBeVisible();

    await employee
        .getByRole('textbox', { name: 'Añadir comentario' })
        .fill('Y que recuerde la elección.');
    await employee.getByRole('button', { name: 'Publicar comentario' }).click();
    await expect(
        employee.locator('[data-test="suggestion-comment"]'),
    ).toContainText('Y que recuerde la elección.');
    await employee.locator('[data-test="reaction-rocket"]').first().click();
    await expect(
        employee.locator('[data-test="reaction-rocket"]').first(),
    ).toHaveAttribute('aria-pressed', 'true');
    await expectAccessible(employee, 'detalle de una sugerencia');

    // «Reportar un bug» abre el formulario fijado en Bugs.
    await employee.goto('/ayuda');
    await employee.locator('[data-test="help-report-bug"]').click();
    dialog = employee.getByRole('dialog');
    await expect(dialog).toContainText('Se publicará en la categoría «Bugs».');
    await dialog.getByLabel('Título').fill(bug);
    await dialog
        .getByRole('textbox', { name: 'Detalle' })
        .fill('Al pulsar «Guardar» no pasa nada.');
    await dialog.getByRole('button', { name: 'Publicar' }).click();
    await expect(employee.getByText('Bugs').first()).toBeVisible();
    await employee.context().close();

    // Quien gestiona la pasa a «Planificada» con una nota y la mueve a «Beta» con el menú.
    const manager = await newPage(browser);
    await login(manager, USERS.manager);
    await manager.goto('/ayuda?pestana=sugerencias&vista=feedback');
    await manager
        .getByRole('searchbox', { name: 'Buscar sugerencias' })
        .fill(idea);
    await manager.getByRole('link', { name: idea }).click();
    const moderation = manager.locator('[data-test="suggestion-moderation"]');
    await moderation.getByLabel('Estado').selectOption('planned');
    await moderation.getByLabel(/Nota oficial/).fill('Para el próximo mes');
    await moderation.getByRole('button', { name: 'Guardar estado' }).click();
    await expect(
        manager.locator('[data-test="suggestion-activity"]'),
    ).toContainText('Para el próximo mes');

    await manager.goto('/ayuda?pestana=sugerencias');
    const roadmap = manager.locator('[data-test="suggestion-roadmap"]');
    await expect(roadmap.getByRole('link', { name: idea })).toBeVisible();
    await roadmap.getByRole('button', { name: `Mover «${idea}» a…` }).click();
    await manager.getByRole('menuitem', { name: 'Beta' }).click();
    await expect(
        roadmap.locator('[data-roadmap-column="beta"]').getByRole('link', {
            name: idea,
        }),
    ).toBeVisible();
    await expectAccessible(manager, 'roadmap');
    await manager.context().close();
});

test('la ayuda en el móvil: sin desplazamiento lateral y con las pestañas a mano', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.employee);

    for (const path of [
        '/ayuda',
        '/ayuda?pestana=tutoriales',
        '/ayuda?pestana=preguntas',
        '/ayuda?pestana=sugerencias&vista=feedback',
    ]) {
        await page.goto(path);
        await expect(
            page.getByRole('navigation', {
                name: 'Secciones del centro de ayuda',
            }),
        ).toBeVisible();
        await expectNoPageScroll(page);
    }
});
