import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
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
 * Accesibilidad AA (SPEC §3: contraste AA en ambos temas y navegación por teclado).
 * Sin violaciones "serious" ni "critical" de axe en /login, / y /styleguide, en claro y en oscuro.
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

for (const theme of ['light', 'dark'] as const satisfies readonly Theme[]) {
    test(`sin violaciones AA graves en tema ${theme === 'light' ? 'claro' : 'oscuro'}`, async ({
        page,
        context,
        baseURL,
    }) => {
        await presetTheme(
            context,
            page,
            theme,
            baseURL ?? 'http://127.0.0.1:8000',
        );

        await test.step('/login', async () => {
            await page.goto('/login');
            await expectTheme(page, theme);
            await expectNoSeriousViolations(page, `/login (${theme})`);
        });

        await test.step('/ (inicio)', async () => {
            await login(page, USERS.admin);
            // El login aplica el tema guardado en la cuenta: se fija aquí para no depender
            // del que haya dejado otro spec (F09).
            await saveUserTheme(page, theme);
            await page.goto('/');
            await page.waitForLoadState('networkidle');
            await expectTheme(page, theme);
            await expectNoSeriousViolations(page, `/ (${theme})`);
        });

        await test.step('/styleguide', async () => {
            await page.goto('/styleguide');
            await page.waitForLoadState('networkidle');
            await expectTheme(page, theme);
            // Espera a que la guía haya medido el contraste de los tokens.
            await expect(page.locator('[data-contrast]').first()).toBeVisible();
            await expect(page.locator('[data-contrast="fail"]')).toHaveCount(0);
            await expectNoSeriousViolations(page, `/styleguide (${theme})`);
        });
    });
}
