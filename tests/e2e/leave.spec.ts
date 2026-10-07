import type { Browser, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/*
 * Vacaciones y permisos (Fase 11, R3; D-360 a D-379). El módulo `people` viene apagado: el primer
 * test lo enciende y el último lo apaga, para no cambiar los demás specs. Datos del DemoDataSeeder:
 * Elena (empleado@example.com) tiene 22 días de vacaciones menos los 3 de la semana que viene; Raúl
 * (responsable@example.com) es su responsable y Lucía, su compañera de Diseño. Nunca contra el
 * servidor (playwright.config.ts).
 */

test.describe.configure({ mode: 'serial' });
test.use({ testIdAttribute: 'data-test' });

const COLLEAGUE = process.env.E2E_COLLEAGUE_EMAIL ?? 'lucia.martin@example.com';

async function setPeopleModule(page: Page, on: boolean): Promise<void> {
    await login(page, USERS.admin);
    await page.goto('/admin/ajustes');
    const toggle = page.getByRole('switch', {
        name: 'Personas (registro de jornada)',
        exact: true,
    });

    if ((await toggle.getAttribute('aria-checked')) !== String(on)) {
        await toggle.click();
    }

    await expect(toggle).toHaveAttribute('aria-checked', String(on));
    await page.getByRole('button', { name: 'Guardar los ajustes' }).click();
    await expect(
        page.getByText('Ajustes guardados', { exact: false }).first(),
    ).toBeVisible();
}

async function asUser(browser: Browser, email: string): Promise<Page> {
    const context = await browser.newContext({
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
    });
    const page = await context.newPage();
    await login(page, email);

    return page;
}

function ymd(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function dmy(iso: string): string {
    const [y, m, d] = iso.split('-');

    return `${d}/${m}/${y}`;
}

/** Lunes de dentro de `weeks` semanas, saltando la Navidad (días bloqueados y de media jornada). */
function mondayIn(weeks: number): Date {
    const date = new Date();
    const day = date.getDay() === 0 ? 7 : date.getDay();
    date.setDate(date.getDate() - day + 1 + weeks * 7);

    while (
        (date.getMonth() === 11 && date.getDate() >= 18) ||
        (date.getMonth() === 0 && date.getDate() <= 7)
    ) {
        date.setDate(date.getDate() + 7);
    }

    return date;
}

async function pickDay(page: Page, label: string, iso: string): Promise<void> {
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel(label, { exact: true }).click();
    // El del campo anterior puede seguir cerrándose: el abierto es el último.
    const calendar = page.locator('[data-slot="popover-content"]').last();
    await expect(calendar.getByRole('grid')).toBeVisible();
    const day = calendar.locator(`[data-day="${iso}"]`).getByRole('button');

    for (let i = 0; i < 6 && (await day.count()) === 0; i++) {
        await calendar
            .getByRole('button', { name: 'Ir al mes siguiente' })
            .click();
    }

    await day.click();
    await expect(calendar).toBeHidden();
}

/** Lo disponible de las vacaciones del año (la primera tarjeta), en días. */
async function vacationAvailable(page: Page): Promise<number> {
    const text = await page
        .getByTestId('leave-balance')
        .first()
        .getByTestId('leave-balance-available')
        .innerText();

    return Number(text.split(' ')[0].replace(',', '.'));
}

test('se enciende el módulo Personas', async ({ page }) => {
    await setPeopleModule(page, true);
});

test('pedir vacaciones con saldo, aprobarlas, verlas en el calendario del equipo sin el motivo y ver el saldo descontado', async ({
    page,
    browser,
}) => {
    test.setTimeout(90_000);
    const monday = mondayIn(10);
    const start = ymd(monday);
    const wednesday = new Date(monday);
    wednesday.setDate(monday.getDate() + 2);
    const end = ymd(wednesday);
    const sameYear = start.slice(0, 4) === String(new Date().getFullYear());
    const period = `${dmy(start)} – ${dmy(end)}`;

    await login(page, USERS.employee);
    await page.goto('/ausencias');
    await expect(
        page.getByRole('heading', { name: /Tus saldos de/ }).first(),
    ).toBeVisible();
    const before = await vacationAvailable(page);
    // Un reintento contra la misma base ya la encuentra pedida (o aprobada): no se repite.
    const fresh = (await page.getByText(period).count()) === 0;

    await test.step('Elena las pide (si un reintento ya lo hizo, no las repite)', async () => {
        if (fresh) {
            await page
                .getByRole('button', {
                    name: 'Solicitar ausencia',
                    exact: true,
                })
                .first()
                .click();
            const dialog = page.getByRole('dialog', {
                name: 'Solicitar una ausencia',
            });
            await dialog
                .getByTestId('leave-type-select')
                .selectOption({ label: 'Vacaciones' });
            await pickDay(page, 'Desde', start);
            await pickDay(page, 'Hasta (incluido)', end);

            // La simulación dice lo que cuesta y lo que queda.
            await expect(dialog.getByTestId('leave-simulation')).toContainText(
                'Cuesta 3 días',
            );
            await dialog
                .getByRole('button', { name: 'Enviar la solicitud' })
                .click();
            await expect(dialog).toBeHidden();
        }

        await expect(
            page.locator('li', { hasText: period }).first(),
        ).toContainText('Cuesta 3 días');
    });

    if (sameYear && fresh) {
        // Lo pendiente ya reserva saldo.
        await expect.poll(() => vacationAvailable(page)).toBe(before - 3);
    }

    await test.step('Raúl, su responsable, las aprueba', async () => {
        const manager = await asUser(browser, USERS.manager);
        await manager.goto('/ausencias/equipo');
        const approve = manager.getByRole('button', {
            name: `Aprobar la ausencia de Elena Empleada ${period}`,
            exact: true,
        });
        if ((await approve.count()) > 0) {
            await approve.click();
        }
        await expect(approve).toHaveCount(0);
        await manager.context().close();
    });

    await test.step('Lucía la ve en el calendario del equipo como «Ausente», sin el motivo', async () => {
        const colleague = await asUser(browser, COLLEAGUE);
        await colleague.goto(`/calendario?personas=1&fecha=${start}`);
        const absences = colleague.getByTestId('team-absence');
        await expect(absences.first()).toBeVisible();
        await expect(
            absences.filter({ hasText: 'Ausente' }).first(),
        ).toBeVisible();
        await expect(absences.filter({ hasText: 'Vacaciones' })).toHaveCount(0);
        await colleague.context().close();
    });

    await test.step('Elena la ve aprobada y su saldo descontado', async () => {
        await page.goto('/ausencias');
        const upcoming = page.getByTestId('absences-upcoming');
        await expect(upcoming).toContainText(period);
        await expect(upcoming.locator('li', { hasText: period })).toContainText(
            'Aprobada',
        );
        if (sameYear && fresh) {
            await expect.poll(() => vacationAvailable(page)).toBe(before - 3);
        }
    });
});

test('pedir un permiso por horas con franja y subir el justificante', async ({
    page,
}) => {
    test.setTimeout(60_000);
    const date = mondayIn(3);
    date.setDate(date.getDate() + 2);
    const day = ymd(date);
    const label = `${dmy(day)} · 2:00 h`;

    await login(page, USERS.employee);
    await page.goto('/ausencias');
    const pending = page.getByTestId('absences-pending');

    if ((await pending.getByText(label).count()) === 0) {
        await page
            .getByRole('button', { name: 'Solicitar ausencia', exact: true })
            .first()
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'Solicitar una ausencia',
        });
        await dialog.getByTestId('leave-type-select').selectOption({
            label: 'Deber inexcusable de carácter público y personal',
        });
        await expect(dialog).toContainText('Pide justificante');
        await dialog
            .getByRole('radio', { name: 'Por horas, con su franja' })
            .click();
        await pickDay(page, 'Día', day);
        await dialog.getByTestId('leave-start-time').fill('10:00');
        await dialog.getByTestId('leave-end-time').fill('12:00');
        await expect(dialog.getByTestId('leave-simulation')).toContainText(
            'Cuesta 2:00 h',
        );
        await dialog
            .getByRole('button', { name: 'Enviar la solicitud' })
            .click();
        await expect(dialog).toBeHidden();
    }

    const item = pending.locator('li', { hasText: label });
    await expect(item).toContainText('De 10:00 a 12:00');

    if (
        (await item.getByRole('link', { name: 'citacion-e2e.pdf' }).count()) ===
        0
    ) {
        await expect(item).toContainText(
            'Este permiso pide justificante y aún no hay ninguno.',
        );
        await item.getByTestId('absence-document-input').setInputFiles({
            name: 'citacion-e2e.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.4\n% citación de prueba\n%%EOF\n'),
        });
        await expect(
            page.getByText('Justificante subido.').first(),
        ).toBeVisible();
    }

    await expect(
        item.getByRole('link', { name: 'citacion-e2e.pdf' }),
    ).toBeVisible();
});

test('se apaga el módulo Personas', async ({ page }) => {
    await setPeopleModule(page, false);
});
