import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/*
 * Emisión propia, entrega E2 · Cobrar, enviar y portal (D-435 a D-446): emitir en la serie de
 * pruebas, registrar un cobro parcial y otro total, enviar por email (en local, MAIL_MAILER=log: no
 * sale nada) y ver la factura en el portal del cliente. La serie de pruebas nunca sale en el portal
 * (D-419): para verla allí, se emite además una en la serie F, adelantando su fecha de inicio en
 * Ajustes, y al final se deja como estaba (1/1/2027) para no cambiar los demás E2E.
 * Bodegas Arrieta es el cliente de cliente@example.com en los datos de ejemplo.
 */

test.describe.configure({ mode: 'serial' });
test.use({ testIdAttribute: 'data-test' });

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const CLIENT = 'Bodegas Arrieta';
const BILLING_EMAIL = 'facturas@bodegas-arrieta.example';

let testInvoiceUrl = '';
let testNumber = '';
let realNumber = '';
let clientBillingUrl = '';

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

/** Cambia el «Empieza el» de la serie F en Ajustes › Series. */
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

/** Crea y emite una factura de Bodegas Arrieta (10 h × 100 € + IVA = 1.210 €). */
async function issueInvoice(
    page: Page,
    code: string,
    series: RegExp,
): Promise<string> {
    await page.goto('/facturacion/facturas/nueva');
    await expect(page.getByTestId('editor-series')).toContainText(`${code} ·`);
    await page.getByTestId('editor-client').click();
    await page.getByRole('option', { name: new RegExp(CLIENT) }).click();

    const complete = page.getByTestId('complete-fiscal');
    if ((await complete.textContent())?.includes('Completar')) {
        await complete.click();
        const form = page.getByTestId('client-fiscal-form');
        await form.getByLabel('Razón social').fill('Bodegas Arrieta, S.L.');
        await form.getByLabel('Dirección').fill('Camino de la Bodega, 3');
        await form.getByLabel('Código postal').fill('26200');
        await form.getByLabel('Población').fill('Haro');
        await form
            .getByRole('button', { name: 'Guardar en la ficha' })
            .click();
        await expect(form).toBeHidden();
    }

    await page.getByTestId('line-description').first().fill('Campaña de vendimia');
    await page.getByTestId('line-quantity').first().fill('10');
    await page.getByTestId('line-price').first().fill('100');
    await expect(page.getByTestId('invoice-total')).toContainText('1.210,00');
    await page.getByTestId('editor-save').click();
    await expect(page).toHaveURL(/\/facturacion\/documentos\/\d+$/);

    await page.getByTestId('document-issue').click();
    await page.getByRole('dialog').getByTestId('issue-confirm').click();
    const heading = page.getByRole('heading', { level: 1 });
    await expect(heading).toHaveText(series);

    return ((await heading.textContent()) ?? '').trim();
}

test('se encienden Facturación y la emisión', async ({ page }) => {
    await setModules(page, true);
});

test('la ficha de facturación del cliente: email de facturación y facturas en el portal (D-443)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/clientes');
    await page.getByRole('link', { name: CLIENT }).first().click();
    await expect(page).toHaveURL(/\/clientes\/\d+$/);
    clientBillingUrl = `${new URL(page.url()).pathname}/facturacion`;
    await page.goto(clientBillingUrl);

    await page.getByLabel('Emails de facturación').fill(BILLING_EMAIL);
    await page
        .getByRole('button', { name: 'Guardar los datos fiscales' })
        .click();
    await expect(
        page.getByText('Datos fiscales guardados').first(),
    ).toBeVisible();

    // Apagado por defecto (D-443): se enciende aquí.
    const portal = page.getByTestId('client-portal_invoices');
    if ((await portal.getAttribute('aria-checked')) === 'false') {
        await portal.click();
    }
    await expect(portal).toHaveAttribute('aria-checked', 'true');
    await expect(page.getByTestId('client-payment_reminders')).toHaveAttribute(
        'aria-checked',
        'true',
    );
    // Lejos del botón que se acaba de pulsar (su transición de color no es su color).
    await page.mouse.move(0, 0);
    await page.waitForTimeout(400);
    await expectAccessible(page);
});

test('emitir en la serie de pruebas y registrar un cobro parcial (D-436)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    testNumber = await issueInvoice(page, 'PRU', /PRU\d{6}/);
    testInvoiceUrl = new URL(page.url()).pathname;

    await expect(page.getByTestId('payments-empty')).toBeVisible();
    await page.getByTestId('document-record-payment').click();
    const form = page.getByTestId('payment-form');
    await expect(form.getByTestId('payment-amount')).toHaveValue('1210.00');
    await form.getByTestId('payment-amount').fill('500');
    await expect(form.getByTestId('payment-summary')).toContainText('710,00');
    await expectAccessible(page);
    await form.getByTestId('payment-confirm').click();
    await expect(form).toBeHidden();

    await expect(page.getByTestId('payment-row')).toHaveCount(1);
    await expect(page.getByTestId('document-amounts')).toContainText('710,00');
    await expect(page.getByText('Cobrada en parte').first()).toBeVisible();
});

test('enviar por email con el PDF y el enlace; queda en la historia (D-438 a D-441)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto(testInvoiceUrl);

    await page.getByTestId('document-send').click();
    const form = page.getByTestId('send-form');
    await expect(form.getByTestId('send-to')).toContainText(BILLING_EMAIL);
    await expect(form.getByTestId('send-subject')).toHaveValue(
        new RegExp(testNumber),
    );
    await expect(form.getByTestId('send-attachments')).toContainText(testNumber);
    await expectAccessible(page);
    await form.getByTestId('send-confirm').click();
    await expect(form).toBeHidden();

    await expect(
        page.getByText(`Enviada a ${BILLING_EMAIL}`).first(),
    ).toBeVisible();
    await expect(page.getByTestId('document-send')).toContainText(
        'Volver a enviar',
    );
});

test('registrar el cobro total: la factura queda cobrada (D-436)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto(testInvoiceUrl);

    await page.getByTestId('document-record-payment').click();
    const form = page.getByTestId('payment-form');
    await expect(form.getByTestId('payment-amount')).toHaveValue('710.00');
    await expect(form.getByTestId('payment-summary')).toContainText(
        'quedará cobrada',
    );
    await form.getByTestId('payment-confirm').click();
    await expect(form).toBeHidden();

    await expect(page.getByTestId('payment-row')).toHaveCount(2);
    await expect(page.getByTestId('document-record-payment')).toHaveCount(0);
    await expect(page.getByTestId('document-chain')).toContainText('2 cobros');
});

test('Por cobrar: la antigüedad por cliente filtra la lista (D-437)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/por-cobrar');
    await expect(page).toHaveURL(/vista=por-cobrar/);

    const aging = page.getByTestId('aging-table');
    if ((await aging.count()) > 0) {
        await aging.getByTestId('aging-cell').first().click();
        await expect(page).toHaveURL(/cliente=\d+/);
    }
    await expectAccessible(page);
});

test('la de pruebas no sale en el portal; una de la serie F, sí, con su PDF (D-443)', async ({
    page,
}) => {
    test.setTimeout(90_000);
    // La serie F empieza hoy, solo para este E2E.
    await login(page, USERS.admin);
    const today = new Date().toLocaleDateString('sv-SE', {
        timeZone: 'Europe/Madrid',
    });
    await setSeriesStart(page, today);

    realNumber = await issueInvoice(page, 'F', /F\d{6}/);

    await page.context().clearCookies();
    await login(page, USERS.client);
    await page.goto('/portal');
    await page.getByTestId('portal-nav-invoices').click();
    await expect(page).toHaveURL(/\/portal\/facturas$/);
    const list = page.getByTestId('portal-invoices');
    await expect(list).toContainText(realNumber);
    await expect(list).not.toContainText(testNumber);
    await expect(page.getByTestId('portal-invoices-summary')).toContainText(
        '1.210,00',
    );

    const href = await page
        .getByTestId('portal-invoice-pdf')
        .first()
        .getAttribute('href');
    const pdf = await page.request.get(href!);
    expect(pdf.status()).toBe(200);
    expect(await pdf.text()).toContain(realNumber);
    await expectAccessible(page);
});

test('sin desplazamiento lateral a 375 px en la ficha y en el portal', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.admin);
    await page.goto(testInvoiceUrl);
    await expect(page.getByTestId('payments-list')).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);

    await page.context().clearCookies();
    await login(page, USERS.client);
    await page.goto('/portal/facturas');
    await expect(page.getByTestId('portal-invoices')).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
});

test('se deja la serie F como estaba y se apagan los módulos', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await setSeriesStart(page, '2027-01-01');

    await setModules(page, false);
});
