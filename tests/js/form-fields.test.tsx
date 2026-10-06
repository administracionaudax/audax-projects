// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';
import { DatePicker } from '@/components/domain/date-picker';

/**
 * Revisión de formularios (D-310): los disparadores que hacen de campo (selector de fecha,
 * buscadores y comboboxes) se ven como el resto de campos, no como un botón con borde azul.
 */
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

        return name.endsWith('.tsx') ? [path] : [];
    });
}

describe('campos con aspecto de campo', () => {
    it('el selector de fecha usa el borde gris de los campos', () => {
        render(
            <DatePicker value={null} onChange={() => {}} aria-label="Fecha" />,
        );
        const trigger = screen.getByRole('button', { name: 'Fecha' });

        expect(trigger.className).toContain('border-input');
        expect(trigger.className).not.toContain('border-primary');
    });

    it('ningún combobox es un botón «outline» (borde azul)', () => {
        const offenders = sources(ROOT).filter((file) =>
            /variant="outline"\s+role="combobox"/.test(
                readFileSync(file, 'utf8'),
            ),
        );

        expect(offenders.map((file) => relative(ROOT, file))).toEqual([]);
    });

    it('las celdas «grid» de las filas de varias columnas se alinean arriba (sin escalones)', () => {
        // Una celda `grid gap-*` sin content-start se estira al alto de la vecina (ayuda o error en
        // una columna) y reparte el hueco entre la etiqueta y la caja: la caja baja (D-310).
        const offenders: string[] = [];

        for (const file of sources(ROOT)) {
            if (file.includes('/components/ui/')) {
                continue;
            }

            const lines = readFileSync(file, 'utf8').split('\n');

            lines.forEach((line, index) => {
                if (
                    !/<(div|fieldset|form)\s+className=.*\b(?:\w+:)?grid-cols-[2-6]\b/.test(
                        line,
                    ) ||
                    /items-start/.test(line)
                ) {
                    return;
                }

                const indent = line.length - line.trimStart().length;

                for (let next = index + 1; next < lines.length; next++) {
                    const child = lines[next];

                    if (child.trim() === '') {
                        continue;
                    }

                    const childIndent = child.length - child.trimStart().length;

                    if (childIndent <= indent) {
                        break;
                    }

                    if (
                        [indent + 4, indent + 8, indent + 12].includes(
                            childIndent,
                        ) &&
                        /^\s*<div\s+className="[^"]*\bgrid\b[^"]*\bgap-[^"]*"/.test(
                            child,
                        ) &&
                        !/content-start|self-start|content-between|items-start|grid-cols|col-span|\bborder\b/.test(
                            child,
                        )
                    ) {
                        offenders.push(`${relative(ROOT, file)}:${next + 1}`);
                    }
                }
            });
        }

        expect(offenders).toEqual([]);
    });

    it('las acciones del plan del día no pierden los errores del servidor', () => {
        // Bolsa de errores `dayPlan` sin onError: la casilla volvía atrás sin decir nada (D-310).
        const offenders: string[] = [];

        for (const file of sources(ROOT)) {
            const lines = readFileSync(file, 'utf8').split('\n');

            lines.forEach((line, index) => {
                if (!line.includes("errorBag: 'dayPlan'")) {
                    return;
                }

                // Hasta el cierre del objeto de opciones (la primera línea menos sangrada).
                const indent = line.length - line.trimStart().length;
                let handled = false;

                for (let next = index + 1; next < lines.length; next++) {
                    const other = lines[next];

                    if (
                        other.trim() !== '' &&
                        other.length - other.trimStart().length < indent
                    ) {
                        break;
                    }

                    handled ||= other.includes('onError');
                }

                if (!handled) {
                    offenders.push(`${relative(ROOT, file)}:${index + 1}`);
                }
            });
        }

        expect(offenders).toEqual([]);
    });
});
