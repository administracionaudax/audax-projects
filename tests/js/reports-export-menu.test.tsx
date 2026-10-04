// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { ExportMenu } from '@/components/reports/export-menu';
import {
    reportRequestUrl,
    withTable,
} from '@/components/reports/report-request';
import type { ReportRequestData } from '@/types';

/*
| Menú «Exportar ▾» de los informes (Fase 9, D-139 y D-140): Excel, CSV y PDF descargan con los
| filtros de la página, Imprimir abre una pestaña, Google Sheets (9.4) va desactivado y «Enviar» y
| «Programar» (9.3) abren sus diálogos. El de una tabla solo lleva sus formatos.
*/

const CLIENT: ReportRequestData = {
    kind: 'client',
    route_params: { client: 4 },
    query: { periodo: 'mes', fecha: '2026-09-01', persona: [3, 5] },
};

describe('reportRequestUrl', () => {
    it('construye la URL de cada informe con sus filtros y el formato', () => {
        expect(reportRequestUrl(CLIENT, 'pdf')).toBe(
            '/informes/clientes/4?periodo=mes&fecha=2026-09-01&persona%5B%5D=3&persona%5B%5D=5&formato=pdf',
        );
        expect(
            reportRequestUrl(
                { kind: 'direction', route_params: {}, query: {} },
                'xlsx',
            ),
        ).toBe('/informes/direccion?formato=xlsx');
        expect(
            reportRequestUrl(
                {
                    kind: 'project_hours',
                    route_params: { project: 7 },
                    query: { desde: '2026-09-01' },
                },
                'csv',
            ),
        ).toBe('/proyectos/7/horas/exportar?desde=2026-09-01&formato=csv');
        expect(
            reportRequestUrl(
                {
                    kind: 'hour_bank',
                    route_params: { project: 7, hourBank: 9 },
                    query: {},
                },
                'imprimir',
            ),
        ).toBe('/proyectos/7/bolsas/9/pdf?formato=imprimir');
        expect(reportRequestUrl(withTable(CLIENT, 'bolsas'), 'xlsx')).toContain(
            'tabla=bolsas&formato=xlsx',
        );
    });

    it('un informe desconocido es un error, no una URL inventada', () => {
        expect(() =>
            reportRequestUrl({ kind: 'nada', route_params: {}, query: {} }),
        ).toThrow('Informe desconocido: nada');
    });
});

describe('ExportMenu', () => {
    it('del informe: Excel, CSV, PDF, Google Sheets, Imprimir, Enviar y Programar', async () => {
        const user = userEvent.setup();
        render(<ExportMenu request={CLIENT} title="Informe de Montó" />);

        await user.click(screen.getByRole('button', { name: 'Exportar' }));

        const items = screen.getAllByRole('menuitem');
        expect(items.map((item) => item.textContent)).toEqual([
            'Excel (.xlsx)',
            'CSV (.csv)',
            'PDF',
            'Google SheetsPróximamente',
            'Imprimir',
            'Enviar por correo…',
            'Programar envío…',
        ]);

        const link = (name: string) =>
            screen.getByRole('menuitem', { name }) as HTMLAnchorElement;
        expect(link('Excel (.xlsx)').getAttribute('href')).toBe(
            reportRequestUrl(CLIENT, 'xlsx'),
        );
        expect(link('CSV (.csv)').getAttribute('href')).toContain(
            'formato=csv',
        );
        expect(link('PDF').getAttribute('href')).toContain('formato=pdf');
        expect(link('PDF').hasAttribute('download')).toBe(true);
        expect(link('Imprimir').getAttribute('href')).toContain(
            'formato=imprimir',
        );
        expect(link('Imprimir').getAttribute('target')).toBe('_blank');
        expect(
            screen
                .getByRole('menuitem', { name: /Google Sheets/ })
                .getAttribute('aria-disabled'),
        ).toBe('true');
    });

    it('«Enviar por correo…» y «Programar envío…» abren su diálogo con el título del informe', async () => {
        const user = userEvent.setup();
        render(<ExportMenu request={CLIENT} title="Informe de Montó" />);

        await user.click(screen.getByRole('button', { name: 'Exportar' }));
        await user.click(
            screen.getByRole('menuitem', { name: 'Enviar por correo…' }),
        );
        const send = await screen.findByRole('dialog');
        expect(send.textContent).toContain('Informe de Montó');
        expect(send.textContent).toContain('Enviar por correo');
        await user.keyboard('{Escape}');

        await user.click(screen.getByRole('button', { name: 'Exportar' }));
        await user.click(
            screen.getByRole('menuitem', { name: 'Programar envío…' }),
        );
        const schedule = await screen.findByRole('dialog');
        expect(schedule.textContent).toContain('Programar envío');
        expect(schedule.textContent).toContain('Informe de Montó');
    });

    it('el de una tabla solo lleva Excel, CSV y Google Sheets de esa tabla', async () => {
        const user = userEvent.setup();
        render(
            <ExportMenu
                request={withTable(CLIENT, 'proyectos')}
                title="Informe de Montó"
                label="Exportar el resumen"
                scope="table"
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Exportar el resumen' }),
        );

        expect(
            screen.getAllByRole('menuitem').map((item) => item.textContent),
        ).toEqual(['Excel (.xlsx)', 'CSV (.csv)', 'Google SheetsPróximamente']);
        expect(
            screen
                .getByRole('menuitem', { name: 'Excel (.xlsx)' })
                .getAttribute('href'),
        ).toContain('tabla=proyectos&formato=xlsx');
        expect(screen.queryByRole('menuitem', { name: 'PDF' })).toBeNull();
    });
});
