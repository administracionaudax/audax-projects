import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Tiempo real (Fase 6, área C2) con dos navegadores. Necesita Reverb en marcha y
 * BROADCAST_CONNECTION=reverb (en la CI, Reverb local): si la página no trae la prop `realtime`,
 * el test se salta (sin Reverb, la campana consulta cada 60 s).
 * El chat en vivo con todas sus funciones (mensaje, escribiendo, leído, reacción, hilo, adjunto)
 * está en tests/e2e/chat.spec.ts.
 */

type PageData = {
    props: {
        auth: { user: { id: number; name: string } | null };
        realtime?: unknown;
    };
};

async function asUser(browser: Browser, email: string): Promise<Page> {
    const context = await browser.newContext({
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
    });
    const page = await context.newPage();
    await login(page, email);

    return page;
}

/** Props de la primera visita (Inertia las deja en <script data-page="app">). */
async function pageData(page: Page): Promise<PageData> {
    const json = await page
        .locator('script[data-page="app"]')
        .first()
        .textContent();

    return JSON.parse(json ?? '{}') as PageData;
}

async function xsrfToken(page: Page): Promise<string> {
    const cookie = (await page.context().cookies()).find(
        (item) => item.name === 'XSRF-TOKEN',
    );
    expect(cookie, 'cookie XSRF-TOKEN tras el login').toBeDefined();

    return decodeURIComponent(cookie?.value ?? '');
}

test('la campana de otra persona se actualiza al momento, sin recargar, cuando la mencionas', async ({
    browser,
}) => {
    const elena = await asUser(browser, USERS.employee);
    await elena.goto('/');
    const data = await pageData(elena);
    test.skip(
        !data.props.realtime,
        'Sin Reverb (prop realtime null): la campana consulta cada 60 s.',
    );

    const elenaId = data.props.auth.user?.id;
    expect(elenaId, 'id de Elena en las props').toBeDefined();
    const bell = elena.getByRole('button', { name: /^Notificaciones/ });
    await expect(bell).toBeVisible();
    const before = (await bell.getAttribute('aria-label')) ?? '';
    const unread = Number(/(\d+) sin leer/.exec(before)?.[1] ?? 0);

    const ana = await asUser(browser, USERS.admin);
    const search = await ana.request.get('/buscar?q=Prototipo', {
        headers: { Accept: 'application/json' },
    });
    expect(search.ok()).toBe(true);
    const results = (
        (await search.json()) as {
            results: Array<{ type: string; id: number }>;
        }
    ).results;
    const task = results.find((result) => result.type === 'task');
    expect(task, 'una tarea de los datos de ejemplo').toBeDefined();

    // Ana comenta mencionando a Elena (el admin comenta en cualquier tarea, D-031).
    const response = await ana.request.post(`/tareas/${task?.id}/comentarios`, {
        headers: {
            'X-XSRF-TOKEN': await xsrfToken(ana),
            Accept: 'text/html',
        },
        form: {
            body: `<p><span data-type="mention" data-id="${elenaId}">@Elena</span> revisa esto (prueba de tiempo real)</p>`,
        },
        maxRedirects: 0,
    });
    expect(response.status()).toBeLessThan(400);

    // Sin recargar: llega por el canal personal (App.Models.User.{id}).
    await expect(
        elena.getByRole('button', {
            name: `Notificaciones: ${unread + 1} sin leer`,
        }),
    ).toBeVisible({ timeout: 15_000 });

    await elena
        .getByRole('button', { name: `Notificaciones: ${unread + 1} sin leer` })
        .click();
    await expect(
        elena
            .getByText(/Ana Administración te ha mencionado en un comentario/)
            .first(),
    ).toBeVisible();

    await ana.context().close();
    await elena.context().close();
});

test('un cliente nunca se suscribe a los canales de tiempo real', async ({
    browser,
}) => {
    const client = await asUser(browser, USERS.client);
    const response = await client.request.post('/broadcasting/auth', {
        headers: {
            'X-XSRF-TOKEN': await xsrfToken(client),
            Accept: 'application/json',
        },
        form: { socket_id: '1234.5678', channel_name: 'presence-online' },
        maxRedirects: 0,
    });

    expect([302, 401, 403]).toContain(response.status());

    await client.context().close();
});
