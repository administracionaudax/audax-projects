import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/*
 * Facturación (Fase 12, F1; D-380 a D-410): una sola navegación (D-405), Ventas (el informe de
 * facturación), «Vendido frente a real» (ya fuera de Informes), el listado de facturas con sus vistas,
 * barra de importes, orden y filtros en la URL (D-406 y D-407), la ficha (D-408), las facturas leídas de Holded
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

test('Ventas: cifras en dos grupos, gráficas con su tabla, filtro de servicio y exportación (D-400 y D-410)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    // /facturacion lleva a Ventas a quien ve los importes (hasta que llegue el Resumen).
    await page.goto('/facturacion');
    await expect(page).toHaveURL(/\/facturacion\/ventas$/);
    await expect(
        page.getByRole('heading', { name: 'Ventas', level: 1 }),
    ).toBeVisible();
    await expect(page.getByTestId('holded-sync-status')).toContainText(
        'Holded:',
    );

    const kpis = page.getByTestId('invoicing-kpis');
    await expect(kpis).toContainText('Facturado');
    await expect(kpis).toContainText('Cobrado');
    await expect(kpis).toContainText('Ticket medio');
    await expect(
        kpis.getByRole('heading', { name: 'Facturación (sin IVA)' }),
    ).toBeVisible();
    await expect(
        kpis.getByRole('heading', { name: 'Cobros (con IVA)' }),
    ).toBeVisible();
    await expect(
        page.getByRole('switch', { name: 'Comparar con el año anterior' }),
    ).toHaveAttribute('aria-checked', 'true');
    await expect(page.getByText('Facturado por mes')).toBeVisible();
    await expect(page.getByTestId('invoicing-services')).toBeVisible();
    await expect(page.getByTestId('invoicing-clients')).toBeVisible();
    await expect(page.getByTestId('invoicing-overdue')).toBeVisible();
    await expectAccessible(page);

    // Cada gráfica, también como tabla.
    const months = page.getByRole('figure', { name: 'Facturado por mes' });
    await months.getByRole('button', { name: 'Ver como tabla' }).click();
    await expect(months.getByRole('table')).toContainText('Año anterior');
    await expectAccessible(page);

    // Filtro de servicio en la URL.
    await page.getByRole('button', { name: 'Fees', exact: true }).click();
    await expect(page).toHaveURL(/servicio/);
    await expect(
        page.getByRole('button', { name: 'Fees', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');

    // La exportación con los mismos filtros, también desde la URL de antes (301 con su query).
    const csv = await page.request.get(
        '/facturacion/informe?periodo=anio&comparar=1&formato=csv&tabla=clientes',
    );
    expect(csv.status()).toBe(200);
    expect(await csv.text()).toContain('Facturado sin IVA');
});

test('en el móvil: sin scroll horizontal y con el selector de pantalla en la cabecera (D-405)', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.admin);

    for (const url of [
        '/facturacion/ventas',
        '/facturacion/facturas',
        '/facturacion/facturas?vista=sin-proyecto',
        '/facturacion/vendido-frente-a-real',
        '/facturacion/por-revisar',
    ]) {
        await page.goto(url);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );
        expect(overflow, url).toBeLessThanOrEqual(0);
    }

    // La ficha de una factura, también.
    await page.goto('/facturacion/facturas');
    await page
        .getByTestId('invoice-table')
        .locator('tbody tr')
        .first()
        .locator('a[data-row-primary]')
        .click();
    await expect(page).toHaveURL(/\/facturacion\/facturas\/\d+/);
    expect(
        await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        ),
    ).toBeLessThanOrEqual(0);

    // El selector lleva a las demás pantallas de la sección.
    await page.getByTestId('billing-section-switcher').click();
    await page.getByRole('menuitem', { name: 'Por revisar' }).click();
    await expect(page).toHaveURL(/\/facturacion\/por-revisar$/);
    await expect(
        page.getByRole('heading', { name: 'Por revisar', level: 1 }),
    ).toBeVisible();
});

test('Informes ya no tiene nada de facturación y las URL antiguas llevan a Facturación (D-401 y D-405)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/informes');
    // La barra lateral sí enlaza a Facturación; el contenido de Informes, no.
    await expect(
        page.getByRole('main').locator('a[href^="/facturacion"]'),
    ).toHaveCount(0);

    await page.goto('/informes/vendido-frente-a-real?periodo=anio');
    await expect(page).toHaveURL(
        /\/facturacion\/vendido-frente-a-real\?periodo=anio/,
    );
    await page.goto('/informes/facturacion');
    await expect(page).toHaveURL(/\/facturacion\/por-facturar$/);
    await expect(
        page.getByRole('heading', { name: 'Por facturar', level: 1 }),
    ).toBeVisible();
    await expect(
        page
            .getByRole('navigation', { name: 'Navegación principal' })
            .getByRole('link', { name: 'Por facturar' }),
    ).toHaveAttribute('aria-current', 'page');

    for (const [old, now] of [
        ['/facturacion/horas-para-facturar', /\/facturacion\/por-facturar$/],
        [
            '/facturacion/informe?periodo=anio',
            /\/facturacion\/ventas\?periodo=anio$/,
        ],
        [
            '/facturacion/contactos?vista=todos',
            /\/facturacion\/por-revisar\?vista=todos$/,
        ],
    ] as const) {
        await page.goto(old);
        await expect(page).toHaveURL(now);
    }
});

test('una sola navegación: la sección Facturación de la barra lateral, sin pestañas y con «Por revisar» contado (D-405)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/facturas');
    const billing = page.getByTestId('nav-section-billing');

    await expect(billing.getByRole('link')).toHaveText([
        'Facturas',
        'Por facturar',
        'Vendido frente a real',
        /^Por revisar/,
        'Ventas',
        'Ajustes',
    ]);
    await expect(
        billing.getByRole('link', { name: 'Facturas' }),
    ).toHaveAttribute('aria-current', 'page');
    await expect(billing.getByTestId('nav-badge')).toHaveText(/1/);
    // Ni rastro de las pestañas de antes.
    await expect(
        page.getByRole('navigation', { name: 'Secciones de facturación' }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('link', { name: 'Facturación' }).first(),
    ).toHaveAttribute('href', '/facturacion');
});

test('un responsable solo ve «Vendido frente a real» en Facturación, en horas', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/facturacion');
    await expect(page).toHaveURL(/\/facturacion\/vendido-frente-a-real/);
    // Una sola entrada: sin selector de pantalla y sin el estado de Holded (no ve importes).
    await expect(page.getByTestId('billing-section-switcher')).toHaveCount(0);
    await expect(page.getByTestId('holded-sync-status')).toHaveCount(0);
    await expect(
        page.getByTestId('nav-section-billing').getByRole('link'),
    ).toHaveText(['Vendido frente a real']);
    expect((await page.goto('/facturacion/ventas'))?.status()).toBe(403);
    expect((await page.goto('/facturacion/informe'))?.status()).toBe(403);
});

test('«Vendido frente a real»: cifras, gráfica, tabla y filtro por tipo de venta', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/ventas');
    await page
        .getByTestId('nav-section-billing')
        .getByRole('link', { name: 'Vendido frente a real' })
        .click();
    await expect(page).toHaveURL(/\/facturacion\/vendido-frente-a-real/);
    await expect(
        page.getByRole('heading', { name: 'Vendido frente a real', level: 1 }),
    ).toBeVisible();

    await expect(page.getByTestId('sold-vs-actual-kpis')).toContainText(
        'Consumo de lo vendido',
    );
    // Lo pendiente de facturar, como cifra principal del grupo sin IVA (D-410).
    await expect(page.getByTestId('sold-vs-actual-kpis')).toContainText(
        'Pendiente de facturar',
    );
    await expect(page.getByTestId('sold-vs-actual-kpis')).toContainText(
        'Facturación (sin IVA)',
    );
    // La tabla cabe a 1440 px sin desplazarse (D-410).
    await page.setViewportSize({ width: 1440, height: 900 });
    const region = page.getByRole('region', {
        name: 'Vendido frente a real por unidad de venta',
    });
    if ((await region.count()) > 0) {
        expect(
            await region.evaluate(
                (node) => node.scrollWidth - node.clientWidth,
            ),
        ).toBeLessThanOrEqual(1);
    }
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
    // La ficha lleva los filtros del listado del que vienes (D-408).
    await expect(page).toHaveURL(
        /\/facturacion\/facturas\/\d+\?(?=.*tipo=invoice)(?=.*enlace=con)/,
    );
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
    // `enlace=sin` es la vista «Sin proyecto» (D-406).
    await expect(
        page
            .getByTestId('invoice-views')
            .getByRole('link', { name: /^Sin proyecto/ }),
    ).toHaveAttribute('aria-current', 'page');
    const button = page.getByTestId('accept-suggestion').first();
    await expect(button).toBeVisible();
    const label = (await button.textContent()) ?? '';
    await button.click();
    await expect(page.getByText(/Factura enlazada con/).first()).toBeVisible();
    expect(label).toMatch(/^Enlazar [A-Z]+-/);
});

test('el listado de facturas: vistas con su número, barra de importes que filtra, orden y filtros en la URL (D-406 y D-407)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/facturas');
    await expect(
        page.getByRole('heading', { name: 'Facturas', level: 1 }),
    ).toBeVisible();
    const views = page.getByTestId('invoice-views');
    await expect(views.getByRole('link', { name: /^Todas/ })).toHaveAttribute(
        'aria-current',
        'page',
    );
    await expect(page.getByTestId('invoice-period')).toContainText('Este año');
    await expectAccessible(page);

    // Vencidas: con los días de retraso y el periodo de la vista (todo).
    await views.getByRole('link', { name: /^Vencidas/ }).click();
    await expect(page).toHaveURL(/vista=vencidas/);
    await expect(page.getByTestId('invoice-period')).toContainText('Todo');
    await expect(page.getByTestId('invoice-table')).toContainText(
        /Vencida hace \d+ días?/,
    );

    // La barra de importes filtra (y se quita al volver a pulsarla).
    await page.goto('/facturacion/facturas');
    await page.getByTestId('collection-cobrado').click();
    await expect(page).toHaveURL(/cobro=cobrado/);
    await expect(page.getByTestId('collection-cobrado')).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    // Lo cobrado, también lo cobrado en parte; nada sin un euro cobrado ni borradores.
    await expect(page.getByTestId('invoice-table')).toContainText('Cobrada');
    await expect(page.getByTestId('invoice-table')).not.toContainText(
        'Borrador',
    );
    await page.getByTestId('collection-cobrado').click();
    await expect(page).not.toHaveURL(/cobro=/);

    // Orden por columna, con aria-sort.
    await page.getByTestId('invoice-sort-pendiente').click();
    await expect(page).toHaveURL(/orden=pendiente/);
    await expect(
        page.getByRole('columnheader', { name: /Pendiente/ }),
    ).toHaveAttribute('aria-sort', 'descending');

    // Búsqueda al escribir, sin pulsar nada; el número de resultados se anuncia.
    await page.getByRole('searchbox', { name: 'Buscar' }).fill('Mirador');
    await expect(page).toHaveURL(/buscar=Mirador/);
    await expect(page.getByTestId('invoice-table')).toContainText(
        'Hoteles Mirador',
    );
    await expect(page.getByTestId('invoice-table')).not.toContainText(
        'Grupo Ferrán',
    );

    // Cliente con buscador.
    await page.goto('/facturacion/facturas');
    await page.getByTestId('invoice-client').click();
    await page.getByPlaceholder('Busca un cliente').fill('ferr');
    await page.getByRole('option', { name: /Grupo Ferrán/ }).click();
    await expect(page).toHaveURL(/cliente=\d+/);
    await expect(page.getByTestId('invoice-client')).toContainText(
        'Grupo Ferrán',
    );
    // Totales al pie de lo filtrado.
    await expect(
        page.getByTestId('invoice-table').locator('tfoot'),
    ).toContainText(/Total de \d+ facturas?/);
});

test('la ficha: cobro, línea de tiempo, anterior y siguiente del listado y la miga con sus filtros (D-408)', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/facturas?vista=vencidas');
    await page
        .getByTestId('invoice-table')
        .locator('tbody tr')
        .first()
        .locator('a[data-row-primary]')
        .click();
    await expect(page).toHaveURL(
        /\/facturacion\/facturas\/\d+\?vista=vencidas$/,
    );
    await expect(page.getByTestId('invoice-collection')).toContainText(
        'Pendiente',
    );
    await expect(page.getByTestId('invoice-timeline')).toContainText(
        'Emitida por',
    );
    await expect(page.getByTestId('invoice-timeline')).toContainText(
        /Venció hace \d+ días?/,
    );
    await expect(page.getByTestId('invoice-holded')).toHaveAttribute(
        'href',
        'https://app.holded.com/sales/revenue',
    );
    await expectAccessible(page);

    // Siguiente, dentro de las vencidas.
    const first = page.url();
    await page
        .getByTestId('invoice-neighbours')
        .getByRole('link', { name: 'Factura siguiente' })
        .click();
    await expect(page).not.toHaveURL(first);
    await expect(page).toHaveURL(/\?vista=vencidas$/);

    // La miga «Facturas» vuelve al listado con sus filtros.
    await page
        .getByRole('navigation', { name: 'Ruta de navegación' })
        .getByRole('link', { name: 'Facturas' })
        .click();
    await expect(page).toHaveURL(/\/facturacion\/facturas\?vista=vencidas$/);
});

test('un contacto de Holded sin casar se resuelve eligiendo su cliente', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/facturacion/por-revisar');
    await expect(
        page.getByRole('heading', { name: 'Por revisar', level: 1 }),
    ).toBeVisible();
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
