import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Orden de las tarjetas de Inicio (D-138): se reordenan con el teclado desde su asa, el orden se
 * guarda al soltar (PUT /inicio/orden), se mantiene al recargar y «Restablecer orden» vuelve al
 * de por defecto (DELETE /inicio/orden). Cada test deja a la persona con el orden por defecto
 * para no afectar a otros E2E que abren Inicio.
 */

/** Ids de las tarjetas en el orden en que se pintan. */
async function cardOrder(page: Page): Promise<string[]> {
    return page
        .locator('[data-home-card]')
        .evaluateAll((cards) =>
            cards.map((card) => card.getAttribute('data-home-card') ?? ''),
        );
}

function layoutResponse(page: Page, method: 'PUT' | 'DELETE') {
    return page.waitForResponse(
        (response) =>
            new URL(response.url()).pathname === '/inicio/orden' &&
            response.request().method() === method,
    );
}

/** Región viva de dnd-kit con los anuncios del arrastre (hay otras regiones `status` en Inicio). */
function liveRegion(page: Page) {
    return page.locator('[role="status"][id^="DndLiveRegion"]');
}

/**
 * Coge la tarjeta con espacio. dnd-kit escucha las flechas a partir del siguiente ciclo de
 * eventos tras cogerla: una persona nunca es tan rápida, pero Playwright sí.
 */
async function pickUp(page: Page, expected: string): Promise<void> {
    await page.keyboard.press('Space');
    await expect(liveRegion(page)).toContainText(expected);
    await page.waitForTimeout(100);
}

/** Si quedó un orden guardado (p. ej. de un intento anterior), se restablece. */
async function resetIfSaved(page: Page): Promise<void> {
    const reset = page.getByRole('button', { name: 'Restablecer orden' });

    if (await reset.isVisible()) {
        const deleted = layoutResponse(page, 'DELETE');
        await reset.click();
        expect((await deleted).status()).toBe(204);
        await expect(reset).toBeHidden();
    }
}

test.describe('Inicio · orden de las tarjetas', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, USERS.employee);
        await page.goto('/');
        await expect(page.locator('[data-home-card]').first()).toBeVisible();
        await resetIfSaved(page);
    });

    test.afterEach(async ({ page }) => {
        await page.goto('/');
        await resetIfSaved(page);
    });

    test('reordena con el teclado, lo mantiene al recargar y lo restablece', async ({
        page,
    }) => {
        const initial = await cardOrder(page);
        expect(initial.slice(0, 2)).toEqual(['today-tasks', 'timer']);
        await expect(
            page.getByRole('button', { name: 'Restablecer orden' }),
        ).toBeHidden();

        // El asa del temporizador: espacio para cogerla, flecha a la izquierda y espacio para soltarla.
        const handle = page.getByRole('button', {
            name: 'Mover la tarjeta «Temporizador»',
        });
        await handle.focus();
        await expect(handle).toBeVisible();
        const live = liveRegion(page);
        await pickUp(
            page,
            'Has cogido la tarjeta «Temporizador». Está en la posición 2',
        );
        await page.keyboard.press('ArrowLeft');
        await expect(live).toContainText(
            'La tarjeta «Temporizador» está en la posición 1',
        );
        const saved = layoutResponse(page, 'PUT');
        await page.keyboard.press('Space');

        const response = await saved;
        expect(response.status()).toBe(204);
        expect(response.request().postDataJSON()).toEqual({
            cards: ['timer', 'today-tasks', ...initial.slice(2)],
        });

        await expect(live).toContainText(
            'Has soltado la tarjeta «Temporizador» en la posición 1',
        );
        const moved = await cardOrder(page);
        expect(moved.slice(0, 2)).toEqual(['timer', 'today-tasks']);

        // Se mantiene al recargar.
        await page.reload();
        await expect(page.locator('[data-home-card]').first()).toBeVisible();
        expect(await cardOrder(page)).toEqual(moved);

        // «Restablecer orden» vuelve al orden por defecto, también tras recargar.
        const reset = page.getByRole('button', { name: 'Restablecer orden' });
        await expect(reset).toBeVisible();
        const deleted = layoutResponse(page, 'DELETE');
        await reset.click();
        expect((await deleted).status()).toBe(204);
        await expect(reset).toBeHidden();
        expect(await cardOrder(page)).toEqual(initial);
        await expect(page.getByRole('heading', { level: 1 })).toBeFocused();

        await page.reload();
        await expect(page.locator('[data-home-card]').first()).toBeVisible();
        expect(await cardOrder(page)).toEqual(initial);
    });

    test('Escape cancela el movimiento sin guardar', async ({ page }) => {
        const initial = await cardOrder(page);
        let requests = 0;
        page.on('request', (request) => {
            if (new URL(request.url()).pathname === '/inicio/orden') {
                requests++;
            }
        });

        await page
            .getByRole('button', { name: 'Mover la tarjeta «Temporizador»' })
            .focus();
        const live = liveRegion(page);
        await pickUp(page, 'Has cogido la tarjeta «Temporizador»');
        await page.keyboard.press('ArrowLeft');
        await expect(live).toContainText('está en la posición 1');
        await page.keyboard.press('Escape');
        await expect(live).toContainText(
            'Movimiento cancelado. La tarjeta «Temporizador» vuelve a su sitio.',
        );

        await expect.poll(() => cardOrder(page)).toEqual(initial);
        expect(requests).toBe(0);
    });
});
