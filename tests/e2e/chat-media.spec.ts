import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Audios, adjuntos y búsqueda del chat (Fase 6, área C3) sobre los datos de ejemplo:
 * - Elena (empleado@example.com) es miembro de ARR-WEB «Web corporativa»; Irene (Marketing) no.
 * - La CI arranca con el motor falso de transcripción y la cola síncrona
 *   (TRANSCRIPTION_DRIVER=fake y TRANSCRIPTION_QUEUE_CONNECTION=sync en su .env, ci.yml): el audio
 *   sale transcrito con el texto del FakeTranscriber («Transcripción de prueba»). Los datos de
 *   ejemplo del chat no dicen «prueba» en ningún mensaje.
 * - Chromium graba del micrófono falso (--use-fake-device-for-media-stream) sin pedir permiso.
 * - El editor y el botón «Enviar» son de C1 (chat; con archivos pendientes los publica aunque no
 *   haya texto); el clip, el grabador y la bandeja, de C3.
 * Nunca contra el servidor (playwright.config.ts).
 */

test.use({
    permissions: ['microphone'],
    launchOptions: {
        args: [
            '--use-fake-ui-for-media-stream',
            '--use-fake-device-for-media-stream',
        ],
    },
});

const MARKETING = 'irene.castro@example.com';

/** PDF mínimo que fileinfo reconoce como application/pdf. */
const PDF = Buffer.from(
    '%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n',
);

async function openProjectChat(page: Page): Promise<void> {
    await page.goto('/proyectos');
    await page
        .getByRole('link', { name: /Web corporativa/ })
        .first()
        .click();
    await page
        .getByRole('navigation', { name: 'Secciones del proyecto' })
        .getByRole('link', { name: 'Chat' })
        .click();
    await expect(page.locator('[data-test="chat-dropzone"]')).toBeVisible();
}

/**
 * Graba hasta que el contador marca 2 segundos (los audios de menos de 1 s no se envían): se
 * espera a lo que se ve, no un tiempo fijo.
 */
async function recordTwoSeconds(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Grabar un audio' }).click();
    await expect(page.getByRole('timer')).toHaveText(/^0:0[2-9] \//, {
        timeout: 10_000,
    });
}

async function asUser(browser: Browser, email: string): Promise<Page> {
    const context = await browser.newContext({
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
    });
    const page = await context.newPage();
    await login(page, email);

    return page;
}

test('adjuntar un archivo y encontrarlo por su nombre (y quien no ve el chat, no)', async ({
    page,
    browser,
}) => {
    const name = `acta-${Date.now()}.pdf`;
    await login(page, USERS.employee);
    await openProjectChat(page);

    await page.locator('[data-test="chat-attach-input"]').setInputFiles({
        name,
        mimeType: 'application/pdf',
        buffer: PDF,
    });
    await expect(page.locator('[data-test="chat-pending-file"]')).toContainText(
        name,
    );

    await page.getByRole('button', { name: 'Enviar', exact: true }).click();

    await expect(
        page.getByRole('link', { name: new RegExp(`Descargar «${name}»`) }),
    ).toBeVisible();
    await expect(page.locator('[data-test="chat-media-tray"]')).toHaveCount(0);

    await page.goto(`/chat/buscar?q=${encodeURIComponent(name)}`);
    const result = page.locator('[data-test="chat-search-result"]').first();
    await expect(result).toContainText('Archivo');
    await expect(result).toContainText('ARR-WEB · Web corporativa');
    await expect(result).toHaveAttribute('href', /\/chat\/\d+\?mensaje=\d+$/);

    const outsider = await asUser(browser, MARKETING);
    await outsider.goto(`/chat/buscar?q=${encodeURIComponent(name)}`);
    await expect(
        outsider.getByText(`No hay resultados para «${name}»`),
    ).toBeVisible();
    await outsider.context().close();
});

test('grabar un audio y encontrar una palabra de su transcripción', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await openProjectChat(page);

    await recordTwoSeconds(page);
    await page.getByRole('button', { name: 'Enviar audio' }).click();

    const audio = page.locator('[data-test="chat-audio-message"]').last();
    await expect(audio).toBeVisible();
    await expect(
        audio.getByRole('slider', { name: 'Posición del audio' }),
    ).toBeVisible();
    await expect(
        audio.getByRole('button', { name: /Ver la transcripción/ }),
    ).toContainText('Transcripción de prueba');

    await audio.getByRole('button', { name: /Ver la transcripción/ }).click();
    await expect(
        audio.getByRole('button', { name: 'Copiar la transcripción' }),
    ).toBeVisible();

    await page.goto('/chat/buscar?q=prueba&tipo=audios');
    const result = page.locator('[data-test="chat-search-result"]').first();
    await expect(result).toContainText('Audio');
    await expect(result.locator('mark')).toHaveText('prueba');

    await result.click();
    await expect(page).toHaveURL(/\/chat\/\d+\?mensaje=\d+$/);
});

test('la búsqueda global (Ctrl+K) también encuentra lo dicho en los audios', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await openProjectChat(page);
    await recordTwoSeconds(page);
    await page.getByRole('button', { name: 'Enviar audio' }).click();
    await expect(
        page.locator('[data-test="chat-audio-message"]').last(),
    ).toContainText('Transcripción de prueba');

    await page.keyboard.press('Control+k');
    await page
        .getByRole('combobox', { name: 'Buscar en la aplicación' })
        .fill('prueba');
    const option = page
        .getByRole('group', { name: 'Mensajes' })
        .getByRole('option')
        .first();
    await expect(option).toContainText('Audio · Web corporativa');
    await option.click();
    await expect(page).toHaveURL(/\/chat\/\d+\?mensaje=\d+$/);
});
