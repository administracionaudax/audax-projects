import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { COLLABORATOR_USER, login, USERS } from './support';

/**
 * Canales del chat y la lista en tres niveles (D-270 a D-273): el admin crea un canal de equipo,
 * la plantilla lo ve en «Canales» y escribe en él, se sale y se vuelve a entrar; el canal del
 * cliente se abre desde su ficha; lo plegado se recuerda al recargar; a 375 px no hay scroll
 * horizontal; y una colaboradora no ve los canales de equipo.
 */

const suffix = Date.now().toString(36);
const channelName = `Daily E2E ${suffix}`;

function composer(page: Page) {
    return page.getByRole('combobox', { name: 'Escribe un mensaje' });
}

function message(page: Page, text: string) {
    return page.locator('[data-test="chat-message"]').filter({ hasText: text });
}

/** Los data-test de la app (Playwright busca data-testid por defecto). */
function tid(page: Page, id: string) {
    return page.locator(`[data-test="${id}"]`);
}

function section(page: Page, key: 'channels' | 'clients' | 'direct') {
    return page.locator(`[data-test="chat-section-${key}"]`);
}

test.describe.configure({ mode: 'serial' });

test('el admin crea un canal de equipo y escribe en él', async ({ page }) => {
    await login(page, USERS.admin);
    await page.goto('/chat');

    await tid(page, 'chat-new').click();
    await page.getByRole('menuitem', { name: 'Canal de equipo' }).click();
    const dialog = page.getByRole('dialog', { name: 'Nuevo canal de equipo' });
    await dialog.getByLabel('Nombre del canal').fill(channelName);
    await dialog.getByRole('button', { name: 'Crear canal' }).click();

    await expect(page).toHaveURL(/\/chat\/\d+$/);
    await expect(tid(page, 'chat-conversation-title')).toHaveText(channelName);
    await expect(
        page.getByText(/Canal de equipo · \d+ personas/),
    ).toBeVisible();

    await composer(page).fill(`Buenos días ${suffix}`);
    await composer(page).press('Enter');
    await expect(message(page, `Buenos días ${suffix}`)).toBeVisible();
});

test('la plantilla lo ve en «Canales», escribe, sale y vuelve a entrar', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/chat');

    const channels = section(page, 'channels');
    await expect(tid(page, 'chat-section-toggle-channels')).toHaveAttribute(
        'aria-expanded',
        'true',
    );
    await channels.getByRole('link', { name: new RegExp(channelName) }).click();

    await expect(message(page, `Buenos días ${suffix}`)).toBeVisible();
    await composer(page).fill(`Hola desde la plantilla ${suffix}`);
    await composer(page).press('Enter');
    await expect(
        message(page, `Hola desde la plantilla ${suffix}`),
    ).toBeVisible();

    await tid(page, 'chat-channel-leave').click();
    await expect(tid(page, 'chat-channel-join')).toBeVisible();
    await tid(page, 'chat-channel-join').click();
    await expect(tid(page, 'chat-channel-leave')).toBeVisible();
});

test('los niveles se pliegan y se recuerdan al recargar', async ({ page }) => {
    await login(page, USERS.employee);
    await page.goto('/chat');

    const toggle = tid(page, 'chat-section-toggle-direct');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');

    await page.reload();
    await expect(tid(page, 'chat-section-toggle-direct')).toHaveAttribute(
        'aria-expanded',
        'false',
    );

    // Lo deja como estaba para el resto de E2E.
    await tid(page, 'chat-section-toggle-direct').click();
    await expect(tid(page, 'chat-section-toggle-direct')).toHaveAttribute(
        'aria-expanded',
        'true',
    );
});

test('el canal del cliente se abre desde su ficha y queda en «Proyectos y clientes»', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/clientes');
    await page
        .locator('main a[href^="/clientes/"]')
        .filter({ hasText: /\w/ })
        .first()
        .click();
    await expect(page).toHaveURL(/\/clientes\/\d+/);

    await tid(page, 'client-chat-channel').click();
    await expect(page).toHaveURL(/\/chat\/\d+$/);
    await expect(page.getByText('Canal del cliente').first()).toBeVisible();

    const text = `Novedades del cliente ${suffix}`;
    await composer(page).fill(text);
    await composer(page).press('Enter');
    await expect(message(page, text)).toBeVisible();

    // Y la lista lo enseña en «Proyectos y clientes», como cabecera de su cliente.
    const path = new URL(page.url()).pathname;
    await expect(
        section(page, 'clients').locator(`a[href="${path}"]`),
    ).toBeVisible();
});

test('a 375 px la lista se lee sin scroll horizontal', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.employee);
    await page.goto('/chat');

    await expect(section(page, 'channels')).toBeVisible();
    await expect(section(page, 'clients')).toBeVisible();
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
});

test('una colaboradora no ve los canales de equipo', async ({ page }) => {
    await login(page, COLLABORATOR_USER);
    await page.goto('/chat');

    await expect(page.getByText(channelName)).toHaveCount(0);
    await expect(section(page, 'direct')).toHaveCount(0);
});
