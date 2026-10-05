// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => ({ url: '/', props: {} }),
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

import { Button } from '@/components/ui/button';
import {
    editableReport,
    ReportEditDialog,
} from '@/components/weeklies/report-edit-dialog';
import AiUsagePage, { formatUsd } from '@/pages/admin/ai-usage';
import type {
    AiUsagePageProps,
    WeeklyCycleDetail,
    WeeklyReport,
} from '@/types/weeklies';

const totals = {
    calls: 4,
    errors: 1,
    prompt_tokens: 1500,
    response_tokens: 300,
    total_tokens: 1800,
    characters: 3000,
    cost_usd: '0.049200',
};

const page: AiUsagePageProps = {
    range: { days: 30, options: [7, 30, 90] },
    totals,
    by_feature: [
        { feature: 'speech', ...totals, calls: 1, cost_usd: '0.048000' },
    ],
    by_model: [{ provider: 'gemini', model: 'gemini-2.5-flash', ...totals }],
    by_day: [{ date: '2026-10-05', calls: 4, cost_usd: '0.049200' }],
    recent: [
        {
            id: 1,
            provider: 'gemini',
            model: 'gemini-2.5-flash',
            feature: 'weekly_report',
            operation: 'client_batch',
            status: 'error',
            latency_ms: 1200,
            prompt_tokens: null,
            response_tokens: null,
            total_tokens: null,
            character_count: null,
            estimated_cost_usd: null,
            error: 'HTTP 429',
            created_at: '2026-10-05T08:00:00Z',
        },
    ],
};

describe('«Uso de IA» (F-173 y F-180)', () => {
    it('enseña las sumas, por función y por modelo, el coste por día y las últimas llamadas', () => {
        render(<AiUsagePage {...page} />);

        const kpis = document.querySelector(
            '[data-test="ai-usage-totals"]',
        ) as HTMLElement;
        expect(
            within(kpis).getByText('Llamadas').nextSibling?.textContent,
        ).toBe('4');
        expect(
            within(kpis).getByText('Coste (USD)').nextSibling?.textContent,
        ).toBe('0,0492 $');
        expect(screen.getByRole('cell', { name: 'Locución' })).toBeTruthy();
        expect(screen.getAllByText('gemini-2.5-flash').length).toBeGreaterThan(
            0,
        );
        const row = document.querySelector(
            '[data-test="ai-usage-row"]',
        ) as HTMLElement;
        expect(within(row).getByText('Informe semanal')).toBeTruthy();
        expect(within(row).getByText('Error')).toBeTruthy();
        expect(within(row).getByText('HTTP 429')).toBeTruthy();
        expect(
            Array.from(
                document.querySelectorAll('[data-test="ai-usage-range"]'),
            ).map((link) => link.getAttribute('aria-current')),
        ).toEqual([null, 'page', null]);
        expect(formatUsd(null)).toBe('—');
    });
});

describe('editar el informe (F-077)', () => {
    const report: WeeklyReport = {
        global_summary: 'Resumen',
        team_risks: ['Riesgo'],
        client_updates: [
            {
                client_id: 1,
                client_name: 'Acme',
                status: 'risk',
                executive_summary: 'Texto',
                next_steps: ['Paso'],
                milestones: [{ date: null, label: 'Hito' }],
                tags: ['Web'],
                satisfaction_score: 60,
                has_reports: true,
                projects: [],
            },
        ],
    };

    it('el formulario parte de una copia del informe, sin lo que no se edita', () => {
        const form = editableReport(report);

        expect(form.client_updates[0]).toEqual({
            client_id: 1,
            client_name: 'Acme',
            status: 'risk',
            executive_summary: 'Texto',
            next_steps: ['Paso'],
            milestones: [{ date: '', label: 'Hito' }],
        });
        form.client_updates[0].next_steps.push('Otro');
        expect(report.client_updates[0].next_steps).toEqual(['Paso']);
    });

    it('abre con los campos de cada cliente y deja añadir y quitar pasos e hitos', async () => {
        const user = userEvent.setup();
        render(
            <ReportEditDialog
                cycle={{ id: 7, label: 'Semana 41' } as WeeklyCycleDetail}
                report={report}
                trigger={<Button>Editar informe</Button>}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Editar informe' }),
        );
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByRole('group', { name: 'Acme' }),
        ).toBeTruthy();
        expect(
            (
                within(dialog).getByLabelText(
                    'Resumen global',
                ) as HTMLTextAreaElement
            ).value,
        ).toBe('Resumen');
        expect(
            (
                within(dialog).getByLabelText(
                    'Resumen ejecutivo',
                ) as HTMLTextAreaElement
            ).value,
        ).toBe('Texto');

        await user.click(
            within(dialog).getByRole('button', { name: 'Añadir paso' }),
        );
        expect(
            within(dialog).getAllByLabelText(/^Próximos pasos \d$/),
        ).toHaveLength(2);
        await user.click(
            within(dialog).getByRole('button', { name: 'Quitar el hito 1' }),
        );
        expect(
            within(dialog).queryByLabelText('Descripción del hito 1'),
        ).toBeNull();
    });
});
