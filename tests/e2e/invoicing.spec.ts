import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/*
 * Emisión propia, entrega E1 (D-417 a D-429): de «Nueva factura» a un borrador con los datos
 * fiscales del cliente completados desde el editor, emitirlo en la serie de pruebas (antes del
 * 1/1/2027 el editor la propone), abrir su PDF, duplicarla, rectificarla por diferencias y anularla.
 * Los módulos `billing` e `invoicing` vienen apagados: el primer test los enciende en /admin/ajustes
 * y el último los vuelve a apagar. Nunca contra el servidor (playwright.config.ts): en local el PDF
 * sale en HTML (REPORTS_PDF_DRIVER=html) y nada llama a la AEAT ni a Holded.
 */

test.describe.configure({ mode: 'serial' });
test.use({ testIdAttribute: 'data-test' });

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const CLIENT = 'Hoteles Mirador';

/** La ficha de la factura emitida, compartida entre los pasos. */
let invoiceUrl = '';
let invoiceNumber = '';

async function setModules(page: Page, on: boolean): Promise<void> {
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

test('se encienden Facturación y la emisión de facturas', async ({ page }) => {
    await setModules(page, true);
});

test('Nueva factura: cliente con sus datos fiscales completados, líneas, totales y borrador (D-428)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/facturas');
    await page.getByRole('link', { name: 'Nueva factura' }).first().click();
    await expect(page).toHaveURL(/\/facturacion\/facturas\/nueva/);
    await expect(page.getByTestId('invoice-editor')).toBeVisible();

    // Antes del 1/1/2027 el editor propone la serie de pruebas (D-419).
    await expect(page.getByTestId('editor-series')).toContainText('PRU');

    await page.getByTestId('editor-client').click();
    await page.getByRole('option', { name: new RegExp(CLIENT) }).click();
    const fiscal = page.getByTestId('client-fiscal');
    await expect(fiscal).toContainText(CLIENT);

    // Si a su ficha le falta el domicilio, se completa sin salir del editor.
    const complete = page.getByTestId('complete-fiscal');
    if ((await complete.textContent())?.includes('Completar')) {
        await complete.click();
        const form = page.getByTestId('client-fiscal-form');
        await form.getByLabel('Razón social').fill('Hoteles Mirador, S.A.');
        await form.getByLabel('Dirección').fill('Avenida del Puerto, 10');
        await form.getByLabel('Código postal').fill('35001');
        await form.getByLabel('Población').fill('Las Palmas de Gran Canaria');
        await form.getByRole('button', { name: 'Guardar en la ficha' }).click();
        await expect(form).toBeHidden();
    }
    await expect(fiscal).not.toContainText('Para emitir falta');

    await page.getByTestId('line-description').first().fill('Diseño de la web');
    await page.getByTestId('line-quantity').first().fill('10');
    await page.getByTestId('line-price').first().fill('60');
    await expect(page.getByTestId('line-base').first()).toContainText('600,00');
    await expect(page.getByTestId('invoice-total')).toContainText('726,00');

    await expectAccessible(page);

    await page.getByTestId('editor-save').click();
    await expect(page).toHaveURL(/\/facturacion\/documentos\/\d+$/);
    await expect(
        page.getByRole('heading', { level: 1, name: /Borrador/ }),
    ).toBeVisible();
    invoiceUrl = new URL(page.url()).pathname;
});

test('Emitir: número correlativo de la serie de pruebas, registro encadenado y PDF (D-419 a D-421 y D-426)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto(invoiceUrl);

    await page.getByTestId('document-issue').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText(/PRU\d{6}/);
    await expectAccessible(page);
    await dialog.getByTestId('issue-confirm').click();
    await expect(dialog).toBeHidden();

    const heading = page.getByRole('heading', { level: 1 });
    await expect(heading).toHaveText(/PRU\d{6}/);
    invoiceNumber = ((await heading.textContent()) ?? '').match(/PRU\d{6}/)![0];
    await expect(page.getByText('Emitida').first()).toBeVisible();
    await expect(page.getByTestId('document-record')).toBeVisible();
    // Emitida ya no se edita ni se borra.
    await expect(page.getByTestId('document-edit')).toHaveCount(0);

    const href = await page.getByTestId('document-pdf').getAttribute('href');
    expect(href).toBeTruthy();
    const pdf = await page.request.get(href!);
    expect(pdf.status()).toBe(200);
    expect(await pdf.text()).toContain(invoiceNumber);

    await expectAccessible(page);
});

test('La emitida sale en el listado, como emitida en Audax (D-427)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/facturas?vista=pruebas');
    await expect(page.getByText(invoiceNumber).first()).toBeVisible();
});

test('Duplicar crea un borrador con las mismas líneas (D-424)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto(invoiceUrl);

    await page.getByTestId('document-more').click();
    await page.getByTestId('document-duplicate').click();
    await expect(page).toHaveURL(/\/facturacion\/documentos\/\d+\/editar$/);
    await expect(page.getByTestId('line-description').first()).toHaveValue(
        'Diseño de la web',
    );
    await expect(page.getByTestId('invoice-total')).toContainText('726,00');
});

test('Rectificar por diferencias emite una rectificativa solo por la diferencia (D-424)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto(invoiceUrl);

    await page.getByTestId('document-rectify').click();
    const form = page.getByTestId('rectify-form');
    await form.getByTestId('rectify-price').first().fill('50');
    await form.getByTestId('correct-reason').fill('Precio pactado de 50 €/h');
    await expect(page.getByTestId('rectify-summary')).toContainText('121,00');
    await page.getByTestId('rectify-confirm').click();

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        /PRUCN\d{6}/,
    );
    await expect(page.getByTestId('document-rectification')).toContainText(
        invoiceNumber,
    );
    await expect(page.getByTestId('document-amounts')).toContainText('-121,00');
});

test('Anular emite la rectificativa por el total y deja la original anulada (D-244 y D-424)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto(invoiceUrl);

    await page.getByTestId('document-cancel').click();
    await page.getByTestId('correct-reason').fill('Factura duplicada');
    await expect(page.getByTestId('cancel-summary')).toContainText('605,00');
    await page.getByTestId('cancel-confirm').click();

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        /PRUCN\d{6}/,
    );
    await expect(page.getByTestId('document-amounts')).toContainText('-605,00');

    await page.goto(invoiceUrl);
    await expect(page.getByText('Anulada').first()).toBeVisible();
    await expect(page.getByTestId('document-cancel')).toHaveCount(0);
    await expect(page.getByTestId('document-rectify')).toHaveCount(0);
});

test('se apagan la emisión de facturas y Facturación', async ({ page }) => {
    await setModules(page, false);
});
