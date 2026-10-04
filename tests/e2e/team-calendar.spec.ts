import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Calendario del equipo (/calendario, D-144) con los datos de ejemplo: Elena es miembro de
 * «App de citas» (SON-APP), así que puede mover sus tareas. Vistas mes, semana, día y personas,
 * panel de la tarea sin salir del calendario, mover con el teclado (reprogramar, D-057), crear en
 * un día, AA y el móvil de 375 px. Nunca contra el servidor (playwright.config.ts).
 */

function ymd(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

/** Lunes de la semana que viene y el miércoles de esa semana. */
function nextWeek(): { monday: string; wednesday: string; thursday: string } {
    const date = new Date();
    const day = date.getDay() === 0 ? 7 : date.getDay();
    date.setDate(date.getDate() - day + 8);
    const monday = ymd(date);
    date.setDate(date.getDate() + 2);
    const wednesday = ymd(date);
    date.setDate(date.getDate() + 1);

    return { monday, wednesday, thursday: ymd(date) };
}

async function projectId(page: Page, code: string): Promise<number> {
    await page.goto(`/proyectos?buscar=${code}`);
    await page.getByRole('link', { name: 'App de citas' }).first().click();
    await expect(page).toHaveURL(/\/proyectos\/\d+$/);

    return Number(/\/proyectos\/(\d+)/.exec(page.url())?.[1]);
}

async function createTask(
    page: Page,
    project: number,
    data: Record<string, string>,
): Promise<void> {
    const xsrf = (await page.context().cookies()).find(
        (cookie) => cookie.name === 'XSRF-TOKEN',
    );
    const response = await page.request.post(`/proyectos/${project}/tareas`, {
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? ''),
            Accept: 'text/html',
        },
        data,
        maxRedirects: 0,
    });
    expect(response.status()).toBeLessThan(400);
}

async function expectNoPageScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
}

test('ver, abrir y mover una tarea con el teclado en la semana', async ({
    page,
}) => {
    test.setTimeout(90_000);
    const title = `Equipo E2E ${Date.now()}`;
    const { monday, wednesday, thursday } = nextWeek();

    await login(page, USERS.employee);
    const project = await projectId(page, 'SON-APP');
    await createTask(page, project, { title, due_date: wednesday });

    await page.goto(`/calendario?fecha=${monday}`);
    const calendar = page.locator('[data-test="team-calendar"]');
    await expect(calendar.locator('[data-test="team-week"]')).toBeVisible();
    const chip = () =>
        calendar.getByRole('button', { name: new RegExp(`^${title}\\.`) });
    await expect(
        page
            .locator(`[data-test="team-day"][data-date="${wednesday}"]`)
            .getByRole('button', { name: new RegExp(`^${title}\\.`) }),
    ).toBeVisible();
    await expect(chip()).toHaveAttribute('aria-label', /SON-APP/);

    await test.step('pulsarla abre su panel sin salir del calendario', async () => {
        await chip().click();
        const panel = page.locator('[data-test="task-panel"]');
        await expect(panel).toBeVisible();
        await expect(page).toHaveURL(/\/calendario\?.*tarea=\d+/);
        await expect(panel.getByText(title).first()).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(panel).toBeHidden();
        await expect(page).not.toHaveURL(/tarea=/);
    });

    await test.step('con el teclado: → e Intro la llevan al jueves', async () => {
        await chip().focus();
        await page.keyboard.press('ArrowRight');
        await expect(
            chip().locator('[data-test="team-chip-target"]'),
        ).toBeVisible();
        const saved = page.waitForResponse(
            (response) =>
                /\/tareas\/\d+\/reprogramar$/.test(
                    new URL(response.url()).pathname,
                ) && response.request().method() === 'POST',
        );
        await page.keyboard.press('Enter');
        await saved;
        await expect(
            page
                .locator(`[data-test="team-day"][data-date="${thursday}"]`)
                .getByRole('button', { name: new RegExp(`^${title}\\.`) }),
        ).toBeVisible();
        await page.reload();
        await expect(
            page
                .locator(`[data-test="team-day"][data-date="${thursday}"]`)
                .getByRole('button', { name: new RegExp(`^${title}\\.`) }),
        ).toBeVisible();
    });

    await test.step('«+» de un día abre «Nueva tarea» con esa fecha', async () => {
        await page
            .locator(`[data-test="team-day"][data-date="${monday}"]`)
            .locator('[data-test="team-create"]')
            .click();
        const dialog = page.getByRole('dialog');
        await expect(
            dialog.getByRole('heading', { name: 'Nueva tarea' }),
        ).toBeVisible();
        const [year, month, day] = monday.split('-');
        await expect(dialog.getByText(`${day}/${month}/${year}`)).toBeVisible();
        await dialog.getByRole('button', { name: 'Cancelar' }).click();
        await expect(dialog).toBeHidden();
    });
});

test('mes, día y la vista por personas, con filtros en la URL', async ({
    page,
}) => {
    test.setTimeout(60_000);
    await login(page, USERS.admin);
    await page.goto('/calendario');

    await page.getByRole('radio', { name: 'Mes' }).click();
    await expect(page).toHaveURL(/vista=mes/);
    await expect(page.locator('[data-test="team-month"]')).toBeVisible();

    await page.getByRole('radio', { name: 'Día' }).click();
    await expect(page).toHaveURL(/vista=dia/);
    await page.getByRole('switch', { name: 'Por personas' }).click();
    await expect(page).toHaveURL(/personas=1/);
    await expect(page.locator('[data-test="team-people"]')).toBeVisible();
    await expect(
        page.locator('[data-test="team-person-row"]').first(),
    ).toBeVisible();

    await page.getByRole('switch', { name: 'Solo las mías' }).click();
    await expect(page).toHaveURL(/mias=1/);
    await expect(page.locator('[data-test="team-person-row"]')).toHaveCount(1);

    // Se recuerda al volver sin nada en la URL.
    await page.goto('/calendario');
    await expect(page).toHaveURL(/vista=dia/);
    await expect(page).toHaveURL(/mias=1/);
    await page.locator('[data-test="team-clear"]').click();
    await expect(page).not.toHaveURL(/mias=1/);
});

test('el calendario cumple AA y en el móvil el mes es una lista sin scroll horizontal', async ({
    page,
}) => {
    test.setTimeout(90_000);
    await login(page, USERS.employee);

    for (const url of [
        '/calendario',
        '/calendario?personas=1',
        '/calendario?vista=mes',
    ]) {
        await page.goto(url);
        await expect(page.locator('[data-test="team-calendar"]')).toBeVisible();
        const results = await new AxeBuilder({ page })
            .withTags(WCAG_AA)
            .analyze();
        expect(results.violations.map((item) => `${url}: ${item.id}`)).toEqual(
            [],
        );
    }

    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/calendario?vista=mes');
    await expect(page.locator('[data-test="team-month"]')).toHaveCount(0);
    await expect(page.locator('[data-test="team-month-list"]')).toBeVisible();
    await expectNoPageScroll(page);

    const mobile = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    expect(mobile.violations.map((item) => item.id)).toEqual([]);
});
