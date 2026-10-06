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
});
