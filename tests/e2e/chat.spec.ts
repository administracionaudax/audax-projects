import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, requireRealtime, USERS } from './support';

/**
 * Chat (Fase 6, aceptación del SPEC §17): dos personas en navegadores distintos chatean EN TIEMPO
 * REAL. Ana (admin@example.com) y Elena (empleado@example.com) tienen abierta a la vez su directa;
 * la página de Elena NUNCA se recarga mientras Ana escribe:
 * - Elena ve «Ana está escribiendo…» y después el mensaje,
 * - Ana ve «Leído» cuando Elena lo ha visto,
 * - Elena responde en hilo citándolo, reacciona y adjunta un archivo; Ana lo ve todo sin recargar.
 *
 * Sin tiempo real la interfaz consulta cada 10 s (y con él, cada 60 s como red de seguridad):
 * cada paso espera como mucho LIVE, menos que cualquier consulta periódica, así que llegar a tiempo
 * demuestra que ha llegado por Reverb. En la CI Reverb es obligatorio (requireRealtime); fuera de
 * ella, sin Reverb, el test se salta con su motivo.
 * Datos del DatabaseSeeder de desarrollo (con el chat de ejemplo de DemoDataSeeder); nunca contra
 * el servidor (playwright.config.ts).
 */

/** Menos que la consulta periódica sin tiempo real (10 s): lo que llega antes, llega en vivo. */
const LIVE = { timeout: 8_000 };

/** PDF mínimo que fileinfo reconoce como application/pdf. */
const PDF = Buffer.from(
    '%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n',
);

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

/**
 * ¿Reverb ha confirmado ya la suscripción de la página a esos canales? Se registra antes de navegar
 * (escucha los websockets nuevos). El «escribiendo…» es un whisper efímero que Reverb solo reparte
 * a quien ya está suscrito a la conversación y que la interfaz solo acepta de quien está en la
 * presencia (D-120): si Ana escribe antes de que Elena termine de suscribirse, ese aviso se pierde
 * (y el siguiente no sale hasta 3 s después de la siguiente pulsación).
 */
function watchSubscriptions(page: Page, channels: RegExp[]): () => boolean {
    const pending = [...channels];

    page.on('websocket', (socket) => {
        socket.on('framereceived', ({ payload }) => {
            let frame: { event?: unknown; channel?: unknown };

            try {
                frame = JSON.parse(String(payload)) as typeof frame;
            } catch {
                return;
            }

            const name = frame.channel;

            if (
                frame.event !== 'pusher_internal:subscription_succeeded' ||
                typeof name !== 'string'
            ) {
                return;
            }

            const index = pending.findIndex((channel) => channel.test(name));

            if (index >= 0) {
                pending.splice(index, 1);
            }
        });
    });

    return () => pending.length === 0;
}

/** El editor de mensajes (un combobox: sugiere menciones). */
function composer(page: Page) {
    return page.getByRole('combobox', { name: 'Escribe un mensaje' });
}

/**
 * El mensaje con ese texto en su cuerpo. Las respuestas en hilo citan el mensaje original (hasta
 * 120 caracteres), así que se descartan los que solo lo contienen en la cita.
 */
function message(page: Page, text: string) {
    return page
        .locator('[data-test="chat-message"]')
        .filter({ hasText: text })
        .filter({
            hasNot: page.locator('[data-test="chat-parent-quote"]', {
                hasText: text,
            }),
        });
}

test('dos personas chatean en tiempo real: escribiendo, mensaje, leído, hilo, reacción y adjunto', async ({
    browser,
}) => {
    test.setTimeout(120_000);
    const stamp = Date.now().toString(36);
    const hello = `Hola Elena, ¿revisas la maqueta? ${stamp}`;
    const reply = `Sí, esta tarde ${stamp}`;
    const file = `boceto-${stamp}.pdf`;

    const ana = await asUser(browser, USERS.admin);
    const elena = await asUser(browser, USERS.employee);

    await test.step('las dos abren la misma directa', async () => {
        const conversationChannels = [
            /^presence-online$/,
            /^private-conversation\.\d+$/,
        ];
        const anaListening = watchSubscriptions(ana, conversationChannels);
        await openDirect(ana, 'Elena');
        const elenaListening = watchSubscriptions(elena, conversationChannels);
        await elena.goto(new URL(ana.url()).pathname);
        await requireRealtime(elena, (condition, reason) =>
            test.skip(condition, reason),
        );
        await expect(
            elena.locator('[data-test="chat-conversation-title"]'),
        ).toHaveText(/Ana Administración/);
        // Las dos escuchan ya la conversación (y se ven en la presencia) antes de que Ana escriba.
        await expect
            .poll(() => anaListening() && elenaListening(), {
                ...LIVE,
                message:
                    'Ana y Elena suscritas a la presencia y a la conversación',
            })
            .toBe(true);
    });

    await test.step('Elena ve «escribiendo…» mientras Ana escribe', async () => {
        await composer(ana).pressSequentially('Hola', { delay: 50 });
        await expect(elena.locator('[data-test="chat-typing"]')).toContainText(
            'escribiendo',
            LIVE,
        );
    });

    await test.step('Ana envía y Elena lo recibe sin recargar', async () => {
        await composer(ana).fill(hello);
        await composer(ana).press('Enter');
        await expect(message(ana, hello)).not.toContainText('Enviando…');
        await expect(message(elena, hello)).toBeVisible(LIVE);
        // Al llegar el mensaje, el «escribiendo…» de Ana se apaga.
        await expect(elena.locator('[data-test="chat-typing"]')).toHaveText(
            '',
            LIVE,
        );
    });

    await test.step('Ana ve que Elena lo ha leído («Leído», en una directa)', async () => {
        await expect(
            ana.locator('[data-test="chat-read-receipt"]'),
        ).toContainText('Leído', LIVE);
    });

    await test.step('Elena responde en hilo citando el mensaje y Ana lo ve con la cita', async () => {
        await message(elena, hello)
            .locator('[data-test="chat-message-actions"]')
            .click();
        await elena.getByRole('menuitem', { name: 'Responder' }).click();
        await expect(elena.getByText(/Respondiendo a Ana/)).toBeVisible();
        // El foco va al editor al responder.
        await expect(composer(elena)).toBeFocused();
        await composer(elena).fill(reply);
        await composer(elena).press('Enter');

        await expect(
            message(elena, reply).locator('[data-test="chat-parent-quote"]'),
        ).toContainText(hello.slice(0, 20));
        await expect(message(ana, reply)).toBeVisible(LIVE);
        await expect(
            message(ana, reply).locator('[data-test="chat-parent-quote"]'),
        ).toContainText(hello.slice(0, 20));
    });

    await test.step('Elena reacciona y Ana ve la reacción', async () => {
        await message(elena, hello)
            .locator('[data-test="chat-message-actions"]')
            .click();
        await elena.getByRole('menuitem', { name: 'Reaccionar' }).click();
        await elena.getByRole('button', { name: 'Reaccionar con 👍' }).click();

        await expect(
            message(elena, hello).locator('[data-test="chat-reaction"]'),
        ).toHaveAttribute('aria-pressed', 'true');
        await expect(
            message(ana, hello).locator('[data-test="chat-reaction"]'),
        ).toContainText('1', LIVE);
        await expect(
            message(ana, hello).locator('[data-test="chat-reaction"]'),
        ).toHaveAttribute('aria-label', /Elena/);
    });

    await test.step('Elena adjunta un archivo y Ana lo ve sin recargar', async () => {
        await elena.locator('[data-test="chat-attach-input"]').setInputFiles({
            name: file,
            mimeType: 'application/pdf',
            buffer: PDF,
        });
        await expect(
            elena.locator('[data-test="chat-pending-file"]'),
        ).toContainText(file);
        await elena
            .getByRole('button', { name: 'Enviar', exact: true })
            .click();

        await expect(
            ana.getByRole('link', { name: new RegExp(`Descargar «${file}»`) }),
        ).toBeVisible(LIVE);
    });

    await test.step('el enlace a un mensaje lo abre en su sitio', async () => {
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
    await composer(page).fill(text);
    await composer(page).press('Enter');
    await expect(message(page, text)).toBeVisible();
});
