import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Clientes, equipo y estado de proyectos de la Weekly (Fase 10, entrega 10.4) con los datos de
 * ejemplo del DemoDataSeeder: tres semanas cerradas y la en curso, con la weekly de Elena enviada.
 * En la CI la IA es la de prueba (GEMINI_DRIVER=fake, FakeLlm::demo) y la cola es síncrona: los
 * resúmenes salen al momento. Nunca contra el servidor.
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

test('el estado de proyectos: la pestaña de las weeklies, por cliente y en tabla, con filtros', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/weeklies');
    await page.locator('[data-test="weekly-tab-estado-proyectos"]').click();
    await expect(page).toHaveURL(/\/weeklies\/estado-proyectos$/);

    const cards = page.locator('[data-test="project-status-client-card"]');
    await expect(cards.first()).toBeVisible();
    await expect(
        cards
            .filter({ hasText: 'Bodegas Arrieta' })
            .locator('[data-test="project-status-card"]')
            .first(),
    ).toBeVisible();
    await expectAccessible(page, 'estado de proyectos por cliente');

    await page.getByLabel('Tipo', { exact: true }).selectOption('hour_bank');
    for (const kind of await page
        .locator('[data-test="project-kind"]')
        .allTextContents()) {
        expect(kind).toContain('Bolsa de horas');
    }

    await page.getByRole('button', { name: 'Tabla' }).click();
    await expect(
        page.locator('[data-test="project-status-row"]').first(),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Ordenar por Progreso' }).click();
    await expectAccessible(page, 'estado de proyectos en tabla');

    await page.setViewportSize({ width: 375, height: 800 });
    await page.reload();
    await expectNoPageScroll(page);
});

test('la cartera de clientes: último reporte, satisfacción, orden y filtros', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/clientes');

    const table = page.getByRole('table', { name: 'Clientes' });
    await expect(
        table.getByRole('columnheader', { name: /Último reporte/ }),
    ).toBeVisible();
    await expect(
        table.getByRole('columnheader', { name: /Satisfacción/ }),
    ).toBeVisible();

    await page
        .getByRole('button', { name: 'Ordenar por Satisfacción' })
        .click();
    await expect(page).toHaveURL(/orden=satisfaccion/);

    await page.getByLabel('Mis proyectos').check();
    await expect(page).toHaveURL(/mios=1/);
    await expect(
        page.locator('[data-test="client-row"]').first(),
    ).toBeVisible();
    await expectAccessible(page, 'cartera de clientes');
});

test('la ficha de cliente: resumen con IA, historial, equipo con su análisis y satisfacción', async ({
    page,
}) => {
    test.setTimeout(90_000);
    await login(page, USERS.employee);
    await page.goto('/clientes');
    await page.getByRole('link', { name: 'Bodegas Arrieta' }).click();

    await expect(page.locator('[data-test="client-owner"]')).toContainText(
        'Raúl',
    );
    await page
        .locator('[data-test="client-ai-summary"]')
        .getByRole('button', { name: /Generar resumen|Regenerar/ })
        .click();
    await expect(page.locator('[data-test="client-ai-summary"]')).toContainText(
        'GEMINI_DRIVER=fake',
    );

    await page.locator('[data-test="weekly-tab-historial"]').click();
    await expect(
        page.locator('[data-test="client-history-week"]').first(),
    ).toBeVisible();
    await expectAccessible(page, 'historial del cliente');

    await page.locator('[data-test="weekly-tab-equipo"]').click();
    const members = page.locator('[data-test="client-team-member"]');
    await expect(members.first()).toBeVisible();
    await page
        .locator('[data-test="client-team-ai"]')
        .getByRole('button')
        .first()
        .click();
    await expect(
        members.locator('[data-test="client-team-activity"]').first(),
    ).toBeVisible();
    await members.first().locator('[data-test="client-team-history"]').click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');

    await page.locator('[data-test="weekly-tab-satisfaccion"]').click();
    await expect(
        page.locator('[data-test="client-satisfaction-tab"]'),
    ).toBeVisible();
    await expectAccessible(page, 'satisfacción del cliente');

    await page.setViewportSize({ width: 375, height: 800 });
    await page.reload();
    await expectNoPageScroll(page);
});

test('el equipo: estado del reporte y la ficha; los resúmenes con IA solo para sus responsables', async ({
    page,
}) => {
    test.setTimeout(90_000);
    await login(page, USERS.employee);
    await page
        .getByRole('navigation', { name: 'Navegación principal' })
        .getByRole('link', { name: 'Equipo' })
        .click();
    await expect(page).toHaveURL(/\/equipo$/);

    const rows = page.locator('[data-test="team-row"]');
    await expect(rows.first()).toBeVisible();
    await page
        .getByLabel('Estado del reporte', { exact: true })
        .selectOption('submitted');
    await expect(rows.filter({ hasText: 'Elena Empleada' })).toHaveCount(1);
    await expectAccessible(page, 'equipo');

    // Una compañera no ve los resúmenes con IA de otra persona (D-147).
    await page
        .getByLabel('Estado del reporte', { exact: true })
        .selectOption('');
    await rows
        .filter({ hasText: 'Pablo Ruiz' })
        .getByRole('link', { name: 'Pablo Ruiz' })
        .click();
    await expect(page.locator('[data-test="person-habits"]')).toBeVisible();
    await expect(
        page.locator('[data-test="person-ai-performance"]'),
    ).toHaveCount(0);

    // Su responsable (Raúl, de Diseño) sí ve y genera los de Elena.
    await page.context().clearCookies();
    await login(page, USERS.manager);
    await page.goto('/equipo');
    await page
        .locator('[data-test="team-row"]')
        .filter({ hasText: 'Elena Empleada' })
        .getByRole('link', { name: 'Elena Empleada' })
        .click();
    const performance = page.locator('[data-test="person-ai-performance"]');
    await expect(performance).toBeVisible();
    await performance.getByRole('button').click();
    await expect(performance).toContainText('GEMINI_DRIVER=fake');
    await expectAccessible(page, 'ficha de persona');

    await page.setViewportSize({ width: 375, height: 800 });
    await page.reload();
    await expectNoPageScroll(page);
});
