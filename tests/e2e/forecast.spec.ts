import AxeBuilder from '@axe-core/playwright';
import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import type { Theme } from './support';
import {
    expectTheme,
    login,
    openNavSection,
    presetTheme,
    saveUserTheme,
    USERS,
} from './support';

/*
 * Pantallas de la Previsión (D-290 a D-308). El módulo `forecast` viene apagado: el primer test lo
 * enciende en /admin/ajustes y el último lo vuelve a apagar, para no cambiar la barra lateral de los
 * demás specs. Datos del DemoDataSeeder: Raúl (responsable de Diseño) ve la previsión; Elena y
 * Lucía son de Diseño.
 */

test.describe.configure({ mode: 'serial' });
test.use({ testIdAttribute: 'data-test' });

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const RUN = Date.now().toString(36).slice(-5);
const FORECAST = `Portal de socios ${RUN}`;
const CLIENT = `Cooperativa E2E ${RUN}`;

async function setForecastModule(page: Page, on: boolean): Promise<void> {
    await login(page, USERS.admin);
    await page.goto('/admin/ajustes');
    const toggle = page.getByRole('switch', { name: 'Previsión', exact: true });

    if ((await toggle.getAttribute('aria-checked')) !== String(on)) {
        await toggle.click();
    }

    await expect(toggle).toHaveAttribute('aria-checked', String(on));
    await page.getByRole('button', { name: 'Guardar los ajustes' }).click();
    await expect(
        page.getByText('Ajustes guardados', { exact: false }).first(),
    ).toBeVisible();
}

async function expectNoSeriousViolations(
    page: Page,
    label: string,
): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    const serious = results.violations.filter(
        (v) => v.impact === 'serious' || v.impact === 'critical',
    );

    expect(
        serious,
        `${label}\n${serious
            .map(
                (v) =>
                    `${v.id}: ${v.help}\n  ${v.nodes
                        .map((n) => n.target.join(' '))
                        .slice(0, 5)
                        .join('\n  ')}`,
            )
            .join('\n')}`,
    ).toEqual([]);
}

/** Elige un día del mes que viene en un selector de fechas. */
async function pickNextMonthDay(
    page: Page,
    trigger: Locator,
    day: number,
): Promise<void> {
    await trigger.click();
    const popover = page.locator('[data-slot="popover-content"]').last();
    await popover.getByRole('button', { name: /siguiente/i }).click();
    await popover
        .getByRole('grid')
        .locator('button')
        .filter({ hasText: new RegExp(`^${day}$`) })
        .first()
        .click();
    await expect(popover).toBeHidden();
}

test('el admin enciende el módulo de la previsión', async ({ page }) => {
    await setForecastModule(page, true);
});

test('la matriz: entrada en la barra lateral, cifras, capas, teclado y tooltip', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/');
    await openNavSection(page, 'Proyectos');
    await page
        .getByRole('navigation', { name: 'Navegación principal' })
        .getByRole('link', { name: 'Previsión' })
        .click();

    await expect(page).toHaveURL(/\/prevision$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
        'Previsión del equipo',
    );
    await expect(
        page.getByTestId('forecast-figures').getByTestId('forecast-stat'),
    ).toHaveCount(4);
    const grid = page.getByRole('grid');
    await expect(grid.getByTestId('forecast-group-row').first()).toBeVisible();
    await expect(grid.getByText('Colaboradores externos')).toBeVisible();

    // Una sola parada de tabulación; con el foco, el tooltip dice de dónde sale cada hora.
    await expect(grid.locator('[tabindex="0"]')).toHaveCount(1);
    await grid.locator('[tabindex="0"]').focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('tooltip')).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('forecast-cell-panel')).toBeVisible();
    await page.keyboard.press('Escape');

    // Las capas son filtro: sin «Previsto posible», la URL lo recuerda.
    await page.getByTestId('layer-tentative').click();
    await expect(page).toHaveURL(/capas=real%2Cseguro|capas=real,seguro/);
    await expect(page.getByTestId('layer-tentative')).toHaveAttribute(
        'aria-pressed',
        'false',
    );

    // «Ver como tabla», la alternativa de la matriz.
    await page.getByRole('button', { name: 'Ver como tabla' }).click();
    await expect(
        page.getByRole('region', {
            name: 'Ocupación por departamento y persona (tabla)',
        }),
    ).toBeVisible();
});

test('«Nuevo proyecto previsto» desde la Previsión carga los clientes (prop diferida)', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/prevision');
    await page.getByRole('link', { name: 'Nuevo proyecto previsto' }).click();
    const form = page.getByTestId('forecast-project-form');
    // El selector nativo de clientes tiene sus opciones (no se queda en «Cargando…»).
    await expect
        .poll(() => form.locator('select option').count())
        .toBeGreaterThan(1);
    await expect(form.getByText('Cargando…')).toHaveCount(0);
    await expect(page).toHaveURL(/\/prevision\/proyectos$/);
});

test('crear un previsto con un hueco y una persona, ver el impacto, asignar el hueco, crear el proyecto real y ver la Planificación', async ({
    page,
}) => {
    test.setTimeout(90_000);
    await login(page, USERS.manager);

    await test.step('nuevo proyecto previsto de un cliente nuevo', async () => {
        await page.goto('/prevision/proyectos');
        await page
            .getByRole('button', { name: 'Nuevo proyecto previsto' })
            .click();
        const form = page.getByTestId('forecast-project-form');
        await form.getByLabel('Nombre').fill(FORECAST);
        await form.getByRole('radio', { name: 'Un cliente nuevo' }).click();
        await form.getByLabel('Nombre del cliente nuevo').fill(CLIENT);
        await pickNextMonthDay(page, form.getByLabel('Inicio'), 3);
        await pickNextMonthDay(page, form.getByLabel('Fin'), 24);
        await form.getByRole('button', { name: 'Crear previsto' }).click();
        await expect(page).toHaveURL(/\/prevision\/proyectos\/\d+$/);
        await expect(page.getByRole('heading', { level: 1 })).toContainText(
            FORECAST,
        );
    });

    await test.step('un hueco de Diseño y una persona', async () => {
        await page.getByTestId('add-allocation').click();
        let form = page.getByTestId('allocation-form');
        await form
            .getByRole('radio', { name: 'Un hueco de un departamento' })
            .click();
        await form
            .getByTestId('allocation-department')
            .selectOption({ label: 'Diseño' });
        await form.getByLabel('Horas (en total)', { exact: true }).fill('40');
        await form.getByRole('button', { name: 'Añadir asignación' }).click();
        await expect(form).toBeHidden();
        await expect(page.getByTestId('allocation-row')).toHaveCount(1);

        await page.getByTestId('add-allocation').click();
        form = page.getByTestId('allocation-form');
        await form
            .getByTestId('allocation-person')
            .selectOption({ label: 'Lucía Martín' });
        await form.getByLabel('Horas (en total)', { exact: true }).fill('20');
        await form.getByRole('button', { name: 'Añadir asignación' }).click();
        await expect(form).toBeHidden();
        await expect(page.getByTestId('allocation-row')).toHaveCount(2);
    });

    await test.step('el impacto «sin / con»', async () => {
        await expect(page.getByTestId('impact-sentence')).toContainText(
            'Si se coge,',
        );
        const rows = page.getByTestId('impact-row');
        await expect(rows.filter({ hasText: 'Diseño' })).toHaveCount(1);
        await expect(rows.filter({ hasText: 'Lucía Martín' })).toHaveCount(1);
    });

    await test.step('asignar el hueco a una persona con su ocupación', async () => {
        await page
            .getByRole('button', { name: /Asignar a…/ })
            .first()
            .click();
        const dialog = page.getByTestId('assign-gap-dialog');
        await dialog.getByRole('combobox').click();
        await page.getByRole('option', { name: /Elena Empleada/ }).click();
        await dialog
            .getByRole('button', { name: 'Asignar', exact: true })
            .click();
        await expect(dialog).toBeHidden();
        await expect(
            page.getByText('Asignada a Elena Empleada').first(),
        ).toBeVisible();
        await expect(
            page.getByTestId('allocations-table').getByText('Sin persona'),
        ).toHaveCount(0);
    });

    await test.step('crear el proyecto real (con el cliente nuevo) y ver su Planificación', async () => {
        await page.getByTestId('create-project').click();
        const form = page.getByTestId('create-project-form');
        await expect(
            form.getByRole('checkbox', {
                name: `Crear el cliente «${CLIENT}»`,
            }),
        ).toBeChecked();
        await form.getByRole('button', { name: 'Crear proyecto real' }).click();
        await expect(page).toHaveURL(/\/proyectos\/\d+$/);

        await page
            .getByRole('navigation', { name: 'Secciones del proyecto' })
            .getByRole('link', { name: 'Planificación' })
            .click();
        await expect(page).toHaveURL(/\/proyectos\/\d+\/planificacion$/);
        await expect(page.getByTestId('planning-origin')).toContainText(
            FORECAST,
        );
        await expect(
            page
                .getByTestId('planning-row')
                .filter({ hasText: 'Elena Empleada' }),
        ).toHaveCount(1);
        await expect(
            page
                .getByTestId('planning-row')
                .filter({ hasText: 'Lucía Martín' }),
        ).toHaveCount(1);
    });
});

for (const theme of ['light', 'dark'] as const satisfies readonly Theme[]) {
    test(`/prevision y la ficha sin violaciones AA graves en tema ${theme === 'light' ? 'claro' : 'oscuro'}`, async ({
        page,
        context,
        baseURL,
    }) => {
        await presetTheme(
            context,
            page,
            theme,
            baseURL ?? 'http://127.0.0.1:8000',
        );
        await login(page, USERS.manager);
        await saveUserTheme(page, theme);

        await page.goto('/prevision');
        await page.waitForLoadState('networkidle');
        await expectTheme(page, theme);
        await expect(page.getByTestId('forecast-gap').first()).toBeVisible();
        await expectNoSeriousViolations(page, `/prevision (${theme})`);

        await page.goto('/prevision/proyectos');
        await page.getByRole('link', { name: 'Web y branding' }).click();
        await page.waitForLoadState('networkidle');
        await expect(page.getByTestId('impact-grid')).toBeVisible();
        await expectNoSeriousViolations(page, `ficha del previsto (${theme})`);
        await saveUserTheme(page, 'light');
    });
}

test('en el móvil (375 px), sin scroll horizontal de la página: la matriz se desplaza dentro de su caja', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.manager);

    for (const path of ['/prevision', '/prevision/proyectos']) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );
        expect(overflow, path).toBeLessThanOrEqual(0);
    }

    const box = page.getByTestId('forecast-matrix');
    await page.goto('/prevision');
    expect(
        await box.evaluate(
            (element) => element.scrollWidth > element.clientWidth,
        ),
    ).toBe(true);
});

test('Mi carga del empleado: en Inicio y en /carga, sus asignaciones', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/');
    await expect(page.getByTestId('my-forecast')).toBeVisible();
    await expect(page.getByTestId('my-forecast')).toContainText(
        'Próximas 12 semanas',
    );

    await page.goto('/carga');
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
        'Mi carga',
    );
    await expect(page.getByTestId('my-forecast-view')).toBeVisible();
    await expect(page.getByTestId('my-allocations')).toContainText(
        'Rediseño web',
    );

    // Un empleado no ve la previsión global (P4).
    const response = await page.goto('/prevision');
    expect(response?.status()).toBe(403);
});

test('el admin vuelve a apagar el módulo', async ({ page }) => {
    await setForecastModule(page, false);
});
