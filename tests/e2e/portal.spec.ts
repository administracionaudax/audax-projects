import fs from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import type { Theme } from './support';
import { expectTheme, login, saveUserTheme, USERS } from './support';

/**
 * Portal de cliente · bolsas (P1, Fase 5; SPEC §11 y aceptación de la §17), sobre el
 * DemoDataSeeder: cliente@example.com es de «Bodegas Arrieta», con ARR-WEB («Bolsa Diseño» y
 * «Bolsa Desarrollo») y ARR-MKT («Marketing – 1.er semestre», renovada por «Marketing – 2.º
 * semestre»). La bolsa de otro cliente es «Bolsa SEO» (SON-SEO, Clínica Dental Sonrisas).
 * Nunca contra el servidor (playwright.config.ts).
 */

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function download(page: Page, action: () => Promise<void>) {
    const [file] = await Promise.all([page.waitForEvent('download'), action()]);
    const path = await file.path();

    return { name: file.suggestedFilename(), bytes: fs.readFileSync(path) };
}

/** Id de una bolsa de otro cliente, visto por el admin en la vista global de bolsas. */
async function otherClientBankId(
    browser: Browser,
    baseURL: string,
): Promise<string> {
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();

    await login(page, USERS.admin);
    await page.goto('/bolsas?estado=todas');
    const href = await page
        .getByRole('link', { name: /Bolsa SEO/ })
        .first()
        .getAttribute('href');
    await context.close();

    const match = /\/bolsas\/(\d+)$/.exec(
        new URL(href ?? '', 'http://x').pathname,
    );
    expect(match, 'enlace a la «Bolsa SEO» en /bolsas').toBeTruthy();

    return match![1];
}

async function expectNoHorizontalScroll(page: Page, label: string) {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(
        overflow,
        `${label}: sin scroll horizontal de página`,
    ).toBeLessThanOrEqual(0);
}

test('el cliente ve su bolsa, abre el detalle, descarga el PDF y no entra en la app interna ni en la bolsa de otro cliente', async ({
    page,
    browser,
    baseURL,
}) => {
    const foreignId = await otherClientBankId(
        browser,
        baseURL ?? 'http://127.0.0.1:8000',
    );

    await login(page, USERS.client);
    await expect(page).toHaveURL(/\/portal\/?$/);

    await test.step('inicio: resumen, bolsas activas, anteriores y la nota de qué horas ve', async () => {
        await expect(page.getByRole('heading', { level: 1 })).toContainText(
            'Hola',
        );
        await expect(
            page.getByText(/Solo se muestran las horas ya aprobadas/),
        ).toBeVisible();
        await expect(
            page.getByRole('region', { name: 'Resumen' }),
        ).toBeVisible();

        const active = page.getByRole('region', { name: 'Tus bolsas activas' });
        await expect(
            active.getByRole('article', { name: 'Bolsa Diseño' }),
        ).toBeVisible();
        await expect(
            active.getByRole('meter', { name: 'Consumo de Bolsa Diseño' }),
        ).toBeVisible();
        await expect(
            page
                .getByRole('region', { name: 'Bolsas anteriores' })
                .getByRole('link', { name: 'Marketing – 1.er semestre' }),
        ).toBeVisible();
        // Nunca importes.
        await expect(page.locator('main')).not.toContainText('€');
    });

    await test.step('detalle: cifras, consumo por mes, horas y PDF', async () => {
        await page
            .getByRole('region', { name: 'Tus bolsas activas' })
            .getByRole('link', { name: 'Bolsa Diseño', exact: true })
            .click();
        await expect(page).toHaveURL(/\/portal\/bolsas\/\d+$/);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Bolsa Diseño' }),
        ).toBeVisible();
        await expect(
            page.getByRole('meter', { name: 'Consumo de Bolsa Diseño' }),
        ).toBeVisible();
        await expect(
            page.getByRole('figure', { name: 'Consumo por mes' }),
        ).toBeVisible();
        await expect(
            page.getByRole('region', { name: 'Tabla de horas' }),
        ).toBeVisible();
        await expect(page.locator('main')).not.toContainText('€');

        const pdf = await download(page, () =>
            page.getByRole('link', { name: /Descargar PDF/ }).click(),
        );
        expect(pdf.name).toMatch(/^ARR-WEB-consumo-bolsa-diseno-.*\.pdf$/);
        expect(pdf.bytes.subarray(0, 5).toString()).toBe('%PDF-');
    });

    await test.step('la app interna le devuelve a su portal', async () => {
        for (const path of ['/proyectos', '/bolsas', '/informes', '/']) {
            await page.goto(path);
            await expect(page, `${path} debe devolver al portal`).toHaveURL(
                /\/portal\/?$/,
            );
        }
    });

    await test.step('la bolsa de otro cliente da 404 (detalle y PDF)', async () => {
        const detail = await page.goto(`/portal/bolsas/${foreignId}`);
        expect(detail?.status()).toBe(404);
        await expect(page.locator('[data-test="error-page"]')).toBeVisible();

        const file = await page.request.get(`/portal/bolsas/${foreignId}/pdf`);
        expect(file.status()).toBe(404);
    });
});

test('las horas se filtran por mes y el histórico de renovaciones enlaza a la bolsa anterior', async ({
    page,
}) => {
    await login(page, USERS.client);
    const active = page.getByRole('region', { name: 'Tus bolsas activas' });

    await test.step('filtro por mes en la URL (?mes=AAAA-MM) y vuelta a todos los meses', async () => {
        await active
            .getByRole('link', { name: 'Bolsa Diseño', exact: true })
            .click();
        const month = page.getByLabel('Mes', { exact: true });
        await month.selectOption({ index: 1 });
        await expect(page).toHaveURL(/[?&]mes=\d{4}-\d{2}/);
        await expect(page.getByText(/^Total de /)).toBeVisible();
        await month.selectOption('');
        await expect(page).not.toHaveURL(/mes=/);
    });

    await test.step('cadena de renovaciones con la actual marcada y enlace a la anterior', async () => {
        await page.goto('/portal');
        await active
            .getByRole('link', {
                name: 'Marketing – 2.º semestre',
                exact: true,
            })
            .click();
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Marketing – 2.º semestre',
            }),
        ).toBeVisible();

        const chain = page.getByRole('list', {
            name: 'Renovaciones de Marketing – 2.º semestre',
        });
        await expect(chain.locator('[aria-current="page"]')).toContainText(
            'Marketing – 2.º semestre',
        );

        await chain
            .getByRole('link', { name: 'Marketing – 1.er semestre' })
            .click();
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Marketing – 1.er semestre',
            }),
        ).toBeVisible();
        await expect(page.getByText('Renovada').first()).toBeVisible();
    });
});

test('inicio y detalle: sin violaciones AA en claro y oscuro, y en el móvil (375 px) sin scroll horizontal', async ({
    page,
}) => {
    await login(page, USERS.client);
    const detailHref = await page
        .getByRole('region', { name: 'Tus bolsas activas' })
        .getByRole('link', { name: 'Bolsa Diseño', exact: true })
        .getAttribute('href');
    const pages = ['/portal', new URL(detailHref ?? '', 'http://x').pathname];

    for (const theme of ['light', 'dark'] as const satisfies readonly Theme[]) {
        // El login aplica el tema guardado en la cuenta: se fija aquí (F09).
        await saveUserTheme(page, theme);

        for (const url of pages) {
            await test.step(`${theme}: ${url}`, async () => {
                await page.goto(url);
                await page.waitForLoadState('networkidle');
                await expectTheme(page, theme);

                const results = await new AxeBuilder({ page })
                    .withTags(WCAG_AA)
                    .analyze();
                expect(
                    results.violations.map((item) => item.id),
                    `${url} (${theme})`,
                ).toEqual([]);
            });
        }
    }

    await page.setViewportSize({ width: 375, height: 812 });
    for (const url of pages) {
        await test.step(`375 px: ${url}`, async () => {
            await page.goto(url);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await expectNoHorizontalScroll(page, url);
        });
    }

    await saveUserTheme(page, 'system');
});
