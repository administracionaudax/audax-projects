import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/*
 * Emisión propia, entrega E3 · Recurrentes, programadas y facturar desde el trabajo (D-450 en
 * adelante): crear una recurrente y generarla a mano; desde «Por facturar», crear un borrador con
 * las horas de un proyecto por horas, emitirlo en la serie F y comprobar que esas horas ya no están
 * pendientes; anularlo y ver que vuelven. La serie de pruebas no bloquea horas (D-461): para probar
 * el bloqueo, la serie F empieza hoy solo en este E2E y al final se deja como estaba (1/1/2027).
 * Fundación Aula Viva tiene horas aprobadas sin facturar en los datos de ejemplo (AUL-WEB).
 */

test.describe.configure({ mode: 'serial' });
test.use({ testIdAttribute: 'data-test' });

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const HOURS_CLIENT = 'Fundación Aula Viva';
const MONTHS = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
];

let invoiceUrl = '';

async function setModules(page: Page, on: boolean): Promise<void> {
    await page.context().clearCookies();
    await login(page, USERS.admin);
    await page.goto('/admin/ajustes');

    for (const name of [
        'Facturación (lectura de Holded)',
        'Emisión de facturas',
    ]) {
        const toggle = page.getByRole('switch', { name, exact: true });
        if ((await toggle.getAttribute('aria-checked')) !== String(on)) {
            await toggle.click();
        }
        await expect(toggle).toHaveAttribute('aria-checked', String(on));
    }

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

/** Cambia el «Empieza el» de la serie F en Ajustes › Series (como el E2E de E2). */
async function setSeriesStart(page: Page, date: string): Promise<void> {
    await page.goto('/facturacion/ajustes?apartado=series');
    const dialog = page.getByRole('dialog');
    await expect(async () => {
        await page
            .getByRole('button', { name: 'Editar F', exact: true })
            .click();
        await expect(dialog).toBeVisible({ timeout: 1_000 });
    }).toPass();
    await dialog.getByLabel('Empieza el').fill(date);
    await dialog.getByRole('button', { name: 'Guardar' }).click();
    await expect(dialog).toBeHidden();
}

/** «Crear borrador» de un cliente en Por facturar (abre el panel lateral). */
async function openWorkPanel(page: Page): Promise<void> {
    await page.goto('/facturacion/por-facturar');
    const row = page
        .getByTestId('unbilled-table')
        .getByRole('row')
        .filter({ hasText: HOURS_CLIENT });
    await row.getByTestId('unbilled-create-draft').click();
    await expect(page.getByTestId('work-draft-sheet')).toBeVisible();
}

test('se encienden Facturación y la emisión', async ({ page }) => {
    await setModules(page, true);
});

test('crear una recurrente y generarla a mano: el borrador lleva las palabras del periodo', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/recurrentes');
    await expectAccessible(page);
    await page.getByTestId('new-recurring').click();
    await expect(page).toHaveURL(/\/facturacion\/recurrentes\/nueva/);

    await page.getByTestId('recurring-name-input').fill('Fee de redes (E2E)');
    await page.getByTestId('recurring-client').click();
    await page.getByRole('option', { name: /Bodegas Arrieta/ }).click();
    await page.getByTestId('recurring-series').click();
    await page.getByRole('option', { name: /^PRU ·/ }).click();
    await page
        .getByTestId('line-description')
        .first()
        .fill('Fee de [mes] de [año]');
    await page.getByTestId('line-quantity').first().fill('1');
    await page.getByTestId('line-price').first().fill('1200');
    await expectAccessible(page);
    await page.getByTestId('recurring-save').click();

    await expect(page).toHaveURL(/\/facturacion\/recurrentes\/\d+$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'Fee de redes (E2E)',
    );
    await expect(
        page.getByTestId('recurring-calendar').getByRole('listitem'),
    ).toHaveCount(12);
    await expectAccessible(page);

    // El periodo: el primer día que toca (el 1 del mes que viene).
    const next = new Date();
    next.setMonth(next.getMonth() + 1, 1);
    const words = `Fee de ${MONTHS[next.getMonth()]} de ${next.getFullYear()}`;

    await page.getByTestId('recurring-generate').click();
    await expect(page).toHaveURL(/\/facturacion\/documentos\/\d+\/editar$/);
    await expect(page.getByTestId('line-description').first()).toHaveValue(
        words,
    );

    await page.goBack();
    await page.reload();
    await expect(page.getByTestId('recurring-history')).toContainText(
        'Borrador',
    );
});

test('desde Por facturar: crear un borrador con las horas y emitirlo en la serie F; ya no están pendientes', async ({
    page,
}) => {
    test.setTimeout(120_000);
    await login(page, USERS.admin);
    // La serie F empieza hoy, solo para este E2E (la de pruebas no bloquea horas, D-461).
    const today = new Date().toLocaleDateString('sv-SE', {
        timeZone: 'Europe/Madrid',
    });
    await setSeriesStart(page, today);
    await openWorkPanel(page);

    const sheet = page.getByTestId('work-draft-sheet');
    await expect(sheet.getByTestId('work-hours-line').first()).toBeVisible();
    await expect(sheet.getByTestId('work-total')).toContainText('€');
    await expectAccessible(page);
    await sheet.getByTestId('work-create').click();

    await expect(page).toHaveURL(/\/facturacion\/documentos\/\d+\/editar$/);
    await expect(page.getByTestId('editor-work-lines')).toBeVisible();
    await expect(page.getByTestId('editor-series')).toContainText('F ·');

    const complete = page.getByTestId('complete-fiscal');
    if ((await complete.textContent())?.includes('Completar')) {
        await complete.click();
        const form = page.getByTestId('client-fiscal-form');
        const fill = async (label: string, value: string) => {
            const field = form.getByLabel(label);
            if ((await field.inputValue()) === '') {
                await field.fill(value);
            }
        };
        await fill('NIF', 'G26999999');
        await fill('Razón social', 'Fundación Aula Viva');
        await fill('Dirección', 'Calle del Aula, 1');
        await fill('Código postal', '26001');
        await fill('Población', 'Logroño');
        await form.getByRole('button', { name: 'Guardar en la ficha' }).click();
        await expect(form).toBeHidden();
    }

    await page.getByTestId('editor-save').click();
    await expect(page).toHaveURL(/\/facturacion\/documentos\/\d+$/);
    await expect(page.getByTestId('line-work-origin').first()).toContainText(
        'se bloquea',
    );

    await page.getByTestId('document-issue').click();
    await page.getByRole('dialog').getByTestId('issue-confirm').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        /^F\d{6}$/,
    );
    await expect(page.getByTestId('line-work-origin').first()).toContainText(
        'bloqueada',
    );
    invoiceUrl = new URL(page.url()).pathname;

    // Las horas ya no se ofrecen: están en la factura emitida (si no le queda nada, ni sale en la lista).
    await page.goto('/facturacion/por-facturar');
    await expect(page.getByTestId('unbilled-table')).toBeVisible();
    const row = page
        .getByTestId('unbilled-table')
        .getByRole('row')
        .filter({ hasText: HOURS_CLIENT });
    if ((await row.count()) > 0) {
        await row.getByTestId('unbilled-create-draft').click();
        const sheet = page.getByTestId('work-draft-sheet');
        await expect(
            sheet
                .getByTestId('work-total')
                .or(sheet.getByText('Nada por facturar en este periodo')),
        ).toBeVisible();
        await expect(sheet.getByTestId('work-hours-line')).toHaveCount(0);
    }
});

test('al anular la factura, las horas vuelven a estar pendientes', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto(invoiceUrl);

    await page.getByTestId('document-cancel').click();
    await page.getByTestId('correct-reason').fill('Prueba del E2E de E3');
    await page.getByTestId('cancel-confirm').click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        /^CN\d{6}$/,
    );

    await openWorkPanel(page);
    await expect(
        page
            .getByTestId('work-draft-sheet')
            .getByTestId('work-hours-line')
            .first(),
    ).toBeVisible();
});

test('a 375 px, la lista de recurrentes y Por facturar sin desplazamiento lateral', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.admin);
    for (const url of [
        '/facturacion/recurrentes',
        '/facturacion/por-facturar',
    ]) {
        await page.goto(url);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
            url,
        ).toBe(true);
    }
});

test('se deja la serie F como estaba y se apagan los módulos', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await setSeriesStart(page, '2027-01-01');

    await setModules(page, false);
});
