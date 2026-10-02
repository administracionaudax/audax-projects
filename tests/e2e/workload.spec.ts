import type { Browser, Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Fase 3 (PLAN-FASE-3 «Tests»): ausencias aprobadas por el responsable que bajan la capacidad en
 * Carga y reasignar desde la celda de una persona sobrecargada. Sobre los datos del DemoDataSeeder:
 * - Elena (Diseño, su responsable es Raúl) tiene vacaciones aprobadas de martes a jueves de la
 *   semana que viene; Lucía, una solicitud pendiente dentro de tres semanas,
 * - Lucía va sobrecargada el primer día laborable de la semana que viene: «Maquetas para la feria
 *   de turismo» (MIR-WEB, que gestiona Raúl) son 16 h que empiezan y se entregan ese día.
 * Carga se abre por defecto en la semana que viene (D-052). Cada celda de la matriz es un botón
 * cuyo nombre accesible empieza por «Persona, día dd/mm/aaaa.» y dice lo planificado, la capacidad,
 * el nivel y el motivo. Nunca contra el servidor (playwright.config.ts).
 */

const OVERLOAD_TASK = 'Maquetas para la feria de turismo';

async function asUser(browser: Browser, email: string): Promise<Page> {
    const context = await browser.newContext({
        locale: 'es-ES',
        timezoneId: 'Europe/Madrid',
    });
    const page = await context.newPage();
    await login(page, email);

    return page;
}

function escapeRegExp(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
}

/** La celda de una persona un día («Elena Empleada, viernes 09/10/2026. …»). */
function cellOf(page: Page, person: string, day: string): Locator {
    return page.getByRole('button', {
        name: new RegExp(`^${escapeRegExp(`${person}, ${day}.`)} `),
    });
}

/** «viernes 09/10/2026», el día de una celda, sacado de su nombre accesible. */
async function dayOf(cell: Locator, person: string): Promise<string> {
    const label = (await cell.getAttribute('aria-label')) ?? '';
    const day = new RegExp(
        `^${escapeRegExp(person)}, (\\S+ \\d{2}/\\d{2}/\\d{4})\\.`,
    ).exec(label)?.[1];

    expect(day, `día de la celda «${label}»`).toBeTruthy();

    return day ?? '';
}

/** Minutos planificados de una celda (NaN si su nombre no los dice). */
async function plannedIn(cell: Locator): Promise<number> {
    const label = (await cell.getAttribute('aria-label')) ?? '';
    const match = /(\d+):(\d{2}) planificadas/.exec(label);

    return match ? Number(match[1]) * 60 + Number(match[2]) : Number.NaN;
}

/** Elige un día (AAAA-MM-DD) en el calendario del selector de fecha que se acaba de abrir. */
async function pickDay(page: Page, iso: string): Promise<void> {
    const calendar = page.locator('[data-slot="popover-content"]');
    await expect(calendar.getByRole('grid')).toBeVisible();
    const day = calendar.locator(`[data-day="${iso}"]`).getByRole('button');

    // La semana que viene puede caer ya en el mes siguiente.
    if ((await day.count()) === 0) {
        await calendar
            .getByRole('button', { name: 'Ir al mes siguiente' })
            .click();
    }

    await day.click();
}

test('el responsable aprueba una ausencia pendiente de su equipo', async ({
    page,
}) => {
    await login(page, USERS.manager);

    await page.goto('/ausencias/equipo');
    await expect(
        page.getByRole('heading', { level: 1, name: 'Ausencias del equipo' }),
    ).toBeVisible();

    const approve = page.getByRole('button', {
        name: /Aprobar la ausencia de Lucía/,
    });
    await expect(approve.first()).toBeVisible();
    await approve.first().click();

    await expect(
        page.getByRole('button', { name: /Aprobar la ausencia de Lucía/ }),
    ).toHaveCount(0);
});

test('una empleada pide medio día, su responsable lo aprueba y Carga enseña su capacidad reducida ese día', async ({
    page,
    browser,
}) => {
    await login(page, USERS.employee);

    // Un día de la semana que viene con su jornada entera (ni festivo ni sus vacaciones).
    await page.goto('/carga');
    const free = page
        .getByRole('button', {
            name: /^Elena Empleada, \S+ \d{2}\/\d{2}\/\d{4}\. .* de 8:00 de capacidad /,
        })
        .first();
    await expect(free).toBeVisible();
    const day = await dayOf(free, 'Elena Empleada');
    const [, date] = day.split(' ');
    const [dd, mm, yyyy] = date.split('/');

    // La pide desde «Mis ausencias»: un permiso de 2 h ese día (si un reintento ya la pidió, no
    // la repite: se solaparía).
    await page.goto('/ausencias');
    await expect(
        page.getByRole('heading', { level: 1, name: 'Mis ausencias' }),
    ).toBeVisible();
    const pending = page.locator('[data-test="absences-pending"]');

    if ((await pending.getByText(`${date} · 2:00 h`).count()) === 0) {
        await page
            .getByRole('button', { name: 'Solicitar ausencia', exact: true })
            .first()
            .click();
        const dialog = page.getByRole('dialog', {
            name: 'Solicitar una ausencia',
        });
        await expect(dialog).toBeVisible();
        await dialog
            .getByLabel('Tipo', { exact: true })
            .selectOption({ label: 'Permiso' });
        await dialog.getByRole('radio', { name: 'Parte de un día' }).click();
        await dialog.getByLabel('Día', { exact: true }).click();
        await pickDay(page, `${yyyy}-${mm}-${dd}`);
        await dialog.getByLabel('Horas que faltas').fill('2:00');
        await dialog
            .getByRole('button', { name: 'Enviar la solicitud' })
            .click();
        await expect(dialog).toBeHidden();
    }

    await expect(pending).toContainText(`${date} · 2:00 h`);

    // Raúl, su responsable, la aprueba en «Ausencias del equipo».
    const manager = await asUser(browser, USERS.manager);
    await manager.goto('/ausencias/equipo');
    const approve = manager.getByRole('button', {
        name: `Aprobar la ausencia de Elena Empleada ${date} · 2:00 h`,
        exact: true,
    });
    await approve.click();
    await expect(approve).toHaveCount(0);

    // En Carga, la celda de Elena ese día tiene 6:00 de capacidad (8:00 − 2:00) y dice por qué.
    await manager.goto('/carga');
    const cell = cellOf(manager, 'Elena Empleada', day);
    await expect(cell).toHaveAttribute(
        'aria-label',
        /planificadas de 6:00 de capacidad .*\. Capacidad reducida: ausencia parcial: 2:00 \(Permiso\)\.$/,
    );
    await expect(cell).toContainText('/ 6:00');
    await manager.context().close();
});

test('Carga enseña a Elena sin capacidad los días de sus vacaciones de la semana que viene', async ({
    page,
}) => {
    await login(page, USERS.manager);

    await page.goto('/carga');
    await expect(
        page.getByRole('heading', { level: 1, name: 'Carga del equipo' }),
    ).toBeVisible();

    // Celdas grises con el motivo escrito (nunca solo color): «Vacaciones».
    const vacation = page.getByRole('button', {
        name: /^Elena Empleada, \S+ \d{2}\/\d{2}\/\d{4}\. Sin capacidad, \d+:\d{2} planificadas\. Vacaciones\.$/,
    });
    await expect(vacation.first()).toBeVisible();
    await expect(vacation.first()).toContainText('Vacaciones');
});

test('reasignar desde la celda de una persona sobrecargada mueve su carga a la otra persona (D-052)', async ({
    page,
}) => {
    await login(page, USERS.manager);
    await page.goto('/carga');

    // La primera celda sobrecargada de Lucía: la de sus 16 h de maquetas.
    const overloaded = page
        .getByRole('button', {
            name: /^Lucía Martín, \S+ \d{2}\/\d{2}\/\d{4}\. .*: Sobrecarga\./,
        })
        .first();
    await expect(overloaded).toBeVisible();
    const day = await dayOf(overloaded, 'Lucía Martín');
    const lucia = cellOf(page, 'Lucía Martín', day);
    const raul = cellOf(page, 'Raúl Responsable', day);
    const luciaBefore = await plannedIn(lucia);
    const raulBefore = await plannedIn(raul);
    expect(luciaBefore).toBeGreaterThanOrEqual(16 * 60);
    expect(raulBefore).not.toBeNaN();

    // El panel de la celda: la tarea con sus 16 h de ese día, reasignada a Raúl.
    await lucia.click();
    const panel = page.locator('[data-test="workload-cell-panel"]');
    await expect(
        panel.getByRole('heading', { name: 'Lucía Martín' }),
    ).toBeVisible();
    const card = panel
        .locator('[data-test="workload-cell-task"]')
        .filter({ hasText: OVERLOAD_TASK });
    await expect(card).toContainText('16:00 este día');
    // Si es la única tarea de la celda, el editor ya viene abierto.
    const toggle = card.getByRole('button', {
        name: 'Reasignar o replanificar',
    });
    if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
        await toggle.click();
    }
    const editor = card.getByRole('form', {
        name: `Reasignar o replanificar «${OVERLOAD_TASK}»`,
    });
    await editor.getByRole('combobox', { name: 'Responsable' }).click();
    await page.getByRole('option', { name: /^Raúl Responsable/ }).click();
    await editor.getByRole('button', { name: 'Guardar' }).click();

    // Sale de la celda de Lucía y la matriz se recalcula: 16 h menos para ella y 16 h más para Raúl
    // (se lee con el panel cerrado: mientras está abierto, el resto de la página queda oculto).
    await expect(
        panel
            .locator('[data-test="workload-cell-task"]')
            .filter({ hasText: OVERLOAD_TASK }),
    ).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect.poll(() => plannedIn(lucia)).toBe(luciaBefore - 16 * 60);
    await expect.poll(() => plannedIn(raul)).toBe(raulBefore + 16 * 60);
});

test('una empleada solo ve su propia fila en Carga (D-052)', async ({
    page,
}) => {
    await login(page, USERS.employee);

    await page.goto('/carga');
    const rows = page.locator('[data-test="workload-row"]');
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText('Elena Empleada');
    await expect(
        page.getByRole('button', { name: /^Lucía Martín, / }),
    ).toHaveCount(0);
});

test('el informe del departamento enseña la carga futura del equipo y enlaza con Carga', async ({
    page,
}) => {
    await login(page, USERS.manager);

    await page.goto('/informes');
    await page
        .getByRole('list', { name: 'Departamentos' })
        .getByRole('link', { name: 'Diseño', exact: true })
        .click();
    await expect(page).toHaveURL(/\/informes\/departamentos\/\d+/);

    // Prop diferida: la tabla llega después de pintar el informe.
    const table = page.getByRole('table', {
        name: 'Carga planificada de cada persona del departamento en las próximas cuatro semanas',
    });
    await expect(table).toBeVisible();
    await expect(
        table.getByRole('rowheader', { name: 'Elena Empleada' }),
    ).toBeVisible();
    await expect(
        table.getByRole('rowheader', { name: 'Equipo' }),
    ).toBeVisible();

    await page.getByRole('link', { name: 'Ver en Carga' }).click();
    // /carga ordena los filtros de la URL a su manera: se comprueban sin depender del orden.
    await expect(page).toHaveURL(/\/carga\?/);
    const url = new URL(page.url());
    expect(url.searchParams.get('horizonte')).toBe('4-semanas');
    expect(url.searchParams.get('departamento')).toMatch(/^\d+$/);
    await expect(
        page.getByRole('heading', { level: 1, name: 'Carga' }),
    ).toBeVisible();
});
