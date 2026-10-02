import fs from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import type { Theme } from './support';
import { expectTheme, login, saveUserTheme, USERS } from './support';

/**
 * Privacidad (SPEC §15, D-075) con los datos del DatabaseSeeder de desarrollo (la cola es «sync»
 * en la CI, así que la exportación queda lista al momento). Nunca contra el servidor.
 * - La empleada ve el aviso de lectura, lee el texto y lo acepta: el aviso desaparece.
 * - Pide sus datos en /ajustes/mis-datos y descarga el ZIP.
 * - Sin violaciones AA graves de axe en las páginas nuevas, en claro y en oscuro.
 */

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function expectNoSeriousViolations(
    page: Page,
    label: string,
): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    const serious = results.violations.filter(
        (v) => v.impact === 'serious' || v.impact === 'critical',
    );
    const report = serious
        .map(
            (v) =>
                `${v.id} (${v.impact}): ${v.help}\n  ${v.nodes
                    .map((n) => n.target.join(' '))
                    .slice(0, 5)
                    .join('\n  ')}`,
        )
        .join('\n');

    expect(serious, `${label}\n${report}`).toEqual([]);
}

test('una empleada lee el texto de privacidad, lo acepta y descarga sus datos', async ({
    page,
}) => {
    await login(page, USERS.employee);
    await page.goto('/');

    const banner = page.getByRole('region', {
        name: 'Información sobre tus datos',
    });

    // En un reintento la lectura ya puede estar registrada: entonces no hay aviso.
    if (await banner.isVisible()) {
        await banner.getByRole('link', { name: 'Leer la información' }).click();
        await expect(page).toHaveURL(/\/privacidad$/);
        await expect(page.getByRole('heading', { level: 1 })).toContainText(
            'Privacidad',
        );
        // En /privacidad no se repite el aviso.
        await expect(banner).toHaveCount(0);
        await expect(
            page.getByText('Borrador pendiente de asesor').first(),
        ).toBeVisible();

        await page
            .getByRole('button', { name: 'He leído la información' })
            .click();
    } else {
        await page.goto('/privacidad');
    }

    const acknowledgement = page.locator(
        '[data-test="privacy-acknowledgement"]',
    );
    await expect(acknowledgement).toContainText(/Leíste la versión \d+ el/);
    await expect(
        page.getByRole('button', { name: 'He leído la información' }),
    ).toHaveCount(0);

    await page.goto('/');
    await expect(
        page.getByRole('region', { name: 'Información sobre tus datos' }),
    ).toHaveCount(0);

    // Mis datos: desde los ajustes.
    await page.goto('/ajustes/perfil');
    await page.getByRole('link', { name: 'Mis datos' }).click();
    await expect(page).toHaveURL(/\/ajustes\/mis-datos$/);

    const request = page.getByRole('button', { name: 'Preparar mis datos' });

    if (await request.isEnabled()) {
        await request.click();
    }

    const latest = page.locator('[data-test="personal-data-export"]').first();
    await expect(latest).toContainText('Lista para descargar', {
        timeout: 20_000,
    });

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        latest.getByRole('link', { name: /^Descargar/ }).click(),
    ]);
    const file = await download.path();

    expect(download.suggestedFilename()).toMatch(
        /^datos-personales-elena-empleada-\d{4}-\d{2}-\d{2}\.zip$/,
    );
    // Un ZIP empieza por «PK».
    expect(fs.readFileSync(file).subarray(0, 2).toString()).toBe('PK');

    await page.reload();
    await expect(
        page.locator('[data-test="personal-data-export"]').first(),
    ).toContainText('Descargada el');
});

for (const theme of ['light', 'dark'] as const satisfies readonly Theme[]) {
    test(`páginas de privacidad sin violaciones AA graves en tema ${theme === 'light' ? 'claro' : 'oscuro'}`, async ({
        page,
    }) => {
        await login(page, USERS.employee);
        await saveUserTheme(page, theme);

        for (const path of ['/privacidad', '/ajustes/mis-datos', '/']) {
            await page.goto(path);
            await page.waitForLoadState('networkidle');
            await expectTheme(page, theme);
            await expectNoSeriousViolations(page, `${path} (${theme})`);
        }

        await login(page, USERS.admin);
        await saveUserTheme(page, theme);
        await page.goto('/admin/privacidad');
        await page.waitForLoadState('networkidle');
        await expectTheme(page, theme);
        await expect(page.getByRole('heading', { level: 1 })).toContainText(
            'Privacidad',
        );
        await expectNoSeriousViolations(page, `/admin/privacidad (${theme})`);
    });
}

test('a 375 px las páginas de privacidad no tienen scroll horizontal', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await login(page, USERS.admin);

    for (const path of [
        '/privacidad',
        '/ajustes/mis-datos',
        '/admin/privacidad',
    ]) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        const overflow = await page.evaluate(
            () =>
                document.documentElement.scrollWidth -
                document.documentElement.clientWidth,
        );
        expect(overflow, path).toBeLessThanOrEqual(0);
    }
});
