import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * El informe de la weekly (Fase 10, entrega 10.3) con los datos de ejemplo del DemoDataSeeder: la
 * semana en curso abierta, con la weekly de Elena enviada (D-187). En la CI la IA y la locución son
 * las de prueba (GEMINI_DRIVER=fake y GOOGLE_TTS_DRIVER=fake, FakeLlm::demo) y la cola es síncrona,
 * así que el texto y el audio salen al momento. Quien gestiona genera el texto y el audio, cierra la
 * semana y se abre la siguiente; cualquiera de la plantilla ve el informe, lo imprime y lo descarga.
 * Nunca contra el servidor. Cierra la semana en curso: va en serie y al final de la Weekly.
 */

async function expectAccessible(page: Page, label: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    expect(results.violations.map((item) => `${label}: ${item.id}`)).toEqual(
        [],
    );
}

async function expectNoPageScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
}

/** Abre el informe de la semana activa desde la gestión de /weeklies. */
async function openActiveReport(page: Page): Promise<string> {
    await page.goto('/weeklies');
    const manage = page.locator('[data-test="weekly-manage"]');
    await expect(manage).toBeVisible();
    await manage.getByRole('link', { name: 'Ver el informe' }).click();
    await expect(page).toHaveURL(/\/weeklies\/\d+$/);
    await expect(
        page.locator('[data-test="weekly-report-view"]'),
    ).toBeVisible();

    return page.url();
}

test.describe.serial('el informe de la weekly', () => {
    let reportUrl = '';
    let closedLabel = '';

    test('quien gestiona genera el texto y el audio con la IA de prueba y cierra la semana', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page, USERS.admin);
        reportUrl = await openActiveReport(page);
        closedLabel = (
            await page.getByRole('heading', { level: 1 }).textContent()
        )?.trim() as string;

        await test.step('sin texto ni audio, no se puede cerrar', async () => {
            await expect(
                page.locator('[data-test="weekly-close-hint"]'),
            ).toContainText('Bloqueado: falta');
        });

        await test.step('genera el texto: el informe trae el resumen y los clientes', async () => {
            await page
                .locator('[data-test="weekly-generate-text"]')
                .first()
                .click();
            await expect(
                page.getByText(
                    'Resumen global de prueba generado sin IA (GEMINI_DRIVER=fake).',
                ),
            ).toBeVisible();
            await expect(
                page.locator('[data-test="weekly-client-card"]').first(),
            ).toBeVisible();
            await expect(
                page
                    .getByRole('navigation', { name: 'Índice de clientes' })
                    .first()
                    .getByRole('link')
                    .first(),
            ).toBeVisible();
        });

        await test.step('genera el audio: el reproductor y uno por cliente', async () => {
            await page
                .locator('[data-test="weekly-generate-audio"]')
                .first()
                .click();
            await expect(
                page.locator('[data-test="weekly-audio-main"]'),
            ).toBeVisible();
            await expect(
                page.locator('[data-test="weekly-audio-inline"]').first(),
            ).toBeVisible();
        });

        await test.step('edita el resumen de un cliente a mano', async () => {
            await page.locator('[data-test="weekly-edit"]').first().click();
            const dialog = page.getByRole('dialog');
            await dialog
                .getByLabel('Resumen ejecutivo')
                .first()
                .fill('Resumen editado a mano en el E2E.');
            await dialog.locator('[data-test="weekly-edit-save"]').click();
            await expect(dialog).toHaveCount(0);
            await expect(
                page.getByText('Resumen editado a mano en el E2E.'),
            ).toBeVisible();
        });

        await expectAccessible(page, 'informe de la weekly');

        await test.step('cierra la semana y se abre la siguiente', async () => {
            await expect(
                page.locator('[data-test="weekly-close-hint"]'),
            ).toContainText(/Puedes cerrar|Lista para cerrar/);
            await page.locator('[data-test="weekly-close"]').first().click();
            const dialog = page.getByRole('dialog');
            await dialog.locator('[data-test="weekly-close-confirm"]').click();
            await expect(dialog).toHaveCount(0);
            await expect(
                page.getByText(/Se ha abierto la siguiente/),
            ).toBeVisible();
            await expect(page.getByText(/Cerrada/).first()).toBeVisible();
            await expect(
                page.locator('[data-test="weekly-generate-text"]').first(),
            ).toBeDisabled();

            await page.goto('/weeklies?pestana=historico');
            const active = page.locator('[data-test="weekly-active-card"]');
            await expect(active).toBeVisible();
            await expect(active).not.toContainText(closedLabel);
        });
    });

    test('cualquiera de la plantilla ve el informe cerrado, lo filtra, lo imprime y lo descarga', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        test.skip(
            reportUrl === '',
            'Necesita la semana cerrada del paso anterior',
        );
        await login(page, USERS.employee);
        await page.goto(reportUrl);

        await expect(
            page.getByText('Resumen editado a mano en el E2E.'),
        ).toBeVisible();
        await expect(
            page.locator('[data-test="weekly-generate-text"]'),
        ).toHaveCount(0);
        await expect(page.locator('[data-test="weekly-close"]')).toHaveCount(0);

        await test.step('«Solo mis proyectos» y pantalla completa', async () => {
            await page
                .getByRole('button', { name: 'Solo mis proyectos' })
                .click();
            await expect(
                page.getByRole('button', { name: 'Solo mis proyectos' }),
            ).toHaveAttribute('aria-pressed', 'true');
            await page
                .getByRole('button', { name: 'Ver a pantalla completa' })
                .click();
            await expect(
                page.locator('[data-test="weekly-report-view"]'),
            ).toHaveAttribute('data-fullscreen', 'true');
            await page.keyboard.press('Escape');
            await expect(
                page.locator('[data-test="weekly-report-view"]'),
            ).toHaveAttribute('data-fullscreen', 'false');
        });

        await test.step('imprimir y descargar el HTML (el «PDF» de la CI es el HTML)', async () => {
            const print = await page.request.get(
                `${reportUrl}/informe/pdf?formato=imprimir`,
            );
            expect(print.ok()).toBe(true);
            expect(await print.text()).toContain('window.print()');

            const html = await page.request.get(
                `${reportUrl}/informe/pdf?formato=html`,
            );
            expect(html.ok()).toBe(true);
            expect(html.headers()['content-disposition']).toContain(
                'attachment',
            );
            expect(await html.text()).toContain('Resumen global');

            const audio = await page.request.get(
                `${reportUrl}/audio?descargar=1`,
            );
            expect(audio.ok()).toBe(true);
        });

        await test.step('en el móvil (375 px), el índice y las acciones van en paneles', async () => {
            await page.setViewportSize({ width: 375, height: 812 });
            await page.goto(reportUrl);
            await expectNoPageScroll(page);
            await page.locator('[data-test="weekly-index-mobile"]').click();
            await expect(
                page.getByRole('dialog').getByRole('navigation', {
                    name: 'Índice de clientes',
                }),
            ).toBeVisible();
            await page.keyboard.press('Escape');
            await page.locator('[data-test="weekly-actions-mobile"]').click();
            await expect(
                page.getByRole('dialog').getByText('Generativas y descargas'),
            ).toBeVisible();
            await expectAccessible(page, 'informe (móvil)');
        });
    });

    test('«Uso de IA» lo ve el admin con las llamadas de prueba', async ({
        page,
    }) => {
        await login(page, USERS.admin);
        await page.goto('/admin/uso-ia');
        await expect(
            page.getByRole('heading', { name: 'Uso de IA', level: 1 }),
        ).toBeVisible();
        await expectAccessible(page, 'Uso de IA');
    });
});
