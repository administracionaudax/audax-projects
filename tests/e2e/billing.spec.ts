import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/*
 * Facturación (Fase 12, F1; D-380 a D-399): «Vendido frente a real», las facturas leídas de Holded
 * (el Holded falso de los datos de ejemplo, HOLDED_DRIVER=fake), el PDF de una factura y resolver un
 * contacto sin casar. El módulo `billing` viene apagado: el primer test lo enciende en
 * /admin/ajustes y el último lo vuelve a apagar. Nunca contra el servidor (playwright.config.ts).
 */

test.describe.configure({ mode: 'serial' });
test.use({ testIdAttribute: 'data-test' });

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function setBillingModule(page: Page, on: boolean): Promise<void> {
    await login(page, USERS.admin);
    await page.goto('/admin/ajustes');
    const toggle = page.getByRole('switch', {
        name: 'Facturación (lectura de Holded)',
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

test('se enciende el módulo Facturación', async ({ page }) => {
    await setBillingModule(page, true);
});

test('«Vendido frente a real»: cifras, gráfica, tabla y filtro por tipo de venta', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/informes');
    await page.getByTestId('r1-index-sold-vs-actual').click();
    await expect(page).toHaveURL(/\/informes\/vendido-frente-a-real/);
    await expect(
        page.getByRole('heading', { name: 'Vendido frente a real', level: 1 }),
    ).toBeVisible();

    await expect(page.getByTestId('sold-vs-actual-kpis')).toContainText(
        'Consumo de lo vendido',
    );
    await expect(page.getByTestId('sold-vs-actual-kpis')).toContainText(
        'Facturado (sin IVA)',
    );
    await expect(page.getByTestId('sold-vs-actual-chart')).toBeVisible();
    const table = page.getByTestId('sold-vs-actual-table');
    await expect(table).toContainText('Bolsa');
    await expectAccessible(page);

    await page
        .getByRole('button', { name: 'Fee mensual', exact: true })
        .click();
    await expect(page).toHaveURL(
        /venta%5B0%5D=fee|venta\[\]=fee|venta%5B%5D=fee/,
    );
    await expect(table).toContainText('FER-FE1');
    await expect(table).not.toContainText('ARR-WEB');
    await expect(
        page.getByRole('button', { name: 'Fee mensual', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');

    // La fila lleva a la pestaña Facturación del proyecto.
    await table.getByRole('link', { name: /Mantenimiento mensual/ }).click();
    await expect(page).toHaveURL(/\/proyectos\/\d+\/facturacion/);
    await expect(page.getByTestId('billing-panel')).toContainText(
        'Fee mensual',
    );
});

test('una factura sincronizada de Holded, sus enlaces y su PDF', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/facturas?tipo=invoice&enlace=con');
    const first = page.getByTestId('invoice-table').locator('tbody tr').first();
    await first.getByRole('link').first().click();
    await expect(page).toHaveURL(/\/facturacion\/facturas\/\d+$/);
    await expect(page.getByTestId('invoice-links')).toBeVisible();
    await expectAccessible(page);

    const href = await page.getByTestId('invoice-pdf').getAttribute('href');
    expect(href).toMatch(/\/facturacion\/facturas\/\d+\/pdf$/);
    const response = await page.request.get(href ?? '');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('application/pdf');
    expect((await response.body()).subarray(0, 4).toString()).toBe('%PDF');
});

test('una factura sin enlazar se enlaza con su sugerencia', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/facturas?enlace=sin');
    const button = page.getByTestId('accept-suggestion').first();
    await expect(button).toBeVisible();
    const label = (await button.textContent()) ?? '';
    await button.click();
    await expect(page.getByText(/Factura enlazada con/).first()).toBeVisible();
    expect(label).toMatch(/Enlazar con/);
});

test('un contacto de Holded sin casar se resuelve eligiendo su cliente', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/contactos');
    const contacts = page.getByTestId('holded-contacts');
    await expect(contacts).toContainText('Estudio Nébula, S.L.');
    await expectAccessible(page);

    // El selector de cliente lleva buscador (D-245): se escribe y se elige.
    await contacts.getByTestId('contact-client').first().click();
    await page
        .getByPlaceholder('Busca un cliente por nombre o NIF')
        .fill('faro');
    await expect(page.getByRole('option')).toHaveCount(1);
    await page.getByRole('option', { name: /^Librería El Faro/ }).click();
    await contacts.getByRole('button', { name: 'Asignar' }).first().click();
    await expect(
        page
            .getByText(
                'Estudio Nébula, S.L. es ahora el cliente Librería El Faro.',
            )
            .first(),
    ).toBeVisible();
    await expect(
        page.getByText('Todos los contactos tienen cliente'),
    ).toBeVisible();

    // Sus facturas pasan a ese cliente.
    await page.goto('/facturacion/facturas?buscar=N%C3%A9bula');
    await expect(page.getByTestId('invoice-table')).toContainText(
        'Librería El Faro',
    );
});

test('los ajustes dicen quién ve Facturación, y nadie se quita el acceso a sí mismo', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/ajustes');
    const access = page.getByTestId('billing-access');
    await expect(access).toBeVisible();
    await expect(
        access.getByRole('switch', { name: /^Acceso de .* a Facturación$/ }),
    ).not.toHaveCount(0);
    // El interruptor propio está desactivado: no se puede quitar uno mismo.
    await expect(access.locator('[role="switch"][disabled]')).toHaveCount(1);
    await expectAccessible(page);
});

test('se apaga el módulo Facturación', async ({ page }) => {
    await setBillingModule(page, false);
    const response = await page.goto('/facturacion/facturas');
    expect(response?.status()).toBe(404);
});
