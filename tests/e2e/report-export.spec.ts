import fs from 'node:fs';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { expectReportPdf, login, USERS } from './support';

/**
 * Menú «Exportar ▾» de los informes (Fase 9, D-139 y D-140) sobre los datos del DemoDataSeeder:
 * sus entradas, la descarga del PDF (sin Gotenberg en local y en la CI, REPORTS_PDF_DRIVER=html:
 * el HTML que convertiría) y la pestaña de imprimir, sin la app y con el diálogo de impresión.
 */

async function download(page: Page, action: () => Promise<void>) {
    const [file] = await Promise.all([page.waitForEvent('download'), action()]);
    const path = await file.path();

    return { name: file.suggestedFilename(), bytes: fs.readFileSync(path) };
}

test('dirección: el menú tiene todas las salidas y el PDF se descarga con los filtros', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await page.goto('/informes/direccion?periodo=trimestre');

    await page.getByRole('button', { name: 'Exportar' }).first().click();
    const menu = page.getByRole('menu');
    await expect(menu.getByRole('menuitem')).toHaveText([
        'Excel (.xlsx)',
        'CSV (.csv)',
        'PDF',
        'Imprimir',
        'Enviar por correo…',
        'Programar envío…',
    ]);
    // Sin credenciales de Google en el entorno (CI y E2E locales), Google Sheets no se ofrece
    // (D-142); con ellas lo cubre tests/e2e/integrations.spec.ts.
    await expect(
        menu.getByRole('menuitem', { name: /Google Sheets/ }),
    ).toHaveCount(0);
    await expect(menu.getByRole('menuitem', { name: 'PDF' })).toHaveAttribute(
        'href',
        /\/informes\/direccion\?periodo=trimestre.*formato=pdf/,
    );

    const pdf = await download(page, () =>
        menu.getByRole('menuitem', { name: 'PDF' }).click(),
    );
    expect(pdf.name).toMatch(/^informe-direccion-\d{4}-t\d\.(pdf|html)$/);
    expectReportPdf(pdf);
    if (pdf.name.endsWith('.html')) {
        const html = pdf.bytes.toString();
        expect(html).toContain('Informe de dirección');
        expect(html).toContain('Cifras clave');
        expect(html).toContain("font-family:'DM Sans'");
    }

    // «Enviar por correo…» abre su diálogo con el título limpio (sin las marcas [[…]] del azul).
    await page.getByRole('button', { name: 'Exportar' }).first().click();
    await page.getByRole('menuitem', { name: 'Enviar por correo…' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('Enviar por correo');
    await expect(dialog).not.toContainText('[[');
});

test('imprimir abre el mismo documento en otra pestaña, sin la app, y lanza el diálogo de impresión', async ({
    page,
    context,
}) => {
    // window.print() bloquearía la prueba: se sustituye por una marca.
    await context.addInitScript(() => {
        window.print = () => {
            (window as unknown as { __printed: boolean }).__printed = true;
        };
    });
    await login(page, USERS.admin);
    await page.goto('/informes/direccion');

    await page.getByRole('button', { name: 'Exportar' }).first().click();
    const [tab] = await Promise.all([
        context.waitForEvent('page'),
        page.getByRole('menuitem', { name: 'Imprimir' }).click(),
    ]);
    await tab.waitForLoadState('load');

    expect(tab.url()).toContain('formato=imprimir');
    await expect(
        tab.getByRole('heading', { level: 1, name: 'Toda la agencia' }),
    ).toBeVisible();
    await expect(tab.getByText('Cifras clave')).toBeVisible();
    // Sin menús ni barra de la app.
    await expect(tab.getByRole('navigation')).toHaveCount(0);
    await expect(tab.locator('[data-page]')).toHaveCount(0);
    await expect
        .poll(() =>
            tab.evaluate(
                () => (window as unknown as { __printed?: boolean }).__printed,
            ),
        )
        .toBe(true);
});

test('la pestaña Horas de un proyecto tiene el mismo menú', async ({
    page,
}) => {
    await login(page, USERS.admin);

    await page.goto('/proyectos');
    await page
        .getByRole('link', { name: /Web corporativa/ })
        .first()
        .click();
    await expect(page).toHaveURL(/\/proyectos\/\d+/);
    const id = /\/proyectos\/(\d+)/.exec(page.url())?.[1];
    await page.goto(`/proyectos/${id}/horas`);

    await page.getByRole('button', { name: 'Exportar' }).click();
    await expect(page.getByRole('menuitem', { name: 'PDF' })).toHaveAttribute(
        'href',
        /\/proyectos\/\d+\/horas\/exportar\?formato=pdf/,
    );
    const csv = await download(page, () =>
        page.getByRole('menuitem', { name: 'CSV (.csv)' }).click(),
    );
    expect(csv.name).toMatch(/^horas-.*\.csv$/);
});
