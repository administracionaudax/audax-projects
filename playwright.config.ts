import { defineConfig, devices } from '@playwright/test';

/**
 * E2E con Playwright (CLAUDE.md: solo en la CI o en local con `php artisan serve`).
 * NUNCA contra el servidor (docs/DECISIONES.md D-002): la URL debe ser local salvo que se
 * fuerce explícitamente con E2E_ALLOW_REMOTE=1.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const host = new URL(baseURL).hostname;
const isLocal =
    ['127.0.0.1', 'localhost', '[::1]', '::1'].includes(host) ||
    host.endsWith('.localhost') ||
    host.endsWith('.test');

if (!isLocal && process.env.E2E_ALLOW_REMOTE !== '1') {
    throw new Error(
        `E2E abortados: ${baseURL} no es local. Los E2E solo se ejecutan en la CI o contra php artisan serve.`,
    );
}

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    // php artisan serve atiende pocas peticiones a la vez y el login está limitado por minuto.
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    timeout: 30_000,
    expect: { timeout: 7_500 },
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ],
    outputDir: 'test-results',
    use: {
        baseURL,
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
