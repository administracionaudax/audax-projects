import type { BrowserContext, Page } from '@playwright/test';
import { expect } from '@playwright/test';

/**
 * Usuarios de ejemplo del DatabaseSeeder de desarrollo (datos ficticios, nunca de producción).
 * Se pueden cambiar con variables de entorno si el seeder usa otros.
 */
export const PASSWORD = process.env.E2E_PASSWORD ?? 'password';

export const USERS = {
    admin: process.env.E2E_ADMIN_EMAIL ?? 'admin@example.com',
    manager: process.env.E2E_MANAGER_EMAIL ?? 'responsable@example.com',
    employee: process.env.E2E_EMPLOYEE_EMAIL ?? 'empleado@example.com',
    client: process.env.E2E_CLIENT_EMAIL ?? 'cliente@example.com',
} as const;

/** Rutas de la barra lateral (URLs en español, contrato de routes/web.php). */
export const SIDEBAR_PATHS = [
    '/mis-tareas',
    '/proyectos',
    '/clientes',
    '/bolsas',
    '/horas',
    '/carga',
    '/informes',
    '/chat',
] as const;

export async function login(
    page: Page,
    email: string,
    password = PASSWORD,
): Promise<void> {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page
        .locator('[data-test="login-button"], button[type="submit"]')
        .first()
        .click();
    await expect(page).not.toHaveURL(/\/login(?:\?|$)/);
}

export type Theme = 'light' | 'dark';

/**
 * Fija el tema antes de cargar la página: cookie (la lee la vista Blade), localStorage
 * (la lee use-appearance) y prefers-color-scheme (por si la preferencia es "sistema").
 */
export async function presetTheme(
    context: BrowserContext,
    page: Page,
    theme: Theme,
    baseURL: string,
): Promise<void> {
    await context.addCookies([
        { name: 'appearance', value: theme, url: baseURL },
    ]);
    await context.addInitScript((value) => {
        try {
            window.localStorage.setItem('appearance', value);
        } catch {
            // Sin almacenamiento: se queda la cookie.
        }
    }, theme);
    await page.emulateMedia({ colorScheme: theme });
}

export async function expectTheme(page: Page, theme: Theme): Promise<void> {
    if (theme === 'dark') {
        await expect(page.locator('html')).toHaveClass(/(^|\s)dark(\s|$)/);
    } else {
        await expect(page.locator('html')).not.toHaveClass(/(^|\s)dark(\s|$)/);
    }
}
