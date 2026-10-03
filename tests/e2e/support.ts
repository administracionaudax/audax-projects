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

/**
 * Aviso de privacidad (D-075): el DemoDataSeeder deja leído el texto a toda la plantilla de
 * ejemplo (los E2E no dependen del aviso) salvo a esta persona, que lo tiene pendiente para el E2E
 * de privacidad (privacy.spec.ts). Ningún otro E2E inicia sesión con ella.
 */
/**
 * Colaboradora externa del DemoDataSeeder (D-134): solo es miembro de «Rediseño web» (MIR-WEB) y
 * «Tienda online» (FAR-SHOP), con una tarea en cada uno. Solo la usa collaborator.spec.ts.
 */
export const COLLABORATOR_USER =
    process.env.E2E_COLLABORATOR_EMAIL ?? 'sara.colaboradora@example.com';

export const PRIVACY_PENDING_USER =
    process.env.E2E_PRIVACY_PENDING_EMAIL ?? 'daniel.ortega@example.com';

/** Rutas de la barra lateral (URLs en español, contrato de routes/web.php). */
export const SIDEBAR_PATHS = [
    '/mis-tareas',
    '/proyectos',
    '/clientes',
    '/bolsas',
    '/horas',
    '/carga',
    '/ausencias',
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

/**
 * El «PDF» de un informe (D-140): en la CI y en local no hay Gotenberg (REPORTS_PDF_DRIVER=html) y
 * la descarga es el HTML que convertiría; con Gotenberg, un PDF de verdad.
 */
export function expectReportPdf(file: { name: string; bytes: Buffer }): void {
    if (file.name.endsWith('.pdf')) {
        expect(file.bytes.subarray(0, 5).toString()).toBe('%PDF-');
    } else {
        expect(file.name).toMatch(/\.html$/);
        expect(file.bytes.subarray(0, 15).toString()).toBe('<!doctype html>');
    }
}

/** Props de la primera visita (Inertia las deja en <script data-page="app">). */
export async function pageProps(page: Page): Promise<Record<string, unknown>> {
    const json = await page
        .locator('script[data-page="app"]')
        .first()
        .textContent();
    const data = JSON.parse(json ?? '{}') as {
        props?: Record<string, unknown>;
    };

    return data.props ?? {};
}

/**
 * ¿Hay tiempo real (prop `realtime` de Reverb)? En la CI TIENE que haberlo (Reverb local, ci.yml):
 * si falta, el test falla. Fuera de la CI, sin Reverb, el test que lo necesita se salta con su
 * motivo (la interfaz funciona con consultas periódicas, pero eso no demuestra el tiempo real).
 */
export async function requireRealtime(
    page: Page,
    skip: (condition: boolean, reason: string) => void,
): Promise<void> {
    const realtime = (await pageProps(page)).realtime ?? null;

    if (process.env.CI) {
        expect(
            realtime,
            'En la CI tiene que haber tiempo real: Reverb local y BROADCAST_CONNECTION=reverb (ci.yml).',
        ).not.toBeNull();

        return;
    }

    skip(
        realtime === null,
        'Sin Reverb (prop realtime null): este test demuestra el tiempo real y lo necesita.',
    );
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

/**
 * Guarda el tema en la cuenta con sesión iniciada (PATCH /ajustes/apariencia, lo mismo que hace
 * el selector de Apariencia). Al iniciar sesión, RecordSuccessfulLogin pisa la cookie
 * "appearance" con users.theme_preference, así que el tema que deja un spec (p. ej.
 * navigation.spec.ts) se colaría en el siguiente. Fijarlo tras el login hace que cada spec sea
 * independiente del orden, de --shard y de fullyParallel (F09).
 */
export async function saveUserTheme(
    page: Page,
    theme: Theme | 'system',
): Promise<void> {
    const xsrf = (await page.context().cookies()).find(
        (cookie) => cookie.name === 'XSRF-TOKEN',
    );
    expect(xsrf, 'cookie XSRF-TOKEN tras el login').toBeDefined();

    const response = await page.request.patch('/ajustes/apariencia', {
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? ''),
            Accept: 'text/html',
        },
        data: { theme },
        maxRedirects: 0,
    });
    expect(response.status(), 'PATCH /ajustes/apariencia').toBeLessThan(400);
}

export async function expectTheme(page: Page, theme: Theme): Promise<void> {
    if (theme === 'dark') {
        await expect(page.locator('html')).toHaveClass(/(^|\s)dark(\s|$)/);
    } else {
        await expect(page.locator('html')).not.toHaveClass(/(^|\s)dark(\s|$)/);
    }
}
