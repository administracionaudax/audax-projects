import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * Plan del día (docs/PLAN-CARGAS.md, Nivel 1; D-250 a D-256) con los datos de ejemplo: Elena
 * (empleado@example.com) aún no ha escrito el plan de hoy y tiene dos pendientes de su último día con
 * jornada; Raúl (responsable@example.com) es su responsable. Nunca contra el servidor
 * (playwright.config.ts).
 */

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

async function expectNoPageScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
}

test.describe.configure({ mode: 'serial' });
// Los componentes marcan sus piezas con data-test (como el resto de E2E).
test.use({ testIdAttribute: 'data-test' });

test('Mi día: pasar las pendientes a hoy, escribir con atajos, marcar hecha y ordenar', async ({
    page,
}) => {
    test.setTimeout(90_000);
    await login(page, USERS.employee);

    await test.step('la barra lateral y la tarjeta de Inicio llevan a Mi día', async () => {
        await expect(page.getByTestId('home-day-plan')).toBeVisible();
        await page.locator('a[href$="/dia"]:visible').first().click();
        await expect(page).toHaveURL(/\/dia$/);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Mi día' }),
        ).toBeVisible();
    });

    await test.step('«Pasar todas a hoy» con un clic deja la marca ↻ ×1', async () => {
        const banner = page.getByTestId('day-plan-pending');
        await expect(banner).toContainText(/Tienes \d+ pendientes?/);
        await page.getByTestId('day-plan-pending-carry-all').click();
        await expect(banner).toHaveCount(0);
        await expect(page.getByTestId('day-plan-line').first()).toBeVisible();
        await expect(
            page.getByTestId('day-plan-carry-count').first(),
        ).toContainText('×1');
    });

    const before = await page.getByTestId('day-plan-line').count();

    await test.step('Intro añade la línea con # proyecto y ~ horas, y deja escribir la siguiente', async () => {
        const input = page.getByTestId('day-plan-composer-input');
        await input.fill('Revisión E2E del plan #');
        await expect(
            page.getByTestId('day-plan-composer-options'),
        ).toBeVisible();
        await input.press('Enter');
        await expect(page.getByTestId('day-plan-composer-options')).toHaveCount(
            0,
        );
        await input.pressSequentially(' ~1:30');
        await input.press('Enter');
        await expect(page.getByTestId('day-plan-line')).toHaveCount(before + 1);
        await expect(input).toHaveValue('');
        await expect(input).toBeFocused();

        const added = page.getByTestId('day-plan-line').last();
        await expect(added).toContainText('Revisión E2E del plan');
        await expect(added.getByTestId('day-plan-line-figures')).toContainText(
            '1:30',
        );

        await input.fill('Segunda línea E2E');
        await input.press('Enter');
        await expect(page.getByTestId('day-plan-line')).toHaveCount(before + 2);
    });

    await test.step('el check la marca hecha y «Subir» la reordena', async () => {
        const added = page
            .getByTestId('day-plan-line')
            .filter({ hasText: 'Revisión E2E del plan' });
        await added.getByTestId('day-plan-line-check').click();
        await expect(added).toHaveAttribute('data-status', 'done');

        const second = page
            .getByTestId('day-plan-line')
            .filter({ hasText: 'Segunda línea E2E' });
        await second.getByTestId('day-plan-line-menu').click();
        await page.getByRole('menuitem', { name: 'Subir' }).click();
        await expect(
            page.getByTestId('day-plan-line').nth(before),
        ).toContainText('Segunda línea E2E');
        await page.waitForLoadState('networkidle');
    });

    await test.step('pasar una línea a mañana', async () => {
        const second = page
            .getByTestId('day-plan-line')
            .filter({ hasText: 'Segunda línea E2E' });
        await page.reload();
        await second.getByTestId('day-plan-line-menu').click();
        await page.getByTestId('day-plan-line-carry').click();
        await page
            .getByTestId('day-plan-carry-options')
            .getByRole('button', { name: /mañana/i })
            .click();
        await expect(second).toHaveAttribute('data-status', 'carried');
    });

    await test.step('accesible (AA) y sin desplazamiento lateral en el móvil', async () => {
        await expectAccessible(page);
        await page.setViewportSize({ width: 375, height: 812 });
        await page.reload();
        await expect(page.getByTestId('day-plan-line').first()).toBeVisible();
        await expectNoPageScroll(page);
    });
});

test('el temporizador desde una línea y «¿Das por hecha la línea?» al pararlo', async ({
    page,
}) => {
    test.setTimeout(90_000);
    await login(page, USERS.employee);
    await page.goto('/dia');

    await test.step('«Desde mis tareas» añade una línea enlazada con su tarea', async () => {
        await page.getByTestId('day-plan-from-tasks').click();
        const list = page.getByTestId('day-plan-from-tasks-list');
        const empty = page
            .getByRole('dialog')
            .getByText('No hay tareas que proponer');
        await expect(list.or(empty)).toBeVisible();
        test.skip(
            (await list.count()) === 0,
            'Sin tareas que proponer hoy en los datos de ejemplo.',
        );
        await list.getByRole('checkbox').first().click();
        await page.getByTestId('day-plan-from-tasks-add').click();
        await expect(page.getByRole('dialog')).toHaveCount(0);
    });

    const line = page
        .getByTestId('day-plan-line')
        .filter({ has: page.getByText(/tarea: «/) })
        .last();

    await test.step('▶ arranca el temporizador en la tarea de la línea', async () => {
        await line.getByTestId('day-plan-line-timer').click();
        await expect(line.getByTestId('day-plan-line-timer')).toHaveAttribute(
            'data-running',
            'true',
        );
        await expect(page.getByTestId('timer-chip')).toBeVisible();
    });

    await test.step('al pararlo pregunta si se da por hecha', async () => {
        await line.getByTestId('day-plan-line-timer').click();
        const toast = page.getByText(/¿Das por hecha/);
        await expect(toast).toBeVisible();
        await page.getByRole('button', { name: 'Marcar como hecha' }).click();
        await expect(line).toHaveAttribute('data-status', 'done');
    });
});

test('Equipo hoy: el responsable ve las cifras y comenta; la compañera, solo textos y checks', async ({
    page,
}) => {
    test.setTimeout(90_000);
    await login(page, USERS.manager);
    await page.goto('/dia/equipo');

    const elena = page
        .getByTestId('day-plan-team-row')
        .filter({ hasText: 'Elena Empleada' });

    await test.step('su responsable ve el plan de Elena con las cifras', async () => {
        await expect(elena).toBeVisible();
        await expect(elena.getByTestId('day-plan-team-figures')).toBeVisible();
        await expect(
            elena.getByTestId('day-plan-team-line').first(),
        ).toBeVisible();
        // Lucía no lo ha escrito: «Sin plan» pasada la hora límite o «Aún no» antes.
        await expect(
            page
                .getByTestId('day-plan-team-row')
                .filter({ hasText: 'Lucía Martín' })
                .getByTestId('day-plan-state'),
        ).toHaveText(/Sin plan|Aún no/);
    });

    await test.step('comenta una línea de Elena', async () => {
        await elena.getByTestId('day-plan-comment-open').first().click();
        await elena
            .getByTestId('day-plan-comment-body')
            .fill('Comentario E2E del responsable');
        await elena.getByTestId('day-plan-comment-send').click();
        await expect(
            elena
                .getByTestId('day-plan-comment')
                .filter({ hasText: 'Comentario E2E del responsable' }),
        ).toBeVisible();
    });

    await test.step('accesible (AA)', async () => {
        await expectAccessible(page);
    });

    await test.step('la semana del equipo abre las líneas de un día', async () => {
        await page.getByTestId('weekly-tab-week').click();
        await expect(page).toHaveURL(/\/dia\/semana/);
        const cell = page
            .getByTestId('day-plan-week-cell')
            .and(page.locator(':enabled'))
            .first();
        await cell.click();
        await expect(page.getByTestId('day-plan-week-lines')).toBeVisible();
        await page.keyboard.press('Escape');
        await expectAccessible(page);
    });

    await test.step('Lucía ve el plan de Elena sin cifras ni comentarios', async () => {
        await page.context().clearCookies();
        await login(page, 'lucia.martin@example.com');
        await page.goto('/dia/equipo');
        const row = page
            .getByTestId('day-plan-team-row')
            .filter({ hasText: 'Elena Empleada' });
        await expect(
            row.getByTestId('day-plan-team-line').first(),
        ).toBeVisible();
        await expect(row.getByTestId('day-plan-team-figures')).toHaveCount(0);
        await expect(row.getByTestId('day-plan-comments')).toHaveCount(0);
        await expect(row.getByTestId('day-plan-line-figures')).toHaveCount(0);
    });
});

test('los ajustes del plan del día y su módulo en /admin/ajustes', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/admin/ajustes');

    await expect(
        page.getByRole('heading', { name: 'Plan del día' }),
    ).toBeVisible();
    await expect(page.getByTestId('settings-day-plan-deadline')).toHaveValue(
        '08:30',
    );
    await expect(
        page.getByLabel('Plan del día', { exact: true }),
    ).toBeVisible();
});
