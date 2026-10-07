import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/*
 * Registro de jornada (Fase 11, R1; D-330 a D-345). El módulo `people` viene apagado: el primer test lo
 * enciende en /admin/ajustes y el último lo vuelve a apagar, para no cambiar la barra lateral de los
 * demás specs. Datos del DemoDataSeeder: Elena (empleado@example.com) aún no ha fichado hoy y Raúl
 * (responsable@example.com) es su responsable. Nunca contra el servidor (playwright.config.ts).
 */

test.describe.configure({ mode: 'serial' });
test.use({ testIdAttribute: 'data-test' });

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const RUN = Date.now().toString(36).slice(-5);
const REASON = `Salí más tarde por la entrega (E2E ${RUN})`;

async function setPeopleModule(page: Page, on: boolean): Promise<void> {
    await login(page, USERS.admin);
    await page.goto('/admin/ajustes');
    const toggle = page.getByRole('switch', {
        name: 'Personas (registro de jornada)',
        exact: true,
    });

    if ((await toggle.getAttribute('aria-checked')) !== String(on)) {
        await toggle.click();
    }

    await expect(toggle).toHaveAttribute('aria-checked', String(on));
    await page.getByRole('button', { name: 'Guardar los ajustes' }).click();
    await expect(
        page.getByText('Ajustes guardados', { exact: false }).first(),
    ).toBeVisible();
}

async function expectAccessible(page: Page): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    const serious = results.violations.filter((violation) =>
        ['serious', 'critical'].includes(violation.impact ?? ''),
    );

    expect(
        serious.map(
            (violation) =>
                `${violation.id}: ${violation.nodes
                    .map((node) => node.target.join(' '))
                    .slice(0, 3)
                    .join(' | ')}`,
        ),
    ).toEqual([]);
}

test('se enciende el módulo Personas', async ({ page }) => {
    await setPeopleModule(page, true);
});

test('fichar la entrada, la comida, la vuelta y la salida desde la cabecera', async ({
    page,
}) => {
    test.setTimeout(60_000);
    await login(page, USERS.employee);
    await page.goto('/personas/jornada');

    const button = page.getByTestId('clock-button');
    // Sin jornada hoy (o cerrada, si el spec ya ha pasado antes contra la misma base).
    await expect(button).toHaveAttribute('data-status', /^(off|closed)$/);

    await test.step('entrar', async () => {
        if ((await button.getAttribute('data-status')) === 'closed') {
            // Jornada partida: volver a entrar desde el menú.
            await page.getByTestId('clock-menu').click();
            await page.getByTestId('clock-mode-on_site').click();
        } else {
            await page.getByTestId('clock-in').click();
        }
        await expect(button).toHaveAttribute('data-status', 'working');
        await expect(
            page.getByText(/Entrada fichada a las/).first(),
        ).toBeVisible();
    });

    await test.step('la comida y la vuelta', async () => {
        await page.getByTestId('clock-pause').click();
        await expect(button).toHaveAttribute('data-status', 'paused');
        await page.getByTestId('clock-back').click();
        await expect(button).toHaveAttribute('data-status', 'working');
    });

    await test.step('salir', async () => {
        await page.getByTestId('clock-out').click();
        await expect(button).toHaveAttribute('data-status', 'closed');
        await expect(
            page.getByText(/Salida fichada a las/).first(),
        ).toBeVisible();
    });

    await test.step('el día de hoy tiene sus cuatro fichajes y su historial', async () => {
        await page.reload();
        const today = page.getByTestId('diary-row').first();
        await today.getByTestId('diary-open').click();
        const detail = page.getByTestId('day-detail');
        await expect(detail).toBeVisible();
        // Cuatro fichajes (ocho si el spec ya ha pasado antes contra la misma base: jornada partida).
        expect(
            await detail.getByTestId('day-events').locator('li').count(),
        ).toBeGreaterThanOrEqual(4);
        expect(
            await detail.getByTestId('history-row').count(),
        ).toBeGreaterThanOrEqual(4);
        await expectAccessible(page);
        await page.keyboard.press('Escape');
    });
});

test('proponer una corrección de un día pasado', async ({ page }) => {
    await login(page, USERS.employee);
    await page.goto('/personas/jornada');

    const day = page
        .locator('[data-test="diary-row"][data-status="ok"]')
        .filter({ hasNotText: '19:15' })
        .nth(1);
    const date = await day.getAttribute('data-date');
    await day.getByTestId('diary-open').click();
    await expect(page).toHaveURL(new RegExp(`dia=${date}`));

    await page.getByTestId('day-propose').click();
    const dialog = page.getByRole('dialog').last();
    const times = dialog.getByTestId('correction-time');
    await times.last().fill('19:15');
    await dialog.getByTestId('correction-reason').fill(REASON);
    await expect(dialog.getByText('2 cambios')).toBeVisible();
    await dialog.getByTestId('correction-submit').click();

    await expect(page.getByText('Corrección propuesta').first()).toBeVisible();
    await expect(
        page.getByTestId('day-detail').getByTestId('correction'),
    ).toContainText(REASON);
    await expect(
        page.getByTestId('day-detail').getByTestId('correction-status').first(),
    ).toContainText('Pendiente');

    // Ella no puede aceptarla: la valida su responsable.
    await expect(
        page.getByTestId('day-detail').getByTestId('correction-accept'),
    ).toHaveCount(0);
});

test('su responsable la valida desde Pendientes y queda en el historial', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/personas/pendientes');

    const card = page.getByTestId('correction').filter({ hasText: REASON });
    await expect(card).toBeVisible();
    await expectAccessible(page);
    await card.getByTestId('correction-accept').click();
    await expect(page.getByText('Corrección aceptada').first()).toBeVisible();
    await expect(
        page.getByTestId('correction').filter({ hasText: REASON }),
    ).toHaveCount(0);

    await page.goto('/personas/equipo');
    await expect(page.getByTestId('team-workday')).toBeVisible();
    await expect(
        page
            .getByTestId('team-row')
            .filter({ hasText: 'Elena Empleada' })
            .getByTestId('team-now'),
    ).toHaveAttribute('data-state', 'closed');
});

test('la persona ve la corrección aceptada y la salida original tachada', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/personas/jornada');
    const row = page
        .locator('[data-test="diary-row"]')
        .filter({ hasText: '19:15' })
        .first();
    await row.getByTestId('diary-open').click();

    const detail = page.getByTestId('day-detail');
    await expect(detail.getByTestId('correction-status').first()).toContainText(
        'Aceptada',
    );
    await expect(
        detail.locator('[data-test="history-row"][data-voided="true"]').first(),
    ).toBeVisible();
});

test('se vuelve a apagar el módulo', async ({ page }) => {
    await setPeopleModule(page, false);
});
