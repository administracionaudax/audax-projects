import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Un cliente solo usa el portal (SPEC §5 y §11): tras iniciar sesión va a /portal
 * y cualquier sección interna le devuelve allí (middleware "internal").
 */
test('el cliente entra al portal y no puede abrir secciones internas', async ({
    page,
}) => {
    await login(page, USERS.client);
    await expect(page).toHaveURL(/\/portal\/?$/);

    for (const path of ['/proyectos', '/', '/horas', '/admin']) {
        await page.goto(path);
        await expect(page, `${path} debe devolver al portal`).toHaveURL(
            /\/portal\/?$/,
        );
    }
});
