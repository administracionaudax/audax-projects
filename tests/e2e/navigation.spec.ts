import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import {
    DEFAULT_COLLAPSED_SECTIONS,
    expectTheme,
    login,
    saveUserTheme,
    setNavSections,
    SIDEBAR_PATHS,
    USERS,
} from './support';

/**
 * Aceptación de la Fase 0 (SPEC §17): un usuario inicia sesión, navega, cambia el tema y cierra sesión.
 */
test('iniciar sesión, navegar por la barra lateral, cambiar el tema y cerrar sesión', async ({
    page,
}) => {
    await login(page, USERS.admin);
    await expect(page).toHaveURL(/\/$/);

    await test.step('navegar por la barra lateral', async () => {
        // Todas las secciones desplegadas para ver cada enlace (nacen plegadas, D-261).
        await setNavSections(page, []);

        for (const path of SIDEBAR_PATHS) {
            await page.locator(`a[href$="${path}"]:visible`).first().click();
            await expect(page).toHaveURL(new RegExp(`${path}$`));
            await expect(
                page.locator('main, [data-slot="sidebar-inset"]').first(),
            ).toBeVisible();
        }

        await setNavSections(page, DEFAULT_COLLAPSED_SECTIONS);
    });

    await test.step('cambiar el tema a oscuro y comprobar que se mantiene al recargar', async () => {
        await page.goto('/ajustes/apariencia');

        const dark = /^(oscuro|dark)$/i;
        await page
            .getByRole('button', { name: dark })
            .or(page.getByRole('radio', { name: dark }))
            .or(page.getByRole('tab', { name: dark }))
            .first()
            .click();
        await expectTheme(page, 'dark');

        await page.reload();
        await expectTheme(page, 'dark');
    });

    await test.step('volver al tema claro', async () => {
        const light = /^(claro|light)$/i;
        await page
            .getByRole('button', { name: light })
            .or(page.getByRole('radio', { name: light }))
            .or(page.getByRole('tab', { name: light }))
            .first()
            .click();
        await expectTheme(page, 'light');

        // Deja la cuenta como la sembró el seeder («según el sistema»): el login aplica el tema
        // guardado y no debe colarse en otros specs (F09).
        await saveUserTheme(page, 'system');
    });

    await test.step('cerrar sesión', async () => {
        await page
            .locator('[data-test="sidebar-menu-button"]:visible')
            .first()
            .click();
        await page.locator('[data-test="logout-button"]').click();
        await expect(page).toHaveURL(/\/login(?:\?|$)/);

        await page.goto('/proyectos');
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
    });
});

/** Deja todas las secciones desplegadas (el valor por defecto) para los demás specs. */
/**
 * Secciones plegables de la barra lateral (D-260): por defecto solo Proyectos desplegada (D-261); el encabezado es un
 * botón con aria-expanded y aria-controls; el estado se guarda por persona (sobrevive a recargar)
 * y la sección de la página a la que se entra se despliega sola. Sin violaciones AA con una
 * sección plegada, y en el móvil (375 px) sin scroll horizontal.
 */
test('las secciones de la barra lateral se pliegan, se recuerdan y se despliegan solas', async ({
    page,
}) => {
    test.setTimeout(60_000);
    await login(page, USERS.manager);
    const nav = page.getByRole('navigation', { name: 'Navegación principal' });
    const weekly = nav.getByRole('button', { name: 'Weekly' });
    // Partir del plegado por defecto aunque otro test lo haya cambiado para esta persona.
    await setNavSections(page, DEFAULT_COLLAPSED_SECTIONS);

    try {
        await test.step('por defecto, solo Proyectos desplegada; Chat fijo y sin «Inicio» (D-261)', async () => {
            await expect(
                nav.getByRole('button', { name: 'Proyectos' }),
            ).toHaveAttribute('aria-expanded', 'true');
            for (const name of ['Weekly', 'Personas']) {
                await expect(nav.getByRole('button', { name })).toHaveAttribute(
                    'aria-expanded',
                    'false',
                );
            }
            // Con el módulo apagado, Facturación solo puede traer «Por facturar» (a quien
            // tiene view-financials, D-402): nada del módulo.
            for (const href of [
                '/facturacion/ventas',
                '/facturacion/por-revisar',
                '/facturacion/vendido-frente-a-real',
                '/facturacion/facturas',
            ]) {
                await expect(nav.locator(`a[href="${href}"]`)).toHaveCount(0);
            }
            await expect(nav.getByRole('link', { name: 'Inicio' })).toHaveCount(
                0,
            );
            await expect(nav.getByRole('link', { name: 'Chat' })).toBeVisible();
            const controls = await weekly.getAttribute('aria-controls');
            await expect(page.locator(`[id="${controls}"]`)).toBeHidden();
        });

        await test.step('sin violaciones AA con secciones plegadas', async () => {
            const results = await new AxeBuilder({ page })
                .include('[data-sidebar="sidebar"]')
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
                .analyze();
            expect(results.violations.map((v) => `${v.id}: ${v.help}`)).toEqual(
                [],
            );
        });

        await test.step('desplegar con el ratón muestra sus entradas', async () => {
            await weekly.click();
            await expect(weekly).toHaveAttribute('aria-expanded', 'true');
            await expect(
                nav.getByRole('link', { name: 'Weeklies' }),
            ).toBeVisible();
        });

        await test.step('el estado se guarda: sigue desplegada al recargar', async () => {
            await page.waitForLoadState('networkidle');
            await page.reload();
            await expect(weekly).toHaveAttribute('aria-expanded', 'true');
        });

        await test.step('con el teclado: Intro pliega y despliega', async () => {
            await weekly.focus();
            await page.keyboard.press('Enter');
            await expect(weekly).toHaveAttribute('aria-expanded', 'false');
            await expect(
                nav.getByRole('link', { name: 'Weeklies' }),
            ).toBeHidden();
            await page.keyboard.press('Enter');
            await expect(weekly).toHaveAttribute('aria-expanded', 'true');
            await page.keyboard.press('Enter');
            await expect(weekly).toHaveAttribute('aria-expanded', 'false');
            await page.waitForLoadState('networkidle');
        });

        await test.step('entrar en una página de la sección plegada la despliega', async () => {
            await page.goto('/mi-espacio');
            await expect(weekly).toHaveAttribute('aria-expanded', 'true');
            await expect(
                nav.getByRole('link', { name: 'Mi espacio' }),
            ).toHaveAttribute('aria-current', 'page');
        });

        await test.step('en el móvil (375 px), las secciones funcionan y no hay scroll horizontal', async () => {
            await page.setViewportSize({ width: 375, height: 812 });
            await page.goto('/');
            await page
                .getByRole('button', {
                    name: 'Mostrar u ocultar la barra lateral',
                })
                .first()
                .click();
            const sheet = page.getByRole('dialog');
            const projects = sheet.getByRole('button', { name: 'Proyectos' });
            await expect(projects).toHaveAttribute('aria-expanded', 'true');
            await projects.click();
            await expect(projects).toHaveAttribute('aria-expanded', 'false');
            await expect(
                sheet.getByRole('link', { name: 'Clientes' }),
            ).toBeHidden();
            await projects.click();
            await expect(
                sheet.getByRole('link', { name: 'Clientes' }),
            ).toBeVisible();

            const overflow = await page.evaluate(
                () =>
                    document.documentElement.scrollWidth -
                    document.documentElement.clientWidth,
            );
            expect(overflow).toBeLessThanOrEqual(0);
        });
    } finally {
        await setNavSections(page, DEFAULT_COLLAPSED_SECTIONS);
    }
});

/**
 * UX-03: los errores se explican en español, con el tema de la app, y sin el mensaje en inglés de
 * la excepción (403 por rol de Spatie, 404 de un recurso que no existe).
 */
test('una página sin permiso o que no existe se explica en español', async ({
    page,
}) => {
    await login(page, USERS.employee);

    const forbidden = await page.goto('/admin');
    expect(forbidden?.status()).toBe(403);
    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'No tienes acceso a esta página',
        }),
    ).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'es');
    await expect(
        page.getByText('User does not have the right roles'),
    ).toHaveCount(0);

    const missing = await page.goto('/proyectos/999999');
    expect(missing?.status()).toBe(404);
    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'No encontramos esta página',
        }),
    ).toBeVisible();

    await page.getByRole('link', { name: 'Ir a Inicio', exact: true }).click();
    await expect(page).toHaveURL(/\/$/);
});
