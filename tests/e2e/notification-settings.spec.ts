import AxeBuilder from '@axe-core/playwright';
import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import type { Theme } from './support';
import {
    expectTheme,
    login,
    presetTheme,
    saveUserTheme,
    USERS,
} from './support';

/**
 * Preferencias de notificación (Fase 7, D-073) sobre los datos del DemoDataSeeder. Nunca contra
 * el servidor (playwright.config.ts). Un solo inicio de sesión por test (el login está limitado
 * por minuto).
 * - Una empleada desactiva el email de «Tareas que vencen», activa el resumen diario (con el
 *   teclado), guarda, recarga y lo ve guardado; a 375 px los canales van debajo de cada evento,
 *   sin scroll horizontal. Al acabar deja sus preferencias como estaban.
 * - axe AA en la página en claro y oscuro, con el admin (ve también los avisos obligatorios).
 */

const URL = '/ajustes/notificaciones';
const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const SAVED = 'Preferencias de notificación guardadas.';
const MOBILE = { width: 375, height: 812 };

function dueEmail(page: Page): Locator {
    return page.getByRole('switch', { name: 'Tareas que vencen: Email' });
}

function digest(page: Page): Locator {
    return page.getByRole('switch', { name: 'Resumen diario por email' });
}

async function save(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Guardar' }).click();
    await expect(page.getByText(SAVED).first()).toBeVisible();
}

/** Deja el email de «Tareas que vencen» activado y el resumen desactivado (lo de fábrica). */
async function resetPreferences(page: Page): Promise<void> {
    let changed = false;

    if ((await dueEmail(page).getAttribute('aria-checked')) !== 'true') {
        await dueEmail(page).click();
        changed = true;
    }

    if ((await digest(page).getAttribute('aria-checked')) !== 'false') {
        await digest(page).click();
        changed = true;
    }

    if (changed) {
        await save(page);
    }
}

async function expectNoAxeViolations(page: Page, label: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    const report = results.violations
        .map(
            (v) =>
                `${v.id} (${v.impact}): ${v.help}\n  ${v.nodes
                    .map((n) => n.target.join(' '))
                    .slice(0, 5)
                    .join('\n  ')}`,
        )
        .join('\n');

    expect(
        results.violations.map((v) => v.id),
        `${label}\n${report}`,
    ).toEqual([]);
}

test('una empleada quita el email de las tareas que vencen y activa el resumen diario', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto(URL);
    await expect(
        page.getByRole('heading', { name: 'Preferencias de notificación' }),
    ).toBeVisible();
    // Otra ejecución pudo dejarlo cambiado: se parte de lo de fábrica.
    await resetPreferences(page);

    await expect(dueEmail(page)).toHaveAttribute('aria-checked', 'true');
    await expect(digest(page)).toHaveAttribute('aria-checked', 'false');
    await expect(
        page.getByRole('group', { name: 'Tareas' }).getByRole('switch'),
    ).not.toHaveCount(0);
    // Sin Web Push configurado, la columna del navegador está desactivada y lo explica.
    await expect(
        page.getByText(/no están activados en este servidor/),
    ).toBeVisible();
    await expect(
        page.getByRole('switch', {
            name: 'Tareas que vencen: Avisos del navegador',
        }),
    ).toBeDisabled();

    await dueEmail(page).click();
    await expect(dueEmail(page)).toHaveAttribute('aria-checked', 'false');

    // El resumen, con el teclado.
    await digest(page).focus();
    await page.keyboard.press('Space');
    await expect(digest(page)).toHaveAttribute('aria-checked', 'true');
    await expect(page.getByText(/llegan juntos en el resumen/)).toBeVisible();
    await expect(page.getByText('Tienes cambios sin guardar.')).toBeVisible();

    await save(page);

    await page.reload();
    await expect(dueEmail(page)).toHaveAttribute('aria-checked', 'false');
    await expect(digest(page)).toHaveAttribute('aria-checked', 'true');
    await expect(
        page.getByRole('switch', { name: 'Tareas que vencen: En la app' }),
    ).toHaveAttribute('aria-checked', 'true');

    // Se llega también desde la campana.
    await page.goto('/');
    await page
        .getByRole('button', { name: /^Notificaciones/ })
        .first()
        .click();
    await page
        .getByRole('link', { name: 'Preferencias de notificación' })
        .click();
    await expect(page).toHaveURL(/\/ajustes\/notificaciones$/);

    await resetPreferences(page);
    await expect(dueEmail(page)).toHaveAttribute('aria-checked', 'true');

    // A 375 px, los canales van debajo de cada evento, con su nombre y dentro de la pantalla.
    await page.setViewportSize(MOBILE);
    await page.reload();
    await page.waitForLoadState('networkidle');

    const row = page.locator('[data-test="notification-event-task.due"]');
    const title = row.getByText('Tareas que vencen', { exact: true });
    await expect(title).toBeVisible();
    const titleBox = await title.boundingBox();
    expect(titleBox).not.toBeNull();

    for (const channel of ['En la app', 'Email', 'Avisos del navegador']) {
        await expect(row.getByText(channel, { exact: true })).toBeVisible();

        const box = await row
            .getByRole('switch', { name: `Tareas que vencen: ${channel}` })
            .boundingBox();
        expect(box, channel).not.toBeNull();
        expect(box!.y).toBeGreaterThan(titleBox!.y);
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(MOBILE.width);
    }

    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
});

test('sin violaciones AA de axe en claro y oscuro, también a 375 px', async ({
    page,
    context,
    baseURL,
}) => {
    const base = baseURL ?? 'http://127.0.0.1:8000';

    await login(page, USERS.admin);

    for (const theme of ['light', 'dark'] as const satisfies readonly Theme[]) {
        const label = theme === 'light' ? 'claro' : 'oscuro';

        await test.step(`tema ${label}`, async () => {
            // Al cambiar de página manda el tema guardado en la cuenta (ThemeSync).
            await presetTheme(context, page, theme, base);
            await saveUserTheme(page, theme);
            await page.setViewportSize({ width: 1280, height: 800 });

            await page.goto(URL);
            await page.waitForLoadState('networkidle');
            await expectTheme(page, theme);
            // El admin ve también los obligatorios (desactivados, con candado).
            await expect(page.getByText('Obligatorio').first()).toBeVisible();
            await expectNoAxeViolations(page, `${URL} (${label})`);

            // Con el resumen activado aparece su aviso: también debe cumplir (no se guarda).
            await digest(page).click();
            await expect(
                page.getByText(/llegan juntos en el resumen/),
            ).toBeVisible();
            await expectNoAxeViolations(
                page,
                `${URL} con el resumen (${label})`,
            );

            await page.setViewportSize(MOBILE);
            await page.reload();
            await page.waitForLoadState('networkidle');
            await expectTheme(page, theme);
            await expectNoAxeViolations(page, `${URL} a 375 px (${label})`);
        });
    }

    // Deja el tema de la cuenta como viene de fábrica.
    await saveUserTheme(page, 'system');
});
