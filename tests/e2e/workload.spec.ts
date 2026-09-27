import { expect, test } from '@playwright/test';
import { login, USERS } from './support';

/**
 * Fase 3 (PLAN-FASE-3 «Tests»): el responsable aprueba una ausencia y la vista Carga enseña la
 * capacidad reducida por las ausencias y los festivos. Sobre los datos del DemoDataSeeder (Lucía
 * tiene una solicitud pendiente y Elena unas vacaciones aprobadas la semana que viene). Nunca
 * contra el servidor (playwright.config.ts).
 */

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

test('Carga enseña a Elena sin capacidad los días de sus vacaciones de la semana que viene', async ({
    page,
}) => {
    await login(page, USERS.manager);

    // Por defecto, la semana que viene (D-052).
    await page.goto('/carga');
    await expect(
        page.getByRole('heading', { level: 1, name: 'Carga' }),
    ).toBeVisible();
    await expect(page.getByText(/Elena/).first()).toBeVisible();
    await expect(page.getByText('Ausencia').first()).toBeVisible();
});

test('una empleada solo ve su propia fila en Carga (D-052)', async ({
    page,
}) => {
    await login(page, USERS.employee);

    await page.goto('/carga');
    await expect(page.getByText(/Elena/).first()).toBeVisible();
    await expect(page.getByText(/Lucía Martín/)).toHaveCount(0);
});
