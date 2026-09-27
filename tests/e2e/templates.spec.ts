import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Plantillas de proyecto y tareas recurrentes (Fase 4, D-058 y D-059), sobre los datos de ejemplo
 * del DemoDataSeeder. Nunca contra el servidor (playwright.config.ts).
 * - Crear un proyecto desde una plantilla (importada en JSON) y ver sus tareas y dependencias.
 * - Crear una regla semanal para hoy y ver la tarea que se crea al momento.
 */

const WEEKDAYS = [
    'domingo',
    'lunes',
    'martes',
    'miércoles',
    'jueves',
    'viernes',
    'sábado',
];

/** Hoy en Madrid, como lo ve la app. */
function todayInMadrid(): { iso: string; weekday: string; label: string } {
    const iso = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Madrid',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
    const [year, month, day] = iso.split('-').map(Number);
    const weekday =
        WEEKDAYS[new Date(Date.UTC(year, month - 1, day)).getUTCDay()];

    return {
        iso,
        weekday,
        label: `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`,
    };
}

async function importTemplate(page: Page, name: string): Promise<void> {
    await page.goto('/admin/plantillas');
    await page.getByRole('button', { name: 'Importar' }).click();

    const dialog = page.getByRole('dialog', { name: 'Importar una plantilla' });
    await dialog.getByLabel('Fichero JSON').setInputFiles({
        name: 'plantilla-e2e.json',
        mimeType: 'application/json',
        buffer: Buffer.from(
            JSON.stringify({
                format: 'audax-project-template',
                version: 1,
                name,
                description: 'Plantilla de prueba de extremo a extremo',
                structure: {
                    tasks: [
                        {
                            ref: 'dis',
                            title: 'Diseño E2E',
                            start_offset_days: 0,
                            duration_days: 5,
                            estimated_minutes: 600,
                        },
                        {
                            ref: 'home',
                            parent_ref: 'dis',
                            title: 'Home E2E',
                            start_offset_days: 0,
                            duration_days: 2,
                        },
                        {
                            ref: 'dev',
                            title: 'Desarrollo E2E',
                            start_offset_days: 7,
                            duration_days: 10,
                        },
                        {
                            ref: 'go',
                            title: 'Publicación E2E',
                            start_offset_days: 17,
                            is_milestone: true,
                        },
                    ],
                    dependencies: [
                        { from_ref: 'dis', to_ref: 'dev' },
                        { from_ref: 'dev', to_ref: 'go' },
                    ],
                },
            }),
        ),
    });
    await dialog.getByRole('button', { name: 'Importar plantilla' }).click();

    // Se abre el editor de la plantilla importada, con su cronograma.
    await expect(page).toHaveURL(/\/admin\/plantillas\/\d+\/editar$/);
    await expect(
        page.getByLabel('Título de la tarea 1', { exact: true }),
    ).toHaveValue('Diseño E2E');
    await expect(
        page.getByLabel('Título de la tarea 1.1', { exact: true }),
    ).toHaveValue('Home E2E');
    await expect(page.locator('[data-test="template-timeline"]')).toContainText(
        'Tareas: 4 · Hitos: 1',
    );
}

test('crear un proyecto desde una plantilla con sus tareas y dependencias', async ({
    page,
}) => {
    const suffix = Date.now().toString(36);
    const templateName = `Plantilla E2E ${suffix}`;
    const projectName = `Proyecto desde plantilla ${suffix}`;

    await login(page, USERS.admin);
    await importTemplate(page, templateName);

    await page.goto('/proyectos/nuevo');
    await page.getByLabel('Nombre', { exact: true }).fill(projectName);
    await page.getByLabel('Tipo de facturación').click();
    await page.getByRole('option', { name: 'Interno' }).click();

    await page.getByRole('radio', { name: 'Desde plantilla' }).click();
    await page
        .getByLabel('Plantilla', { exact: true })
        .selectOption({ label: templateName });
    await expect(
        page.getByText(
            '4 tareas (1 subtarea) · 1 hito · 2 dependencias · 18 días',
        ),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Crear el proyecto' }).click();

    await expect(
        page.getByRole('heading', { name: projectName }),
    ).toBeVisible();
    await expect(
        page.getByText(
            `Proyecto creado con 4 tareas de la plantilla «${templateName}».`,
        ),
    ).toBeVisible();

    // Las tareas en la lista.
    await page
        .getByRole('navigation', { name: 'Secciones del proyecto' })
        .getByRole('link', { name: 'Tareas' })
        .click();
    for (const title of ['Diseño E2E', 'Desarrollo E2E', 'Publicación E2E']) {
        await expect(
            page.locator('[data-test="task-title"]').filter({ hasText: title }),
        ).toBeVisible();
    }

    // Las dependencias en el panel de la tarea (D-062): «Desarrollo» depende de «Diseño».
    await page
        .locator('[data-test="task-title"]')
        .filter({ hasText: 'Desarrollo E2E' })
        .click();
    const panel = page.getByRole('dialog');
    await expect(panel).toBeVisible();
    await expect(panel.getByText('Diseño E2E')).toBeVisible();
    await expect(panel.getByText('Publicación E2E')).toBeVisible();
});

test('crear una regla semanal para hoy crea ya la tarea de hoy', async ({
    page,
}) => {
    const suffix = Date.now().toString(36);
    const title = `Revisión semanal ${suffix}`;
    const today = todayInMadrid();

    // Raúl (responsable de Diseño) gestiona «Web corporativa» (ARR-WEB).
    await login(page, USERS.manager);
    await page.goto('/proyectos');
    await page.getByRole('link', { name: 'Web corporativa' }).first().click();
    await page
        .getByRole('navigation', { name: 'Secciones del proyecto' })
        .getByRole('link', { name: 'Ajustes' })
        .click();

    const section = page.getByRole('region', { name: 'Tareas recurrentes' });
    await section
        .getByRole('button', { name: 'Nueva tarea recurrente' })
        .click();

    const dialog = page.getByRole('dialog', { name: 'Nueva tarea recurrente' });
    await dialog.getByLabel('Título', { exact: true }).fill(title);
    await dialog
        .getByLabel('Bolsa', { exact: true })
        .selectOption({ index: 1 });
    await dialog
        .getByLabel('Día de la semana')
        .selectOption({ label: today.weekday });
    await expect(dialog.locator('[data-test="rule-preview"]')).toContainText(
        'Hoy toca: la tarea de hoy se creará al guardar.',
    );
    await dialog
        .getByRole('button', { name: 'Crear tarea recurrente' })
        .click();

    await expect(dialog).toBeHidden();
    await expect(
        page.getByText(
            `Tarea recurrente «${title}» creada. Hoy toca: ya está creada la tarea de hoy.`,
        ),
    ).toBeVisible();

    const rule = section
        .locator('[data-test="recurring-rule"]')
        .filter({ hasText: title });
    await expect(rule).toContainText(
        `Cada semana, los ${today.weekday === 'sábado' || today.weekday === 'domingo' ? `${today.weekday}s` : today.weekday}`,
    );
    await expect(rule).toContainText('Activa');

    // La tarea de hoy aparece en «Últimas tareas creadas» y abre su panel.
    const recent = section.locator('[data-test="recurring-recent"]');
    await expect(recent).toContainText(today.label);
    await recent.getByRole('link', { name: title }).first().click();
    await expect(page).toHaveURL(/\/tareas\?tarea=\d+/);
    await expect(page.getByRole('dialog')).toBeVisible();
});
