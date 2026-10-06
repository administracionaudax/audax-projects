import AxeBuilder from '@axe-core/playwright';
import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

const WCAG_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * La Weekly (Fase 10, entrega 10.2b) con los datos de ejemplo del DemoDataSeeder (D-186 y D-187):
 * las tres semanas anteriores cerradas con los envíos de la plantilla y la en curso abierta. Si no
 * hubiera ninguna activa, la abre el admin con «Iniciar la semana» (F-040). Se puede repetir sobre la misma
 * base: si Elena (empleado@example.com) ya envió en una pasada anterior, el botón es «Actualizar
 * weekly». Nunca contra el servidor.
 */

async function expectNoPageScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
}

async function expectAccessible(page: Page, label: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(WCAG_AA).analyze();
    expect(results.violations.map((item) => `${label}: ${item.id}`)).toEqual(
        [],
    );
}

/** Abre «Mi weekly» de la semana activa desde el resumen de /weeklies. */
async function openMyActiveWeekly(page: Page): Promise<void> {
    await page.goto('/weeklies');
    await page.locator('[data-test="my-weekly-open"]').click();
    await expect(page).toHaveURL(/\/mi-espacio\?semana=\d+$/);
    await expect(page.locator('[data-test="weekly-editor"]')).toBeVisible();
}

async function asAdmin(browser: Browser): Promise<Page> {
    const context = await browser.newContext();
    const page = await context.newPage();
    await login(page, USERS.admin);

    return page;
}

/** Abre la semana en curso con «Iniciar la semana» si no hay ninguna activa (F-040). */
async function ensureActiveWeek(browser: Browser): Promise<void> {
    const admin = await asAdmin(browser);
    await admin.goto('/weeklies');
    const open = admin.locator('[data-test="weekly-open-cycle"]');

    if (await open.isVisible()) {
        await open.click();
        await expect(
            admin.locator('[data-test="weekly-manage"]'),
        ).toBeVisible();
    }

    await admin.context().close();
}

test('escribir el borrador, enviarlo y verlo en el estado del equipo', async ({
    page,
    browser,
}) => {
    test.setTimeout(90_000);
    await ensureActiveWeek(browser);
    const text = `Weekly E2E ${Date.now()}: revisada la home con el cliente.`;

    await test.step('la barra lateral lleva a Mi espacio y a las weeklies', async () => {
        await login(page, USERS.employee);
        const nav = page.getByRole('navigation', {
            name: 'Navegación principal',
        });
        // La sección Weekly nace plegada (D-261): se despliega con su encabezado.
        await nav.getByRole('button', { name: 'Weekly' }).click();
        await expect(
            nav.getByRole('link', { name: 'Mi espacio' }),
        ).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Weeklies' })).toBeVisible();
    });

    await test.step('el borrador se guarda solo y sigue ahí al recargar', async () => {
        await openMyActiveWeekly(page);
        const box = page.locator('[data-test^="weekly-entry-"]').first();
        const textarea = box.locator('[data-test="weekly-entry-text"]');

        if (!(await textarea.isVisible())) {
            await box.locator('[data-test="weekly-entry-toggle"]').click();
        }

        await textarea.fill(text);
        await expect(
            page.locator('[data-test="weekly-autosave"]'),
        ).toHaveAttribute('data-status', 'saved');

        // Al recargar, la caja sale plegada (con el texto de muestra en su botón) si la weekly ya se
        // envió, o desplegada si es un borrador: en los dos casos el texto sigue ahí.
        await page.reload();
        const saved = page.locator('[data-test^="weekly-entry-"]').first();
        const savedText = saved.locator('[data-test="weekly-entry-text"]');

        if (!(await savedText.isVisible())) {
            await saved.locator('[data-test="weekly-entry-toggle"]').click();
        }

        await expect(savedText).toHaveValue(text);
    });

    await test.step('se envía y la tarjeta dice que está enviada', async () => {
        await page
            .getByRole('button', { name: /^(Enviar|Actualizar) weekly$/ })
            .click();
        await expect(
            page.getByRole('button', { name: 'Actualizar weekly' }),
        ).toBeVisible();

        await page.goto('/weeklies');
        await expect(
            page.locator('[data-test="my-weekly-callout"]'),
        ).toHaveAttribute('data-status', /^submitted(_late)?$/);
        await expectAccessible(page, '/weeklies');
    });

    await test.step('quien gestiona la ve en la tira del equipo', async () => {
        const admin = await asAdmin(browser);
        await admin.goto('/weeklies?pestana=historico');
        const submitted = admin
            .locator('[data-test="weekly-active-card"]')
            .locator('[data-test="weekly-team-submitted"]');
        await expect(
            submitted.getByRole('link', {
                name: /^Elena Empleada · Enviado/,
            }),
        ).toBeVisible();
        await expectAccessible(admin, '/weeklies?pestana=historico');
        await admin.context().close();
    });

    await test.step('en el móvil (375 px) no hay scroll horizontal', async () => {
        await page.setViewportSize({ width: 375, height: 812 });

        for (const url of [
            '/weeklies',
            '/weeklies?pestana=historico',
            '/mi-espacio',
        ]) {
            await page.goto(url);
            await expectNoPageScroll(page);
        }

        await openMyActiveWeekly(page);
        await expectNoPageScroll(page);
        await expectAccessible(page, 'Mi weekly (móvil)');
    });
});

test('quien gestiona exime a una persona pendiente y le quita la exención', async ({
    page,
    browser,
}) => {
    test.setTimeout(60_000);
    await ensureActiveWeek(browser);

    await login(page, USERS.admin);
    await page.goto('/weeklies');
    const manage = page.locator('[data-test="weekly-manage"]');
    await expect(manage).toBeVisible();

    // La primera persona que falta (en los datos de ejemplo, varias de la plantilla).
    const first = manage.locator('[data-test="weekly-pending-member"]').first();
    await expect(first).toBeVisible();
    const person = (
        await first.locator('span.truncate').first().textContent()
    )?.trim() as string;
    expect(person).not.toBe('');

    await manage.getByRole('button', { name: `Eximir a ${person}` }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel(/Nota/).fill('Formación fuera toda la semana');
    await dialog.locator('[data-test="weekly-exempt-confirm"]').click();
    await expect(dialog).toHaveCount(0);

    const exempt = manage.locator('[data-test="weekly-exempt-list"]');
    // Puede haber otros exentos de pasadas anteriores sobre la misma base: se mira solo su fila.
    const exemptRow = exempt
        .locator('[data-test="weekly-exempt-member"]')
        .filter({ hasText: person });
    await expect(exemptRow).toBeVisible();
    await expect(exemptRow.getByText('Exención manual')).toBeVisible();
    await expect(
        manage.locator('[data-test="weekly-pending"]').getByText(person),
    ).toHaveCount(0);

    // En la tira, entre los exentos.
    await expect(
        manage.locator('[data-test="weekly-team-exempt"]').getByRole('link', {
            name: `${person} · Exento (Exención manual)`,
        }),
    ).toBeVisible();

    await exempt
        .getByRole('button', { name: `Quitar exención de ${person}` })
        .click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Quitar exención' })
        .click();
    await expect(
        manage.locator('[data-test="weekly-pending"]').getByText(person),
    ).toBeVisible();
});

test('una semana cerrada se abre en solo lectura', async ({ page }) => {
    await login(page, USERS.employee);
    await page.goto('/mi-espacio');

    const closed = page
        .locator('[data-test="my-week"]')
        .filter({ hasText: 'Cerrada' })
        .first();
    await expect(closed).toBeVisible();
    await closed.click();

    await expect(page).toHaveURL(/\/mi-espacio\?semana=\d+$/);
    await expect(
        page.getByText('Esta semana ya está cerrada: solo lectura.'),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="weekly-entry-readonly"]').first(),
    ).toBeVisible();
    await expect(
        page.locator('[data-test="weekly-editor"]').getByRole('textbox'),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: /^(Enviar|Actualizar) weekly$/ }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Añadir otro cliente' }),
    ).toHaveCount(0);
    await expectAccessible(page, 'Mi weekly cerrada');
});
