import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Chat (Fase 6, aceptación del SPEC §17): dos personas en navegadores distintos.
 * - Ana (admin@example.com) abre una directa con Elena (empleado@example.com) y le escribe,
 * - Elena ve el mensaje (con tiempo real o, sin él, tras la consulta periódica de 10 s),
 *   responde en hilo citándolo y reacciona,
 * - Ana ve la respuesta con su cita y la reacción, y el «Leído por» de C2.
 * Datos del DatabaseSeeder de desarrollo (con el chat de ejemplo de DemoDataSeeder); nunca contra
 * el servidor (playwright.config.ts).
 */

/** Sin tiempo real, lo de la otra persona llega con la consulta periódica (10 s). */
const LIVE = { timeout: 25_000 };

/** «Leído por» (C2) sin tiempo real se consulta cada 30 s. */
const READ = { timeout: 40_000 };

async function asUser(browser: Browser, email: string): Promise<Page> {
    const context = await browser.newContext({
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
    });
    const page = await context.newPage();
    await login(page, email);

    return page;
}

async function openDirect(page: Page, name: string): Promise<void> {
    await page.goto('/chat');
    await page.locator('[data-test="chat-new"]').click();
    await page.getByRole('menuitem', { name: 'Mensaje directo' }).click();
    const dialog = page.getByRole('dialog', { name: 'Nuevo mensaje directo' });
    await dialog.getByPlaceholder('Buscar persona').fill(name);
    await dialog
        .getByRole('option', { name: new RegExp(name) })
        .first()
        .click();
    await expect(page).toHaveURL(/\/chat\/\d+$/);
    await expect(
        page.locator('[data-test="chat-conversation-title"]'),
    ).toHaveText(new RegExp(name));
}

function message(page: Page, text: string) {
    return page.locator('[data-test="chat-message"]').filter({ hasText: text });
}

test('dos personas chatean: mensaje, respuesta en hilo, reacción y leído', async ({
    browser,
}) => {
    // Sin Reverb, cada paso espera a su consulta periódica.
    test.setTimeout(150_000);
    const stamp = Date.now().toString(36);
    const hello = `Hola Elena, ¿revisas la maqueta? ${stamp}`;
    const reply = `Sí, esta tarde ${stamp}`;

    const ana = await asUser(browser, USERS.admin);
    const elena = await asUser(browser, USERS.employee);

    await test.step('Ana abre la directa con Elena y le escribe', async () => {
        await openDirect(ana, 'Elena');
        const input = ana.getByRole('textbox', { name: 'Escribe un mensaje' });
        await input.fill(hello);
        await input.press('Enter');
        await expect(message(ana, hello)).toBeVisible();
        await expect(message(ana, hello)).not.toContainText('Enviando…');
    });

    await test.step('Elena la ve en su lista con el mensaje sin leer y la abre', async () => {
        await elena.goto('/chat');
        // Por su nombre completo: los datos de ejemplo tienen más conversaciones (y «Ana» está
        // dentro de palabras como «mañana»).
        const item = elena
            .locator('[data-test="chat-conversation-item"]')
            .filter({ hasText: 'Ana Administración' });
        await expect(item).toContainText(hello.slice(0, 20), LIVE);
        await item.click();
        await expect(message(elena, hello)).toBeVisible(LIVE);
    });

    await test.step('Ana ve que Elena lo ha leído («Leído», en una directa)', async () => {
        await expect(
            ana.locator('[data-test="chat-read-receipt"]'),
        ).toContainText('Leído', READ);
    });

    await test.step('Elena responde en hilo citando el mensaje', async () => {
        await message(elena, hello)
            .locator('[data-test="chat-message-actions"]')
            .click();
        await elena.getByRole('menuitem', { name: 'Responder' }).click();
        await expect(elena.getByText(/Respondiendo a Ana/)).toBeVisible();

        const input = elena.getByRole('textbox', {
            name: 'Escribe un mensaje',
        });
        await input.fill(reply);
        await input.press('Enter');

        await expect(
            message(elena, reply).locator('[data-test="chat-parent-quote"]'),
        ).toContainText(hello.slice(0, 20));
    });

    await test.step('Elena reacciona al mensaje de Ana', async () => {
        await message(elena, hello)
            .locator('[data-test="chat-message-actions"]')
            .click();
        await elena.getByRole('menuitem', { name: 'Reaccionar' }).click();
        await elena.getByRole('button', { name: 'Reaccionar con 👍' }).click();

        await expect(
            message(elena, hello).locator('[data-test="chat-reaction"]'),
        ).toHaveAttribute('aria-pressed', 'true');
    });

    await test.step('Ana ve la respuesta con su cita y la reacción', async () => {
        await expect(message(ana, reply)).toBeVisible(LIVE);
        await expect(
            message(ana, reply).locator('[data-test="chat-parent-quote"]'),
        ).toContainText(hello.slice(0, 20));
        await expect(
            message(ana, hello).locator('[data-test="chat-reaction"]'),
        ).toContainText('1', LIVE);
        await expect(
            message(ana, hello).locator('[data-test="chat-reaction"]'),
        ).toHaveAttribute('aria-label', /Elena/);
    });

    await test.step('el enlace a un mensaje lo abre en su sitio', async () => {
        await ana.reload();
        const id = await message(ana, hello).getAttribute('data-message-id');
        const conversation = new URL(ana.url()).pathname.split('/').pop();
        await elena.goto(`/chat/${conversation}?mensaje=${id}`);
        await expect(message(elena, hello)).toBeVisible();
    });

    await ana.context().close();
    await elena.context().close();
});

test('el chat del proyecto se abre desde su pestaña y se puede escribir', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/proyectos');
    await page
        .getByRole('link', { name: /Web corporativa/ })
        .first()
        .click();
    await page
        .getByRole('navigation', { name: 'Secciones del proyecto' })
        .getByRole('link', { name: 'Chat' })
        .click();

    await expect(page).toHaveURL(/\/proyectos\/\d+\/chat$/);
    const text = `Aviso para el equipo ${Date.now().toString(36)}`;
    const input = page.getByRole('textbox', { name: 'Escribe un mensaje' });
    await input.fill(text);
    await input.press('Enter');
    await expect(message(page, text)).toBeVisible();
});
