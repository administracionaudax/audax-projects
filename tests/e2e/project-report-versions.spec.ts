import fs from 'node:fs';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { expectReportPdf, login, USERS } from './support';

/**
 * Informe de un proyecto en sus dos versiones (D-240 a D-242) sobre los datos del DemoDataSeeder:
 * en «Exportar ▾» se elige «Interno (completo)» o «Para el cliente» y el PDF (sin Gotenberg en
 * local y en la CI, REPORTS_PDF_DRIVER=html: el HTML que convertiría) y el Excel salen en esa
 * versión; «Enviar por correo…» la lleva y deja cambiarla.
 */

async function download(page: Page, action: () => Promise<void>) {
    const [file] = await Promise.all([page.waitForEvent('download'), action()]);
    const path = await file.path();

    return { name: file.suggestedFilename(), bytes: fs.readFileSync(path) };
}

async function openProjectReport(page: Page): Promise<void> {
    await page.goto('/proyectos');
    await page.waitForLoadState('networkidle');
    const href = await page
        .getByRole('link', { name: /Web corporativa/ })
        .first()
        .getAttribute('href');
    const id = /\/proyectos\/(\d+)/.exec(href ?? '')?.[1];
    expect(id, 'proyecto «Web corporativa» del DemoDataSeeder').toBeTruthy();

    await page.goto(`/informes/proyectos/${id}?periodo=anio`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

test('exporta el informe interno y completo y el del cliente', async ({
    page,
}) => {
    test.setTimeout(60_000);
    await login(page, USERS.admin);
    await openProjectReport(page);

    // Por defecto, el interno y completo.
    await page.getByRole('button', { name: 'Exportar' }).first().click();
    const menu = page.getByRole('menu');
    await expect(
        menu.getByRole('menuitemradio', { name: 'Interno (completo)' }),
    ).toHaveAttribute('aria-checked', 'true');
    await expect(menu.getByRole('menuitem', { name: 'PDF' })).toHaveAttribute(
        'href',
        /version=interno.*formato=pdf/,
    );

    const internal = await download(page, () =>
        menu.getByRole('menuitem', { name: 'PDF' }).click(),
    );
    expect(internal.name).toMatch(/^informe-proyecto-[a-z0-9-]+\.(pdf|html)$/);
    expectReportPdf(internal);
    if (internal.name.endsWith('.html')) {
        const html = internal.bytes.toString();
        for (const section of [
            'Resumen del proyecto',
            'Horas por persona',
            'Estimado frente a real por tarea',
            'Horas por tarea y persona',
            'Horas por mes',
            'Costes y margen',
            'Entradas de horas',
        ]) {
            expect(html).toContain(section);
        }
    }

    await page.getByRole('button', { name: 'Exportar' }).first().click();
    const workbook = await download(page, () =>
        page.getByRole('menuitem', { name: 'Excel (.xlsx)' }).click(),
    );
    expect(workbook.name).toMatch(/^informe-completo-de-[a-z0-9-]+\.xlsx$/);
    expect(workbook.bytes.subarray(0, 2).toString()).toBe('PK');

    // Para el cliente: elegir la versión no cierra el menú y todo sale en ella.
    await page.getByRole('button', { name: 'Exportar' }).first().click();
    await page.getByRole('menuitemradio', { name: 'Para el cliente' }).click();
    await expect(
        page.getByRole('menuitemradio', { name: 'Para el cliente' }),
    ).toHaveAttribute('aria-checked', 'true');
    await expect(page.getByRole('menuitem', { name: 'PDF' })).toHaveAttribute(
        'href',
        /version=cliente.*formato=pdf/,
    );

    const client = await download(page, () =>
        page.getByRole('menuitem', { name: 'PDF' }).click(),
    );
    expect(client.name).toMatch(
        /^informe-proyecto-cliente-[a-z0-9-]+\.(pdf|html)$/,
    );
    expectReportPdf(client);
    if (client.name.endsWith('.html')) {
        const html = client.bytes.toString();
        expect(html).toContain('Informe de proyecto para el cliente');
        expect(html).toContain('Incluye solo las horas aprobadas.');
        expect(html).toContain('Detalle de las horas');
        for (const internalOnly of [
            'Costes y margen',
            'Rentabilidad',
            'Ingreso estimado',
            'Tarifa',
            'Borrador',
            'Datos económicos',
        ]) {
            expect(html).not.toContain(internalOnly);
        }
    }

    await page.getByRole('button', { name: 'Exportar' }).first().click();
    await expect(
        page.getByRole('menuitemradio', { name: 'Para el cliente' }),
    ).toHaveAttribute('aria-checked', 'true');
    const clientWorkbook = await download(page, () =>
        page.getByRole('menuitem', { name: 'Excel (.xlsx)' }).click(),
    );
    expect(clientWorkbook.name).toMatch(
        /^informe-para-el-cliente-de-[a-z0-9-]+\.xlsx$/,
    );
});

test('«Enviar por correo…» lleva la versión elegida y deja cambiarla', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await openProjectReport(page);

    await page.getByRole('button', { name: 'Exportar' }).first().click();
    await page.getByRole('menuitemradio', { name: 'Para el cliente' }).click();
    await page.getByRole('menuitem', { name: 'Enviar por correo…' }).click();

    const dialog = page.getByRole('dialog');
    const versions = dialog.getByRole('radiogroup', {
        name: 'Versión del informe',
    });
    await expect(
        versions.getByRole('radio', { name: 'Para el cliente' }),
    ).toHaveAttribute('aria-checked', 'true');
    await versions.getByRole('radio', { name: 'Interno (completo)' }).click();
    await expect(
        versions.getByRole('radio', { name: 'Interno (completo)' }),
    ).toHaveAttribute('aria-checked', 'true');

    await versions.getByRole('radio', { name: 'Para el cliente' }).click();
    await dialog.getByLabel('Correos externos').fill('cliente@example.com');
    await dialog.getByLabel('Correos externos').press('Enter');
    await expect(dialog.getByText('cliente@example.com')).toBeVisible();
    await dialog.getByRole('button', { name: 'Enviar', exact: true }).click();
    // Se genera en la cola (síncrona en local) con la versión elegida y el diálogo se cierra.
    await expect(dialog).toBeHidden({ timeout: 20_000 });
    await expect(
        page.getByText(
            'Envío en cola: llegará en unos minutos a 1 destinatario.',
        ),
    ).toBeVisible();
});
