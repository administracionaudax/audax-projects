import AxeBuilder from '@axe-core/playwright';
import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import type { Theme } from './support';
import { login, presetTheme, saveUserTheme, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Planificación de la Fase 4 (agente G2): calendario de tareas (D-061) y dependencias desde el
 * panel de la tarea (D-056, D-062) sobre los datos de ejemplo del DemoDataSeeder:
 * - Clínica Dental Sonrisas · «SON-APP» (precio cerrado, activo) tiene a Elena de miembro,
 *   así que puede crear, mover y enlazar sus tareas.
 * Nunca contra el servidor (playwright.config.ts).
 */

/** "YYYY-MM-DD" de una fecha local. */
function ymd(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

/** "dd/mm/aaaa" de "YYYY-MM-DD" (sin conversión de zona). */
function es(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

/** Id del proyecto SON-APP (se abre desde el listado de proyectos). */
async function openProject(page: Page): Promise<number> {
    await page.goto('/proyectos?buscar=SON-APP');
    await page.getByRole('link', { name: 'App de citas' }).first().click();
    await expect(page).toHaveURL(/\/proyectos\/\d+$/);

    return Number(/\/proyectos\/(\d+)/.exec(page.url())?.[1]);
}

/**
 * Crea una tarea con POST /proyectos/{p}/tareas (lo mismo que la creación rápida), con la cookie
 * XSRF de la sesión.
 */
async function createTask(
    page: Page,
    projectId: number,
    data: Record<string, string>,
): Promise<void> {
    const xsrf = (await page.context().cookies()).find(
        (cookie) => cookie.name === 'XSRF-TOKEN',
    );
    const response = await page.request.post(`/proyectos/${projectId}/tareas`, {
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? ''),
            Accept: 'text/html',
        },
        data,
        maxRedirects: 0,
    });
    expect(response.status(), 'POST /proyectos/{p}/tareas').toBeLessThan(400);
}

test('mover una tarea en el calendario (teclado y arrastre) y verla en la lista con la fecha nueva', async ({
    page,
}) => {
    test.setTimeout(90_000);
    const stamp = Date.now();
    const title = `Calendario E2E ${stamp}`;
    // El día 10 del mes que viene: sin sucesoras, así que no hay diálogo de conflictos.
    const next = new Date();
    next.setDate(1);
    next.setMonth(next.getMonth() + 1);
    const month = ymd(next).slice(0, 7);
    const due = `${month}-10`;

    await login(page, USERS.employee);
    const projectId = await openProject(page);
    await createTask(page, projectId, {
        title,
        start_date: `${month}-08`,
        due_date: due,
    });

    await page.goto(
        `/proyectos/${projectId}/tareas?vista=calendario&mes=${month}`,
    );
    const calendar = page.locator('[data-test="task-calendar"]');
    await expect(calendar).toBeVisible();
    const chip = () =>
        calendar
            .getByRole('button', { name: new RegExp(`^${title}\\.`) })
            .first();

    await test.step('con el teclado: flechas y Enter', async () => {
        await expect(
            calendar.locator(`[data-date="${due}"]`).getByText(title),
        ).toBeVisible();
        await chip().focus();
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowRight');
        await expect(
            calendar.locator('[data-test="calendar-chip-target"]'),
        ).toHaveText(es(`${month}-12`));
        await page.keyboard.press('Enter');

        await expect(
            calendar.locator(`[data-date="${month}-12"]`).getByText(title),
        ).toBeVisible();
    });

    await test.step('arrastrando al día 15', async () => {
        const from = await chip().boundingBox();
        const to = await calendar
            .locator(`[data-date="${month}-15"]`)
            .boundingBox();
        expect(from).not.toBeNull();
        expect(to).not.toBeNull();

        await page.mouse.move(
            (from?.x ?? 0) + 10,
            (from?.y ?? 0) + (from?.height ?? 0) / 2,
        );
        await page.mouse.down();
        await page.mouse.move(
            (to?.x ?? 0) + (to?.width ?? 0) / 2,
            (to?.y ?? 0) + (to?.height ?? 0) / 2,
            { steps: 12 },
        );
        await page.mouse.up();

        await expect(
            calendar.locator(`[data-date="${month}-15"]`).getByText(title),
        ).toBeVisible();
    });

    await test.step('la lista enseña las fechas nuevas (la duración se conserva)', async () => {
        await page.getByRole('radio', { name: 'Lista' }).click();
        const row = page
            .locator('[data-test="task-row"]')
            .filter({ hasText: title });
        await expect(row).toContainText(
            `${es(`${month}-13`)} – ${es(`${month}-15`)}`,
        );
    });
});

test('añadir una dependencia desde el panel y rechazar un ciclo', async ({
    page,
}) => {
    test.setTimeout(90_000);
    const stamp = Date.now();
    const first = `Predecesora E2E ${stamp}`;
    const second = `Sucesora E2E ${stamp}`;

    await login(page, USERS.employee);
    const projectId = await openProject(page);
    await createTask(page, projectId, { title: first });
    await createTask(page, projectId, { title: second });

    const openPanel = async (title: string) => {
        await page.goto(`/proyectos/${projectId}/tareas`);
        await page
            .locator('[data-test="task-title"]')
            .filter({ hasText: title })
            .first()
            .click();
        const panel = page.locator('[data-test="task-panel"]');
        await expect(
            panel.locator('[data-test="task-dependencies"]'),
        ).toBeVisible();

        return panel;
    };

    const addBlocked = async (panel: Locator, title: string) => {
        await panel
            .getByRole('button', { name: 'Añadir una tarea a la que bloquea' })
            .click();
        await page.getByPlaceholder('Busca una tarea del proyecto').fill(title);
        await page
            .locator('[data-test="dependency-candidate"]')
            .filter({ hasText: title })
            .first()
            .click();
    };

    await test.step('«Predecesora» bloquea a «Sucesora»', async () => {
        const panel = await openPanel(first);
        await addBlocked(panel, second);

        await expect(
            panel
                .getByRole('group', { name: 'Bloquea a' })
                .locator('[data-test="dependency-item"]')
                .filter({ hasText: second }),
        ).toBeVisible();
    });

    await test.step('la sucesora ve la dependencia y no puede bloquear a su predecesora (ciclo)', async () => {
        const panel = await openPanel(second);
        await expect(
            panel
                .getByRole('group', { name: 'Depende de' })
                .locator('[data-test="dependency-item"]')
                .filter({ hasText: first }),
        ).toBeVisible();

        await addBlocked(panel, first);

        await expect(
            panel.getByRole('group', { name: 'Bloquea a' }).getByRole('alert'),
        ).toContainText('crearía un ciclo');
        await expect(
            panel
                .getByRole('group', { name: 'Bloquea a' })
                .locator('[data-test="dependency-item"]'),
        ).toHaveCount(0);
    });
});

test('calendario, panel con dependencias y resumen: WCAG 2.1 AA en claro y oscuro y sin scroll horizontal a 375 px', async ({
    page,
    context,
    baseURL,
}) => {
    test.setTimeout(120_000);

    await login(page, USERS.manager);
    const projectId = await openProject(page);
    // Una tarea cualquiera del proyecto: se abre su panel desde la lista y se lee ?tarea=.
    await page.goto(`/proyectos/${projectId}/tareas`);
    await page.locator('[data-test="task-title"]').first().click();
    await expect(page).toHaveURL(/[?&]tarea=\d+/);
    const firstTask = /[?&]tarea=(\d+)/.exec(page.url())?.[1];

    const pages = [
        `/proyectos/${projectId}/tareas?vista=calendario`,
        `/proyectos/${projectId}/tareas?vista=calendario&semana=${ymd(new Date())}`,
        `/proyectos/${projectId}/tareas?vista=calendario&tarea=${firstTask}`,
        `/proyectos/${projectId}`,
        '/',
    ];

    for (const theme of ['light', 'dark'] as Theme[]) {
        await saveUserTheme(page, theme);
        await presetTheme(context, page, theme, baseURL ?? '');

        for (const url of pages) {
            await test.step(`${theme}: ${url}`, async () => {
                await page.goto(url);
                if (url.includes('tarea=')) {
                    await expect(
                        page.locator('[data-test="task-dependencies"]'),
                    ).toBeVisible();
                }

                const results = await new AxeBuilder({ page })
                    .withTags(WCAG_AA)
                    .analyze();
                expect(results.violations.map((item) => item.id)).toEqual([]);
            });
        }
    }

    await page.setViewportSize({ width: 375, height: 812 });
    for (const url of pages) {
        await test.step(`375 px: ${url}`, async () => {
            await page.goto(url);
            const overflow = await page.evaluate(
                () =>
                    document.documentElement.scrollWidth -
                    document.documentElement.clientWidth,
            );
            expect(overflow).toBeLessThanOrEqual(0);
        });
    }
});
