import fs from 'node:fs';
import path from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import type { Browser, BrowserContext, Page, TestInfo } from '@playwright/test';
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
 * UX obligatoria de la Fase 1 (SPEC §3 y §19): WCAG 2.1 AA sin violaciones de axe en todas las
 * páginas de la Fase 1, en tema claro y oscuro, con admin, responsable y empleada; y a 375 px
 * sin scroll horizontal de la página.
 *
 * Sobre los datos del DemoDataSeeder (proyecto «ARR-WEB · Web corporativa» de Bodegas Arrieta,
 * con su bolsa de Diseño). Nunca contra el servidor (playwright.config.ts).
 *
 * Violaciones conocidas: las páginas de KNOWN_AXE y KNOWN_OVERFLOW se marcan con test.fail() y
 * una anotación con la regla. Cuando se arreglen, Playwright avisará de que «pasa y se esperaba
 * que fallara»: entonces hay que quitar la entrada correspondiente.
 */

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const MOBILE = { width: 375, height: 812 };

type Role = 'admin' | 'manager' | 'employee';

const ROLE_EMAIL: Record<Role, string> = {
    admin: USERS.admin,
    manager: USERS.manager,
    employee: USERS.employee,
};

const ROLE_LABEL: Record<Role, string> = {
    admin: 'admin',
    manager: 'responsable',
    employee: 'empleada',
};

type Ids = {
    project: string;
    hourBank: string;
    client: string;
    user: string;
    department: string;
};

type PageDef = {
    id: string;
    roles: readonly Role[];
    path: (ids: Ids) => string;
    /** Acción tras cargar (p. ej. abrir el panel de una tarea). */
    prepare?: (page: Page) => Promise<void>;
};

const ALL: readonly Role[] = ['admin', 'manager', 'employee'];
const STAFF: readonly Role[] = ['admin', 'manager'];
const ADMIN: readonly Role[] = ['admin'];

const PAGES: readonly PageDef[] = [
    { id: 'inicio', roles: ALL, path: () => '/' },
    { id: 'mis-tareas', roles: ALL, path: () => '/mis-tareas' },
    { id: 'proyectos', roles: ALL, path: () => '/proyectos' },
    { id: 'proyecto-nuevo', roles: STAFF, path: () => '/proyectos/nuevo' },
    {
        id: 'proyecto-resumen',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}`,
    },
    {
        id: 'proyecto-tareas-lista',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}/tareas`,
    },
    {
        id: 'proyecto-tareas-kanban',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}/tareas?vista=kanban`,
    },
    {
        id: 'proyecto-panel-tarea',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}/tareas`,
        prepare: async (page) => {
            await page.locator('[data-test="task-title"]').first().click();
            const panel = page.getByRole('dialog');
            await expect(panel).toBeVisible();
            // Espera a que el panel termine de cargar la tarea (sale el esqueleto).
            await expect(
                panel.locator('[data-test="task-title-input"]'),
            ).toBeVisible();
            await page.waitForLoadState('networkidle');
        },
    },
    {
        id: 'proyecto-bolsas',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}/bolsas`,
    },
    {
        id: 'proyecto-bolsa-detalle',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}/bolsas/${i.hourBank}`,
    },
    {
        id: 'proyecto-horas',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}/horas`,
    },
    {
        id: 'proyecto-archivos',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}/archivos`,
    },
    {
        id: 'proyecto-ajustes',
        roles: STAFF,
        path: (i) => `/proyectos/${i.project}/ajustes`,
    },
    { id: 'bolsas', roles: STAFF, path: () => '/bolsas' },
    { id: 'clientes', roles: ALL, path: () => '/clientes' },
    { id: 'cliente-ficha', roles: ALL, path: (i) => `/clientes/${i.client}` },
    { id: 'horas', roles: ALL, path: () => '/horas' },
    {
        id: 'horas-aprobaciones',
        roles: STAFF,
        path: () => '/horas/aprobaciones',
    },
    { id: 'horas-bloqueo', roles: ADMIN, path: () => '/horas/bloqueo' },
    { id: 'notificaciones', roles: ALL, path: () => '/notificaciones' },
    { id: 'admin', roles: ADMIN, path: () => '/admin' },
    { id: 'admin-usuarios', roles: ADMIN, path: () => '/admin/usuarios' },
    {
        id: 'admin-usuario-editar',
        roles: ADMIN,
        path: (i) => `/admin/usuarios/${i.user}`,
    },
    {
        id: 'admin-usuario-baja',
        roles: ADMIN,
        path: (i) => `/admin/usuarios/${i.user}/baja`,
    },
    {
        id: 'admin-departamentos',
        roles: ADMIN,
        path: () => '/admin/departamentos',
    },
    { id: 'admin-estados', roles: ADMIN, path: () => '/admin/estados' },
    { id: 'admin-tipos', roles: ADMIN, path: () => '/admin/tipos-de-tarea' },
    { id: 'admin-ajustes', roles: ADMIN, path: () => '/admin/ajustes' },
    // Estados abiertos que axe no ve en la carga inicial.
    {
        id: 'dialogo-anadir-horas',
        roles: ALL,
        path: () => '/horas',
        prepare: async (page) => {
            await page.locator('[data-test="header-log-time"]').click();
            await expect(
                page.getByRole('dialog', { name: 'Añadir horas' }),
            ).toBeVisible();
            await page.waitForLoadState('networkidle');
        },
    },
    {
        id: 'busqueda-global',
        roles: ALL,
        path: () => '/',
        prepare: async (page) => {
            await page
                .getByRole('button', { name: /^Buscar…/ })
                .first()
                .click();
            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            await dialog.getByRole('combobox').fill('web');
            await expect(dialog.getByRole('option').first()).toBeVisible();
            await page.waitForLoadState('networkidle');
        },
    },
    {
        id: 'campana-notificaciones',
        roles: ALL,
        path: () => '/',
        prepare: async (page) => {
            await page
                .getByRole('button', { name: /^Notificaciones/ })
                .first()
                .click();
            await expect(
                page.locator('[data-radix-popper-content-wrapper]').first(),
            ).toBeVisible();
            await page.waitForLoadState('networkidle');
        },
    },
    // Fase 2: informes (D-044: cada rol ve los suyos).
    { id: 'informes-indice', roles: ALL, path: () => '/informes' },
    {
        id: 'informes-direccion',
        roles: STAFF,
        path: () => '/informes/direccion?comparar=1',
    },
    {
        id: 'informes-departamento',
        roles: STAFF,
        path: (i) => `/informes/departamentos/${i.department}`,
    },
    {
        id: 'informes-persona',
        roles: ALL,
        path: (i) => `/informes/personas/${i.user}?periodo=trimestre`,
    },
    {
        id: 'informes-cliente',
        roles: STAFF,
        path: (i) => `/informes/clientes/${i.client}`,
    },
    {
        id: 'informes-proyecto',
        roles: STAFF,
        path: (i) => `/informes/proyectos/${i.project}`,
    },
    { id: 'informes-detalle', roles: ALL, path: () => '/informes/detalle' },
    {
        id: 'informes-facturacion',
        roles: ADMIN,
        path: (i) => `/facturacion/horas-para-facturar?cliente[]=${i.client}`,
    },
    // Fase 3: ausencias, festivos y carga.
    { id: 'ausencias', roles: ALL, path: () => '/ausencias' },
    {
        id: 'ausencias-solicitar',
        roles: ALL,
        path: () => '/ausencias?solicitar=1',
    },
    { id: 'ausencias-equipo', roles: STAFF, path: () => '/ausencias/equipo' },
    { id: 'admin-festivos', roles: ADMIN, path: () => '/admin/festivos' },
    { id: 'carga', roles: ALL, path: () => '/carga' },
    {
        id: 'carga-4-semanas',
        roles: STAFF,
        path: () => '/carga?horizonte=4-semanas',
    },
    // Fase 4: Gantt, calendario, plantillas y tareas recurrentes.
    { id: 'gantt', roles: ALL, path: () => '/gantt' },
    {
        id: 'proyecto-gantt',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}/gantt`,
    },
    {
        id: 'proyecto-calendario',
        roles: ALL,
        path: (i) => `/proyectos/${i.project}/tareas?vista=calendario`,
    },
    { id: 'admin-plantillas', roles: ADMIN, path: () => '/admin/plantillas' },
    {
        id: 'admin-tareas-recurrentes',
        roles: ADMIN,
        path: () => '/admin/tareas-recurrentes',
    },
    {
        id: 'admin-invitar-dialogo',
        roles: ADMIN,
        path: () => '/admin/usuarios',
        prepare: async (page) => {
            await page.locator('[data-test="invite-user"]').click();
            await expect(page.getByRole('dialog')).toBeVisible();
        },
    },
];

/**
 * Violaciones de axe conocidas (hallazgos de la revisión UX de la Fase 1), por página y tema.
 * Clave: `${página}` (todos los roles y temas), `${página}@${tema}` o `${página}@${tema}@${rol}`.
 * Quita la entrada cuando se arregle.
 */
const KNOWN_AXE: Record<string, string> = {};

/** Páginas con scroll horizontal a 375 px conocido. Clave: `${página}` o `${página}@${rol}`. */
const KNOWN_OVERFLOW: Record<string, string> = {};

function known(
    map: Record<string, string>,
    keys: readonly string[],
): string | undefined {
    for (const key of keys) {
        if (map[key] !== undefined) {
            return map[key];
        }
    }

    return undefined;
}

// --- Sesiones -------------------------------------------------------------------------------
// El login está limitado por minuto: se inicia sesión una vez por rol y se reutiliza el estado
// (también tras reiniciarse el worker por un fallo).

const AUTH_DIR = path.join(process.cwd(), 'test-results', '.a11y-phase1-auth');

function statePath(role: Role): string {
    return path.join(AUTH_DIR, `${role}.json`);
}

async function openAs(
    browser: Browser,
    role: Role,
    theme: Theme,
    baseURL: string,
    viewport?: { width: number; height: number },
): Promise<{ context: BrowserContext; page: Page }> {
    const stored = fs.existsSync(statePath(role)) ? statePath(role) : undefined;
    const context = await browser.newContext({
        baseURL,
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
        storageState: stored,
        viewport: viewport ?? { width: 1280, height: 800 },
    });
    const page = await context.newPage();
    await presetTheme(context, page, theme, baseURL);

    let authenticated = false;

    if (stored) {
        const response = await page.request.get('/notificaciones/recientes', {
            headers: { Accept: 'application/json' },
            maxRedirects: 0,
        });
        authenticated = response.status() === 200;
    }

    if (!authenticated) {
        await login(page, ROLE_EMAIL[role]);
        fs.mkdirSync(AUTH_DIR, { recursive: true });
        await context.storageState({ path: statePath(role) });
    }

    // Al iniciar sesión (y en cada página, ThemeSync) manda el tema guardado en la cuenta.
    await saveUserTheme(page, theme);
    await context.addCookies([
        { name: 'appearance', value: theme, url: baseURL },
    ]);

    return { context, page };
}

// Ids del DemoDataSeeder, resueltos una vez por worker desde la interfaz (no dependen del orden).
let cachedIds: Ids | undefined;

async function resolveIds(page: Page): Promise<Ids> {
    if (cachedIds) {
        return cachedIds;
    }

    const idFrom = async (url: string, pattern: RegExp): Promise<string> => {
        await page.goto(url);
        await page.waitForLoadState('networkidle');
        const hrefs = await page
            .locator('a[href]')
            .evaluateAll((links) =>
                links.map((a) => a.getAttribute('href') ?? ''),
            );
        const match = hrefs
            .map((href) => new URL(href, 'http://x').pathname.match(pattern))
            .find((m) => m !== null);
        expect(match, `enlace ${pattern} en ${url}`).toBeTruthy();

        return match![1];
    };

    // Se resuelven con la cuenta de admin (ve todo).
    const project = await page.goto('/proyectos').then(async () => {
        await page.waitForLoadState('networkidle');
        const href = await page
            .getByRole('link', { name: /Web corporativa/ })
            .first()
            .getAttribute('href');

        return new URL(href ?? '', 'http://x').pathname.match(
            /\/proyectos\/(\d+)$/,
        )![1];
    });
    const hourBank = await idFrom(
        `/proyectos/${project}/bolsas`,
        new RegExp(`^/proyectos/${project}/bolsas/(\\d+)$`),
    );
    const client = await idFrom('/clientes', /^\/clientes\/(\d+)$/);
    const user = await page.goto('/admin/usuarios').then(async () => {
        await page.waitForLoadState('networkidle');
        const href = await page
            .getByRole('link', { name: /Elena/ })
            .first()
            .getAttribute('href');

        return new URL(href ?? '', 'http://x').pathname.match(
            /\/admin\/usuarios\/(\d+)$/,
        )![1];
    });

    const department = await page.goto('/informes').then(async () => {
        await page.waitForLoadState('networkidle');
        const href = await page
            .getByRole('link', { name: /Diseño/ })
            .first()
            .getAttribute('href');

        return new URL(href ?? '', 'http://x').pathname.match(
            /\/informes\/departamentos\/(\d+)$/,
        )![1];
    });

    cachedIds = { project, hourBank, client, user, department };

    return cachedIds;
}

async function ids(browser: Browser, baseURL: string): Promise<Ids> {
    if (cachedIds) {
        return cachedIds;
    }

    const { context, page } = await openAs(browser, 'admin', 'light', baseURL);

    try {
        return await resolveIds(page);
    } finally {
        await context.close();
    }
}

// --- Comprobaciones -------------------------------------------------------------------------

async function expectNoAxeViolations(
    page: Page,
    label: string,
    testInfo: TestInfo,
): Promise<void> {
    // Las zonas que se están recargando van atenuadas (aria-busy + opacity-60, p. ej. los informes
    // con props diferidas): se analiza la página ya cargada, no el estado intermedio.
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, {
        timeout: 15_000,
    });
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    const report = results.violations
        .map(
            (v) =>
                `${v.id} (${v.impact}): ${v.help}\n  ${v.nodes
                    .slice(0, 6)
                    .map(
                        (n) =>
                            `${n.target.join(' ')}\n      ${n.failureSummary?.split('\n').slice(1, 2).join(' ').trim() ?? ''}`,
                    )
                    .join('\n  ')}`,
        )
        .join('\n');

    if (results.violations.length > 0) {
        await testInfo.attach('axe', {
            body: report,
            contentType: 'text/plain',
        });
    }

    expect(
        results.violations.map((v) => v.id),
        `${label}\n${report}`,
    ).toEqual([]);
}

/**
 * A 375 px: ni scroll horizontal de la página ni contenido recortado. El layout de la app lleva
 * `overflow-x-clip` (app-sidebar-layout.tsx), así que un contenido demasiado ancho no genera
 * scroll: se corta sin más y queda inalcanzable. Por eso, además del scroll del documento, se
 * buscan elementos que se salgan del viewport sin estar dentro de su propio contenedor con
 * scroll o recorte (tablas con overflow-x-auto, SVG, avatares…). Se ignoran los fijos/flotantes
 * y los que están ocultos (sr-only, cerrados).
 */
async function expectNoHorizontalScroll(
    page: Page,
    label: string,
): Promise<void> {
    const overflow = await page.evaluate(() => {
        const root = document.documentElement;
        const viewport = root.clientWidth;
        const offenders: string[] = [];
        const layoutRoots = new Set<Element>([root, document.body]);
        document
            .querySelectorAll('main')
            .forEach((main) => layoutRoots.add(main));

        const insideOwnContainer = (el: Element): boolean => {
            for (let a = el.parentElement; a; a = a.parentElement) {
                if (layoutRoots.has(a)) {
                    return false;
                }

                const style = getComputedStyle(a);

                if (style.position === 'fixed') {
                    return true;
                }

                if (
                    ['auto', 'scroll', 'hidden', 'clip'].includes(
                        style.overflowX,
                    )
                ) {
                    return true;
                }
            }

            return false;
        };

        for (const el of Array.from(document.body.querySelectorAll('*'))) {
            if (
                el.closest('svg') !== null &&
                el.tagName.toLowerCase() !== 'svg'
            ) {
                continue;
            }

            const rect = el.getBoundingClientRect();
            const style = getComputedStyle(el);

            if (
                rect.width === 0 ||
                rect.height === 0 ||
                style.visibility === 'hidden' ||
                style.position === 'fixed' ||
                // Campos ocultos que Radix crea para los formularios (interruptores y radios):
                // invisibles, sin foco y desplazados fuera a propósito.
                (el.getAttribute('aria-hidden') === 'true' &&
                    style.opacity === '0') ||
                el.closest('[data-radix-popper-content-wrapper]') !== null
            ) {
                continue;
            }

            if (
                (rect.right > viewport + 1 || rect.left < -1) &&
                !insideOwnContainer(el)
            ) {
                const cls =
                    typeof el.className === 'string'
                        ? el.className.split(' ').slice(0, 4).join('.')
                        : '';
                const text = (el.textContent ?? '').trim().slice(0, 40);
                offenders.push(
                    `${el.tagName.toLowerCase()}${cls ? `.${cls}` : ''} [${Math.round(rect.left)}..${Math.round(rect.right)} px] «${text}»`,
                );
            }

            if (offenders.length >= 6) {
                break;
            }
        }

        return {
            scrollWidth: root.scrollWidth,
            clientWidth: viewport,
            offenders,
        };
    });

    expect(
        overflow.scrollWidth,
        `${label}: scroll horizontal (${overflow.scrollWidth} > ${overflow.clientWidth})`,
    ).toBeLessThanOrEqual(overflow.clientWidth);
    expect(
        overflow.offenders,
        `${label}: contenido fuera de los ${overflow.clientWidth} px (recortado por el layout)`,
    ).toEqual([]);
}

async function visit(page: Page, def: PageDef, pageIds: Ids): Promise<void> {
    const response = await page.goto(def.path(pageIds));
    expect(response?.status(), `${def.id}: estado HTTP`).toBeLessThan(400);
    await page.waitForLoadState('networkidle');

    if (def.prepare) {
        await def.prepare(page);
    }
}

// --- Tests ----------------------------------------------------------------------------------

test.describe.configure({ mode: 'default' });

for (const role of ['admin', 'manager', 'employee'] as const) {
    for (const theme of ['light', 'dark'] as const satisfies readonly Theme[]) {
        test.describe(`${ROLE_LABEL[role]} · tema ${theme === 'light' ? 'claro' : 'oscuro'}`, () => {
            for (const def of PAGES.filter((p) => p.roles.includes(role))) {
                test(`${def.id}: WCAG 2.1 AA sin violaciones`, async ({
                    browser,
                    baseURL,
                }, testInfo) => {
                    test.setTimeout(60_000);
                    const reason = known(KNOWN_AXE, [
                        `${def.id}@${theme}@${role}`,
                        `${def.id}@${theme}`,
                        def.id,
                    ]);

                    if (reason) {
                        testInfo.annotations.push({
                            type: 'a11y-conocida',
                            description: reason,
                        });
                        test.fail(true, reason);
                    }

                    const base = baseURL ?? 'http://127.0.0.1:8000';
                    const pageIds = await ids(browser, base);
                    const { context, page } = await openAs(
                        browser,
                        role,
                        theme,
                        base,
                    );

                    try {
                        await visit(page, def, pageIds);
                        await expectTheme(page, theme);
                        await expectNoAxeViolations(
                            page,
                            `${def.id} (${ROLE_LABEL[role]}, ${theme})`,
                            testInfo,
                        );
                    } finally {
                        await context.close();
                    }
                });
            }
        });
    }

    test.describe(`${ROLE_LABEL[role]} · móvil 375 px`, () => {
        for (const def of PAGES.filter((p) => p.roles.includes(role))) {
            test(`${def.id}: sin scroll horizontal`, async ({
                browser,
                baseURL,
            }, testInfo) => {
                test.setTimeout(60_000);
                const reason = known(KNOWN_OVERFLOW, [
                    `${def.id}@${role}`,
                    def.id,
                ]);

                if (reason) {
                    testInfo.annotations.push({
                        type: 'movil-conocida',
                        description: reason,
                    });
                    test.fail(true, reason);
                }

                const base = baseURL ?? 'http://127.0.0.1:8000';
                const pageIds = await ids(browser, base);
                const { context, page } = await openAs(
                    browser,
                    role,
                    'light',
                    base,
                    MOBILE,
                );

                try {
                    await visit(page, def, pageIds);
                    await expectNoHorizontalScroll(
                        page,
                        `${def.id} (${ROLE_LABEL[role]}, 375 px)`,
                    );
                } finally {
                    await context.close();
                }
            });
        }
    });
}

test.describe('invitado · /invitacion/{token}', () => {
    for (const theme of ['light', 'dark'] as const satisfies readonly Theme[]) {
        test(`invitación con token inventado, tema ${theme === 'light' ? 'claro' : 'oscuro'}: WCAG 2.1 AA`, async ({
            browser,
            baseURL,
        }, testInfo) => {
            const reason = known(KNOWN_AXE, [
                `invitacion@${theme}`,
                'invitacion',
            ]);

            if (reason) {
                testInfo.annotations.push({
                    type: 'a11y-conocida',
                    description: reason,
                });
                test.fail(true, reason);
            }

            const base = baseURL ?? 'http://127.0.0.1:8000';
            const context = await browser.newContext({
                baseURL: base,
                locale: 'es-ES',
            });
            const page = await context.newPage();

            try {
                await presetTheme(context, page, theme, base);
                const response = await page.goto(
                    '/invitacion/token-inventado-0000?email=nadie%40example.com',
                );
                expect(response?.status()).toBeLessThan(400);
                await page.waitForLoadState('networkidle');
                await expectTheme(page, theme);
                await expectNoAxeViolations(
                    page,
                    `invitacion (${theme})`,
                    testInfo,
                );
            } finally {
                await context.close();
            }
        });
    }

    test('invitación con token inventado: sin scroll horizontal a 375 px', async ({
        browser,
        baseURL,
    }) => {
        const base = baseURL ?? 'http://127.0.0.1:8000';
        const context = await browser.newContext({
            baseURL: base,
            locale: 'es-ES',
            viewport: MOBILE,
        });
        const page = await context.newPage();

        try {
            await page.goto(
                '/invitacion/token-inventado-0000?email=nadie%40example.com',
            );
            await page.waitForLoadState('networkidle');
            await expectNoHorizontalScroll(page, 'invitacion (375 px)');
        } finally {
            await context.close();
        }
    });
});
