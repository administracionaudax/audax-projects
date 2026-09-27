import AxeBuilder from '@axe-core/playwright';
import type { Browser, Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import type { Theme } from './support';
import { login, presetTheme, saveUserTheme, USERS } from './support';

/**
 * Gantt de la Fase 4 (SPEC §6.1 y §17, D-056, D-057, D-060) sobre los datos del DemoDataSeeder:
 * - «LAM-INT · Intranet de obra» (Construcciones Lamas, por horas y sin bolsas), con Pablo, Sergio y
 *   Lucía de miembros; Elena (empleada de Diseño) no es miembro y lo ve en solo lectura,
 * - Raúl (responsable) gestiona cualquier proyecto (D-031), así que mueve y enlaza sus tareas.
 * Cada test crea sus propias tareas (con un sello único) en el mes en curso, así que no depende del
 * orden ni de otras ejecuciones. Nunca contra el servidor (playwright.config.ts).
 */

const PROJECT_CODE = 'LAM-INT';
const PROJECT_NAME = 'Intranet de obra';
const CLIENT_NAME = 'Construcciones Lamas';
const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const MOBILE = { width: 375, height: 812 };

/** Píxeles de un día en la escala por defecto (semana, geometry.ts DAY_WIDTH). */
const WEEK_DAY_WIDTH = 16;

/** Mes en curso en Madrid: el que abre el calendario del selector de fechas. */
function currentMonth(): { year: string; month: string } {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Madrid',
        year: 'numeric',
        month: '2-digit',
    }).formatToParts(new Date());

    return {
        year: parts.find((part) => part.type === 'year')?.value ?? '',
        month: parts.find((part) => part.type === 'month')?.value ?? '',
    };
}

/**
 * Día del mes en curso como lo enseña la app (formatDate): "13/10/2026". Se usan días del 10 al
 * 17, que nunca salen como días del mes anterior o siguiente en el calendario.
 */
function shown(day: number): string {
    const { year, month } = currentMonth();

    return `${String(day).padStart(2, '0')}/${month}/${year}`;
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Barra (o rombo) de una tarea: su nombre accesible empieza por el título y sigue con las fechas. */
function bar(page: Page, title: string): Locator {
    return page.getByRole('button', {
        name: new RegExp(`^${escapeRegExp(title)}\\. `),
    });
}

function scroller(page: Page): Locator {
    return page.locator('[data-test="gantt-scroll"]');
}

/**
 * El diagrama solo pinta las filas cercanas a la vista (virtualización): baja por él hasta que
 * aparece la barra de la tarea.
 */
async function reveal(page: Page, target: Locator): Promise<void> {
    await scroller(page).evaluate((element) => {
        element.scrollTop = 0;
    });

    for (let step = 0; step < 40 && (await target.count()) === 0; step++) {
        await scroller(page).evaluate((element) => {
            element.scrollTop += element.clientHeight / 2;
        });
        await page.waitForTimeout(50);
    }

    await expect(target).toHaveCount(1);
}

async function openProjectGantt(page: Page): Promise<void> {
    await page.goto(`/proyectos?buscar=${PROJECT_CODE}`);
    await page.getByRole('link', { name: PROJECT_NAME }).first().click();
    await page
        .getByRole('navigation', { name: 'Secciones del proyecto' })
        .getByRole('link', { name: 'Gantt' })
        .click();
    await expect(page).toHaveURL(/\/proyectos\/\d+\/gantt$/);
    await expect(
        page.getByRole('region', {
            name: `Diagrama de Gantt de «${PROJECT_NAME}»`,
        }),
    ).toBeVisible();
}

async function pickDay(
    page: Page,
    dialog: Locator,
    label: string,
    day: number,
): Promise<void> {
    await dialog.getByLabel(label, { exact: true }).click();
    const calendar = page.getByRole('grid');
    await calendar
        .locator('button')
        .filter({ hasText: new RegExp(`^${day}$`) })
        .click();
    await expect(calendar).toBeHidden();
}

/** «Nueva tarea» del Gantt con inicio y entrega en el mes en curso (tasks.store, TaskWriter). */
async function createTask(
    page: Page,
    title: string,
    start: number,
    due: number,
): Promise<void> {
    await page.locator('[data-test="gantt-new-task"]').click();
    const dialog = page.getByRole('dialog', { name: 'Nueva tarea' });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Título', { exact: true }).fill(title);
    await pickDay(page, dialog, 'Inicio', start);
    await pickDay(page, dialog, 'Entrega', due);
    await dialog.getByRole('button', { name: 'Crear tarea' }).click();
    await expect(dialog).toBeHidden();

    const created = bar(page, title);
    await reveal(page, created);
    await expect(created).toHaveAccessibleName(
        new RegExp(
            `Del ${escapeRegExp(shown(start))} al ${escapeRegExp(shown(due))}`,
        ),
    );
}

/** «Añadir dependencia…» desde el menú «Más» de la tarea (alternativa al conector). */
async function openDependencyDialog(
    page: Page,
    title: string,
): Promise<Locator> {
    await reveal(page, bar(page, title));
    await page
        .getByRole('button', { name: `Más opciones de «${title}»` })
        .click();
    await page.getByRole('menuitem', { name: 'Añadir dependencia…' }).click();
    const dialog = page.getByRole('dialog', { name: 'Añadir dependencia' });
    await expect(dialog).toBeVisible();

    return dialog;
}

/** En el diálogo de `title`: «`title` va después de `other`» (other es su predecesora). */
async function chooseAfter(dialog: Locator, title: string, other: string) {
    await dialog
        .getByLabel(`«${title}» va después de la tarea que elijas`, {
            exact: true,
        })
        .click();
    await dialog.getByPlaceholder('Busca una tarea').fill(other);
    await dialog
        .getByRole('option', { name: new RegExp(escapeRegExp(other)) })
        .click();
    await dialog.getByRole('button', { name: 'Añadir dependencia' }).click();
}

/** Enlaza fin → inicio: `successor` va después de `predecessor`. */
async function link(
    page: Page,
    predecessor: string,
    successor: string,
): Promise<void> {
    const dialog = await openDependencyDialog(page, successor);
    await chooseAfter(dialog, successor, predecessor);
    await expect(dialog).toBeHidden();
    await expect(
        page.locator(
            `[data-test="gantt-unlink"][aria-label="Quitar la dependencia «${predecessor}» → «${successor}»"]`,
        ),
    ).toHaveCount(1);
}

/** Deja la barra a la vista, lejos de la columna de títulos y de la cabecera pegajosas. */
async function bringIntoView(page: Page, target: Locator): Promise<void> {
    await scroller(page).evaluate((element) =>
        element.scrollIntoView({ block: 'start' }),
    );
    const position = await target.evaluate((element) => ({
        left: (element as HTMLElement).offsetLeft,
        top: (element as HTMLElement).offsetTop,
    }));
    await scroller(page).evaluate((element, { left, top }) => {
        element.scrollLeft = Math.max(left - 160, 0);
        element.scrollTop = Math.max(top - 72, 0);
    }, position);
    await expect(target).toBeVisible();
}

async function asUser(
    browser: Browser,
    baseURL: string,
    email: string,
    theme?: Theme,
    viewport?: { width: number; height: number },
): Promise<Page> {
    const context = await browser.newContext({
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
        ...(viewport ? { viewport } : {}),
    });
    const page = await context.newPage();

    if (theme) {
        await presetTheme(context, page, theme, baseURL);
    }

    await login(page, email);

    if (theme) {
        await saveUserTheme(page, theme);
    }

    return page;
}

test('mover una tarea con sucesora: aviso, confirmar y la sucesora se desplaza también en la lista de tareas', async ({
    page,
}) => {
    test.setTimeout(120_000);
    const stamp = Date.now();
    const first = `Maquetar E2E ${stamp}`;
    const second = `Publicar E2E ${stamp}`;

    await login(page, USERS.manager);
    await openProjectGantt(page);

    await test.step('crear dos tareas con fechas desde el Gantt', async () => {
        await createTask(page, first, 10, 12);
        await createTask(page, second, 13, 14);
    });

    await test.step('enlazar fin → inicio desde «Añadir dependencia…»', async () => {
        await link(page, first, second);
    });

    const conflict = page.getByRole('dialog', {
        name: 'Hay tareas que dependen de esta',
    });

    await test.step('arrastrar con el ratón pide la propuesta; «Cancelar» la deja en su sitio', async () => {
        const predecessor = bar(page, first);
        await bringIntoView(page, predecessor);
        const box = await predecessor.boundingBox();
        expect(box).not.toBeNull();

        if (box) {
            const x = box.x + box.width / 2;
            const y = box.y + box.height / 2;
            await page.mouse.move(x, y);
            await page.mouse.down();
            await page.mouse.move(x + WEEK_DAY_WIDTH * 3, y, { steps: 8 });
            await page.mouse.up();
        }

        await expect(conflict).toBeVisible();
        const row = conflict.getByRole('row', {
            name: new RegExp(escapeRegExp(second)),
        });
        await expect(row).toContainText(`${shown(13)} – ${shown(14)}`);
        await expect(row).toContainText(`${shown(16)} – ${shown(17)}`);
        await expect(row).toContainText('(+3 días)');

        await conflict.getByRole('button', { name: 'Cancelar' }).click();
        await expect(conflict).toBeHidden();
        await expect(bar(page, first)).toHaveAccessibleName(
            new RegExp(
                `Del ${escapeRegExp(shown(10))} al ${escapeRegExp(shown(12))}`,
            ),
        );
    });

    await test.step('mover con el teclado e Intro: aviso con la propuesta y «Mover también las sucesoras»', async () => {
        const predecessor = bar(page, first);
        await predecessor.focus();
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Enter');

        await expect(conflict).toBeVisible();
        await expect(conflict).toContainText(
            `Con las nuevas fechas de «${first}» (Del ${shown(11)} al ${shown(13)})`,
        );
        const row = conflict.getByRole('row', {
            name: new RegExp(escapeRegExp(second)),
        });
        await expect(row).toContainText(`${shown(13)} – ${shown(14)}`);
        await expect(row).toContainText(`${shown(14)} – ${shown(15)}`);
        await expect(row).toContainText('(+1 día)');

        await conflict
            .getByRole('button', { name: 'Mover también las sucesoras' })
            .click();
        await expect(conflict).toBeHidden();
        await expect(
            page.getByText(
                'Fechas actualizadas y 1 tarea sucesora desplazada.',
            ),
        ).toBeVisible();
    });

    await test.step('las dos tareas quedan en sus fechas nuevas y sin conflicto', async () => {
        await expect(bar(page, first)).toHaveAccessibleName(
            new RegExp(
                `Del ${escapeRegExp(shown(11))} al ${escapeRegExp(shown(13))}`,
            ),
        );
        await expect(bar(page, second)).toHaveAccessibleName(
            new RegExp(
                `Del ${escapeRegExp(shown(14))} al ${escapeRegExp(shown(15))}`,
            ),
        );
        await expect(bar(page, second)).not.toHaveAccessibleName(
            /En conflicto/,
        );
    });

    await test.step('la lista de tareas lo refleja', async () => {
        await page
            .getByRole('navigation', { name: 'Secciones del proyecto' })
            .getByRole('link', { name: 'Tareas' })
            .click();
        const successorRow = page
            .locator('[data-test="task-row"]')
            .filter({ hasText: second });
        await expect(successorRow).toContainText(`${shown(14)} – ${shown(15)}`);
        const predecessorRow = page
            .locator('[data-test="task-row"]')
            .filter({ hasText: first });
        await expect(predecessorRow).toContainText(
            `${shown(11)} – ${shown(13)}`,
        );
    });
});

test('enlazar creando un ciclo se rechaza con el mensaje del servidor', async ({
    page,
}) => {
    test.setTimeout(90_000);
    const stamp = Date.now();
    const first = `Briefing E2E ${stamp}`;
    const second = `Entrega E2E ${stamp}`;

    await login(page, USERS.manager);
    await openProjectGantt(page);
    await createTask(page, first, 10, 11);
    await createTask(page, second, 12, 13);
    await link(page, first, second);

    const dialog = await openDependencyDialog(page, first);
    await chooseAfter(dialog, first, second);

    await expect(dialog.getByRole('alert')).toHaveText(
        'Esa dependencia crearía un ciclo: la tarea ya depende, directa o indirectamente, de la otra.',
    );
    await dialog.getByRole('button', { name: 'Cancelar' }).click();
    await expect(dialog).toBeHidden();

    // Solo sigue la dependencia de antes.
    await expect(
        page.locator(
            `[data-test="gantt-unlink"][aria-label="Quitar la dependencia «${second}» → «${first}»"]`,
        ),
    ).toHaveCount(0);
    await expect(
        page.locator(
            `[data-test="gantt-unlink"][aria-label="Quitar la dependencia «${first}» → «${second}»"]`,
        ),
    ).toHaveCount(1);
});

test('quien no es miembro del proyecto ve el Gantt en solo lectura', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await openProjectGantt(page);

    await expect(page.locator('[data-test="gantt-new-task"]')).toHaveCount(0);
    const firstBar = page.locator('[data-test="gantt-bar"]').first();
    await expect(firstBar).toHaveAccessibleName(/Solo lectura/);
    await expect(page.locator('[data-test="gantt-connector"]')).toHaveCount(0);
});

test('Gantt multiproyecto: se llega desde Proyectos, filtra en la URL y agrupa por proyecto', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/proyectos');
    await page.getByRole('link', { name: 'Ver el Gantt' }).click();
    await expect(page).toHaveURL(/\/gantt$/);
    await expect(
        page.getByRole('region', {
            name: 'Diagrama de Gantt de los proyectos filtrados',
        }),
    ).toBeVisible();

    await page.getByRole('combobox', { name: 'Cliente' }).click();
    await page.getByRole('option', { name: CLIENT_NAME }).click();
    await expect(page).toHaveURL(/\/gantt\?cliente=\d+$/);

    const projectLink = page.getByRole('link', { name: PROJECT_NAME });
    await expect(projectLink).toHaveAttribute(
        'href',
        /\/proyectos\/\d+\/gantt$/,
    );

    const toggle = page.getByRole('button', {
        name: `Plegar «${PROJECT_NAME}»`,
    });
    await toggle.click();
    await expect(
        page.getByRole('button', { name: `Desplegar «${PROJECT_NAME}»` }),
    ).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('[data-test="gantt-bar"]')).toHaveCount(0);

    await page.getByRole('radio', { name: 'Mes' }).click();
    await expect(page).toHaveURL(/\/gantt\?cliente=\d+&escala=mes$/);

    await page.getByRole('radio', { name: 'Tabla' }).click();
    await expect(
        page.getByRole('table', {
            name: 'Diagrama de Gantt de los proyectos filtrados',
        }),
    ).toBeVisible();
});

test('Gantt multiproyecto: «Nueva tarea» en el proyecto elegido, «Sin fechas» por proyecto y el foco sigue a la tarea', async ({
    page,
}) => {
    test.setTimeout(90_000);
    const title = `Revisar E2E ${Date.now()}`;
    const group = `${PROJECT_CODE} · ${PROJECT_NAME}`;

    await login(page, USERS.manager);
    await page.goto('/gantt');
    await page.getByRole('combobox', { name: 'Cliente' }).click();
    await page.getByRole('option', { name: CLIENT_NAME }).click();
    await expect(page).toHaveURL(/\/gantt\?cliente=\d+$/);

    await test.step('«Nueva tarea» sin fechas en el proyecto elegido', async () => {
        await page.locator('[data-test="gantt-new-task"]').click();
        const dialog = page.getByRole('dialog', { name: 'Nueva tarea' });
        await expect(dialog).toBeVisible();
        // Con un solo proyecto a la vista (el cliente filtrado tiene uno), el diálogo lo elige solo.
        const picker = dialog.getByRole('combobox', { name: 'Proyecto' });
        if ((await picker.count()) > 0) {
            await picker.click();
            await page.getByRole('option', { name: group }).click();
        }
        await dialog.getByLabel('Título', { exact: true }).fill(title);
        await dialog.getByRole('button', { name: 'Crear tarea' }).click();
        await expect(dialog).toBeHidden();
    });

    const unscheduled = page.locator('[data-test="gantt-unscheduled"]');
    const assign = unscheduled.getByRole('button', {
        name: `Asignar fechas a «${title}»`,
    });

    await test.step('sale en «Sin fechas», dentro de su proyecto', async () => {
        await expect(
            unscheduled
                .getByRole('list', {
                    name: new RegExp(`^${escapeRegExp(group)} \\(\\d+\\)$`),
                })
                .getByText(title),
        ).toBeVisible();
    });

    await test.step('«Asignar fechas»: pasa al diagrama y el foco va a su barra', async () => {
        await assign.click();
        const dialog = page.getByRole('dialog', { name: 'Asignar fechas' });
        await expect(dialog).toBeVisible();
        await pickDay(page, dialog, 'Inicio', 10);
        await pickDay(page, dialog, 'Entrega', 12);
        await dialog.getByRole('button', { name: 'Guardar fechas' }).click();
        await expect(dialog).toBeHidden();

        const created = bar(page, title);
        await expect(created).toBeFocused();
        await expect(created).toHaveAccessibleName(
            new RegExp(
                `Del ${escapeRegExp(shown(10))} al ${escapeRegExp(shown(12))}`,
            ),
        );
        await expect(assign).toHaveCount(0);
    });

    await test.step('«Quitar fechas»: vuelve a «Sin fechas» y el foco la sigue', async () => {
        await page.keyboard.press('Shift+F10');
        await page.getByRole('menuitem', { name: 'Quitar fechas' }).click();
        await expect(assign).toBeFocused();
        await expect(bar(page, title)).toHaveCount(0);
    });
});

for (const theme of ['light', 'dark'] as const) {
    test(`las páginas del Gantt no tienen violaciones de axe (tema ${theme === 'light' ? 'claro' : 'oscuro'})`, async ({
        browser,
        baseURL,
    }) => {
        const page = await asUser(browser, baseURL ?? '', USERS.manager, theme);

        try {
            await openProjectGantt(page);

            for (const url of [page.url(), '/gantt']) {
                await page.goto(url);
                await expect(
                    page.locator('[data-test="gantt-bar"]').first(),
                ).toBeVisible();
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
            }
        } finally {
            await page.context().close();
        }
    });
}

test('a 375 px la página no tiene scroll horizontal: el Gantt se desplaza en su propio contenedor', async ({
    browser,
    baseURL,
}) => {
    const page = await asUser(
        browser,
        baseURL ?? '',
        USERS.manager,
        undefined,
        MOBILE,
    );

    try {
        await openProjectGantt(page);

        for (const url of [page.url(), '/gantt']) {
            await page.goto(url);
            await expect(scroller(page)).toBeVisible();

            const sizes = await page.evaluate(() => ({
                page: document.documentElement.scrollWidth,
                viewport: document.documentElement.clientWidth,
            }));
            expect(sizes.page, url).toBeLessThanOrEqual(sizes.viewport);

            const own = await scroller(page).evaluate((element) => ({
                scrollWidth: element.scrollWidth,
                clientWidth: element.clientWidth,
                right: element.getBoundingClientRect().right,
            }));
            expect(own.scrollWidth, url).toBeGreaterThan(own.clientWidth);
            expect(own.right, url).toBeLessThanOrEqual(sizes.viewport + 1);
        }
    } finally {
        await page.context().close();
    }
});
