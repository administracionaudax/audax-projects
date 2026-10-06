import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Revisión de formularios (D-310 a D-319): fallos de comportamiento que solo se ven con el servidor
 * de verdad (props diferidas canceladas, datos que no se resincronizan tras guardar).
 */

const CLIENT_NAME = 'Bodegas Arrieta';

test('una acción en la ficha del cliente antes de que llegue su Weekly no la deja en «Cargando…»', async ({
    page,
}) => {
    await login(page, USERS.admin);

    // La carga diferida de la Weekly del cliente tarda: la primera vez se retiene 4 s (y la
    // cancelará la acción); las siguientes pasan sin esperar.
    let weeklyRequests = 0;
    await page.route('**/clientes/*', async (route) => {
        const partial = route.request().headers()['x-inertia-partial-data'];

        if (partial?.split(',').includes('weekly')) {
            weeklyRequests++;

            if (weeklyRequests === 1) {
                await new Promise((resolve) => setTimeout(resolve, 4000));
            }
        }

        await route.continue().catch(() => undefined);
    });

    await page.goto('/clientes');
    await page.getByRole('link', { name: CLIENT_NAME, exact: true }).click();
    const portal = page.locator('[data-test="client-portal"]');
    await expect(portal).toBeVisible();

    // Una visita parcial a otra ruta (invitar al portal) mientras la Weekly aún no ha llegado.
    await portal.getByRole('button', { name: 'Invitar al portal' }).click();
    const dialog = page.getByRole('dialog', {
        name: `Invitar al portal de ${CLIENT_NAME}`,
    });
    await dialog.getByLabel('Nombre').fill('Persona diferida');
    await dialog
        .getByLabel('Correo electrónico')
        .fill(`diferida-${Date.now()}@example.com`);
    await dialog.getByRole('button', { name: 'Enviar la invitación' }).click();
    await expect(dialog).toBeHidden();

    // La Weekly se vuelve a pedir y llega: no se queda «Cargando la Weekly del cliente…».
    await expect(page.getByText('Cargando la Weekly del cliente…')).toHaveCount(
        0,
        { timeout: 15_000 },
    );
    expect(weeklyRequests).toBeGreaterThanOrEqual(2);
});

test('guardar dos veces los avisos de la weekly no vuelve a crear las reglas nuevas', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/weeklies/avisos');

    const before = await page.locator('[data-test="reminder-rule"]').count();
    await page.locator('[data-test="reminder-rule-add"]').click();
    await expect(page.locator('[data-test="reminder-rule"]')).toHaveCount(
        before + 1,
    );

    const bodies: { rules: { id: number | null }[] }[] = [];
    page.on('request', (request) => {
        if (
            request.method() === 'PUT' &&
            request.url().includes('/weeklies/avisos')
        ) {
            bodies.push(request.postDataJSON());
        }
    });

    await page.locator('[data-test="reminders-save"]').click();
    await expect(
        page.getByText('Avisos de la weekly guardados.'),
    ).toBeVisible();
    const second = page.waitForResponse(
        (response) =>
            response.request().method() === 'PUT' &&
            response.url().includes('/weeklies/avisos'),
    );
    await page.locator('[data-test="reminders-save"]').click();
    await second;
    // Hasta que termina (y se resincroniza el formulario) el botón está desactivado.
    await expect(page.locator('[data-test="reminders-save"]')).toBeEnabled();
    await page.waitForTimeout(300);
    await expect.poll(() => bodies.length).toBe(2);

    // En el segundo guardado, la regla nueva ya lleva su id (antes se borraba y se creaba otra).
    expect(bodies[0].rules.at(-1)?.id).toBeNull();
    expect(bodies[1].rules.at(-1)?.id).not.toBeNull();

    // Se deja como estaba.
    await page
        .locator('[data-test="reminder-rule"]')
        .last()
        .locator('[data-test="reminder-rule-remove"]')
        .click();
    await expect(page.locator('[data-test="reminder-rule"]')).toHaveCount(
        before,
    );
    const third = page.waitForResponse(
        (response) =>
            response.request().method() === 'PUT' &&
            response.url().includes('/weeklies/avisos'),
    );
    await page.locator('[data-test="reminders-save"]').click();
    await third;
    await page.reload();
    await expect(page.locator('[data-test="reminder-rule"]')).toHaveCount(
        before,
    );
});
