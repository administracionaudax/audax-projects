// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';
import { CalendarHeatmap } from '@/components/charts/calendar-heatmap';
import { ChartTable } from '@/components/charts/chart-frame';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Toggle } from '@/components/ui/toggle';
import { FOCUS_RING } from '@/lib/focus-ring';

/**
 * Indicador de foco (UI-03, WCAG 1.4.11): anillo opaco con el token `--ring` (≥ 3:1, validado en
 * theme-contrast.test.ts). Un anillo al 50 % de opacidad bajaba a ≈ 2:1.
 */
// Vitest se ejecuta desde la raíz del proyecto (en jsdom, import.meta.url no es un file://).
const ROOT = join(process.cwd(), 'resources/js');
const GENERATED = ['actions', 'routes', 'wayfinder'];

function sources(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return GENERATED.includes(relative(ROOT, path))
                ? []
                : sources(path);
        }

        return /\.(ts|tsx)$/.test(name) ? [path] : [];
    });
}

const REQUIRED = [
    'focus-visible:ring-2',
    'focus-visible:ring-ring',
    'focus-visible:ring-offset-2',
];

function expectOpaqueRing(element: Element) {
    const classes = element.getAttribute('class') ?? '';

    for (const token of REQUIRED) {
        expect(classes.split(/\s+/), classes).toContain(token);
    }

    expect(classes).not.toMatch(/ring-[a-z-]+\/\d+/);
}

describe('anillo de foco', () => {
    it('FOCUS_RING usa el token opaco con separación', () => {
        for (const token of REQUIRED) {
            expect(FOCUS_RING.split(' ')).toContain(token);
        }
    });

    it('ningún componente usa anillos ni contornos de foco con opacidad', () => {
        const offenders = sources(ROOT).flatMap((path) => {
            const code = readFileSync(path, 'utf8');

            return [
                ...code.matchAll(
                    /\b(?:ring|outline)-(?:ring|destructive|white|sidebar-ring)\/\d+/g,
                ),
            ].map((m) => `${relative(ROOT, path)}: ${m[0]}`);
        });

        expect(offenders).toEqual([]);
    });

    it.each([
        ['botón', () => <Button>Guardar</Button>, 'button'],
        [
            'botón destructivo',
            () => <Button variant="destructive">Borrar</Button>,
            'button',
        ],
        [
            'botón fantasma',
            () => <Button variant="ghost">Ver</Button>,
            'button',
        ],
        ['campo de texto', () => <Input aria-label="Nombre" />, 'textbox'],
        [
            'interruptor',
            () => <Toggle aria-label="Negrita">B</Toggle>,
            'button',
        ],
        ['casilla', () => <Checkbox aria-label="Acepto" />, 'checkbox'],
    ] as const)('%s', (_label, ui, role) => {
        render(ui());
        expectOpaqueRing(screen.getByRole(role));
    });

    it('insignia interactiva', () => {
        render(
            <Badge asChild>
                <a href="/x">Nuevo</a>
            </Badge>,
        );
        expectOpaqueRing(screen.getByRole('link'));
    });

    it('tabla alternativa de las gráficas', () => {
        render(
            <ChartTable
                caption="Horas"
                table={{
                    columns: [{ key: 'label', label: 'Mes' }],
                    rows: [{ id: '1', label: 'enero' }],
                }}
            />,
        );
        expectOpaqueRing(screen.getByRole('region'));
    });

    it('calendario de calor', () => {
        render(
            <CalendarHeatmap days={[{ date: '2026-09-21', minutes: 60 }]} />,
        );
        expectOpaqueRing(
            screen.getByRole('group', { name: /usa las flechas/ }),
        );
    });
});
