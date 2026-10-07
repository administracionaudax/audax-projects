import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Mejoras de uso del 07/10, segunda tanda (D-326 a D-329), con los datos de ejemplo del
 * DemoDataSeeder: el responsable trabaja en «Web corporativa» (ARR-WEB), de Bodegas Arrieta.
 * - La columna «Hecha» del kanban nace plegada, se despliega y se recuerda al recargar (D-326).
 * - Una tarjeta se suelta en la columna plegada y pasa a «Hecha» sin desplegarla (D-326).
 * - A 375 px, los proyectos son tarjetas y una tarjeta entera abre el proyecto (D-327).
 * Nunca contra el servidor (playwright.config.ts).
 */

const stamp = Date.now().toString(36);

async function projectId(page: Page): Promise<string> {
    await page.goto('/proyectos?buscar=ARR-WEB');
    const href = await page
        .getByRole('link', { name: /Web corporativa/ })
        .first()
        .getAttribute('href');
    const id = /\/proyectos\/(\d+)/.exec(href ?? '')?.[1];
    expect(id, 'proyecto «Web corporativa» del DemoDataSeeder').toBeTruthy();

    return id as string;
}

function doneColumn(page: Page): Locator {
    return page
        .locator('[data-test="kanban-column"]')
        .filter({ has: page.getByRole('button', { name: /^Hecha/ }) });
}

function doneToggle(page: Page): Locator {
    return doneColumn(page).locator('[data-test="kanban-column-toggle"]');
}

test.describe('kanban con la columna «Hecha» plegable (D-326)', () => {
    // Todas las columnas a la vista, para arrastrar de la primera a la última.
    test.use({ viewport: { width: 1920, height: 1080 } });

    test('«Hecha» nace plegada, se despliega y se recuerda al recargar', async ({
        page,
    }) => {
        await login(page, USERS.manager);
        const id = await projectId(page);
        await page.goto(`/proyectos/${id}/tareas?vista=kanban`);

        await expect(doneToggle(page)).toHaveAttribute(
            'aria-expanded',
            'false',
        );
        await expect(doneColumn(page)).toHaveAttribute(
            'data-collapsed',
            'true',
        );
        await expect(
            page.getByRole('list', { name: 'Tareas en «Hecha»' }),
        ).toHaveCount(0);

        await doneToggle(page).click();
        await expect(doneToggle(page)).toHaveAttribute('aria-expanded', 'true');
        await expect(
            page.getByRole('list', { name: 'Tareas en «Hecha»' }),
        ).toBeVisible();

        await page.reload();
        await expect(doneToggle(page)).toHaveAttribute('aria-expanded', 'true');

        // Con el teclado se vuelve a plegar y así se queda al recargar.
        await doneToggle(page).focus();
        await page.keyboard.press('Enter');
        await expect(doneToggle(page)).toHaveAttribute(
            'aria-expanded',
            'false',
        );
        await page.reload();
        await expect(doneToggle(page)).toHaveAttribute(
            'aria-expanded',
            'false',
        );
    });

    test('una tarjeta soltada en la columna plegada pasa a «Hecha» sin desplegarla', async ({
        page,
    }) => {
        await login(page, USERS.manager);
        const id = await projectId(page);
        await page.goto(`/proyectos/${id}/tareas?vista=kanban`);
        await expect(doneToggle(page)).toHaveAttribute(
            'aria-expanded',
            'false',
        );

        const title = `Soltar en Hecha ${stamp}`;
        const input = page.locator('[data-test="quick-add-input"]').first();
        await input.fill(title);
        await input.press('Enter');
        const card = page
            .locator('[data-test="kanban-card"]')
            .filter({ hasText: title });
        await expect(card).toBeVisible();

        const before = Number(
            /\((\d+)\)/.exec((await doneToggle(page).textContent()) ?? '')?.[1],
        );

        const handle = card.locator('[data-test="kanban-handle"]');
        await handle.scrollIntoViewIfNeeded();
        const from = await handle.boundingBox();
        const to = await doneColumn(page).boundingBox();
        expect(from && to).toBeTruthy();

        await page.mouse.move(from!.x + from!.width / 2, from!.y + 5);
        await page.mouse.down();
        await page.mouse.move(from!.x + 20, from!.y + 20, { steps: 5 });
        await page.mouse.move(to!.x + to!.width / 2, to!.y + 80, {
            steps: 25,
        });
        await expect(doneColumn(page)).toHaveClass(/bg-accent/);
        await page.mouse.up();

        // Sigue plegada, con una tarea más, y la tarjeta ya no está en su columna.
        await expect(doneToggle(page)).toHaveAttribute(
            'aria-expanded',
            'false',
        );
        await expect(doneToggle(page)).toContainText(`(${before + 1})`);
        await expect(card).toHaveCount(0);

        // En el servidor ya es una tarea hecha: se ve al mostrar las completadas.
        await page.goto(`/proyectos/${id}/tareas?vista=kanban&completadas=1`);
        await doneToggle(page).click();
        await expect(
            page
                .getByRole('list', { name: 'Tareas en «Hecha»' })
                .getByText(title),
        ).toBeVisible();
    });
});

test.describe('proyectos en tarjetas en el móvil (D-327)', () => {
    test.use({ viewport: { width: 375, height: 812 } });

    test('a 375 px cada proyecto es una tarjeta con sus bolsas y la tarjeta entera abre el proyecto', async ({
        page,
    }) => {
        await login(page, USERS.manager);
        await page.goto('/proyectos?buscar=ARR-WEB');

        await expect(page.locator('table')).toHaveCount(0);
        const card = page
            .locator('[data-test="project-card"]')
            .filter({ hasText: 'Web corporativa' });
        await expect(card).toBeVisible();
        await expect(card).toContainText('ARR-WEB');
        await expect(card).toContainText('Gestor principal');
        await expect(
            card.locator('[data-test="card-bank"]').first(),
        ).toBeVisible();
        await expect(card.getByRole('meter').first()).toBeVisible();

        // Sin scroll horizontal de la página.
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth),
        ).toBeLessThanOrEqual(375);

        // Un toque en el gestor (no en el enlace) abre el proyecto.
        await card.getByText('Gestor principal').click();
        await expect(page).toHaveURL(/\/proyectos\/\d+$/);
        await expect(
            page.getByRole('heading', { name: 'Web corporativa' }),
        ).toBeVisible();
    });
});
