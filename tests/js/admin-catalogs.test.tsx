import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { colorName } from '@/components/admin/color-picker';
import { parseDayMinutes } from '@/components/admin/day-minutes-input';
import { iconLabel } from '@/components/admin/icon-picker';
import { TASK_TYPE_ICONS } from '@/components/admin/task-type-icon';
import { cleanFilters } from '@/components/admin/use-list-filters';
import { weekTotal } from '@/components/admin/week-minutes-input';

/** Lee una lista de cadenas de una constante PHP (const array NAME = [...]). */
function phpList(file: string, constant: string): string[] {
    const source = readFileSync(join(process.cwd(), file), 'utf8');
    const block = new RegExp(
        `const array ${constant} = \\[([\\s\\S]*?)\\];`,
    ).exec(source);

    return [...(block?.[1] ?? '').matchAll(/'([^']+)'/g)].map(
        (match) => match[1],
    );
}

describe('catálogo de iconos de los tipos de tarea', () => {
    const php = phpList('app/Domain/Admin/TaskTypeIcons.php', 'ICONS');

    it('el frontend tiene exactamente los iconos que admite el servidor', () => {
        expect(php.length).toBeGreaterThan(20);
        expect(Object.keys(TASK_TYPE_ICONS).sort()).toEqual([...php].sort());
    });

    it('incluye los iconos de los tipos por defecto (TaskType::DEFAULTS)', () => {
        const source = readFileSync(
            join(process.cwd(), 'app/Models/TaskType.php'),
            'utf8',
        );
        const defaults = [...source.matchAll(/'icon' => '([^']+)'/g)].map(
            (match) => match[1],
        );

        expect(defaults.length).toBe(10);
        defaults.forEach((icon) => expect(php).toContain(icon));
    });

    it('cada icono tiene un nombre en español para los lectores de pantalla', () => {
        php.forEach((icon) => expect(iconLabel(icon)).not.toBe(icon));
        expect(iconLabel('code-xml')).toBe('Código');
    });
});

describe('paleta de marca', () => {
    it('coincide con App\\Domain\\Admin\\Palette y cada color tiene nombre', () => {
        const php = phpList('app/Domain/Admin/Palette.php', 'COLORS');

        expect(php).toEqual([
            '#0171FF',
            '#179FA5',
            '#5E2DAD',
            '#E65FB3',
            '#3C41AE',
            '#0892C4',
            '#56667A',
        ]);
        php.forEach((color) => expect(colorName(color)).not.toBe(color));
        expect(colorName('#0171ff')).toBe('Azul');
        expect(colorName('#123456')).toBe('#123456');
    });
});

describe('jornada de un día', () => {
    it.each([
        ['8:00', 480],
        ['7,5', 450],
        ['7.5', 450],
        ['8h', 480],
        ['6h30', 390],
        ['90m', 90],
        ['24:00', 1440],
        ['', 0],
        ['0', 0],
        ['0:00', 0],
        ['0h', 0],
        ['  ', 0],
        ['24:30', null],
        ['25', null],
        ['-1', null],
        ['ocho', null],
    ] as const)('«%s» → %s', (input, expected) => {
        expect(parseDayMinutes(input)).toBe(expected);
    });

    it('suma la semana contando 0 los días no válidos', () => {
        expect(weekTotal([480, 480, 480, 480, 360, 0, 0])).toBe(2280);
        expect(weekTotal([480, null, 480])).toBe(960);
    });
});

describe('filtros en la URL', () => {
    it('quita los vacíos y los que tienen el valor por defecto', () => {
        expect(
            cleanFilters(
                {
                    q: '',
                    rol: 'employee',
                    departamento: null,
                    estado: 'activos',
                },
                { estado: 'activos' },
            ),
        ).toEqual({ rol: 'employee' });
        expect(
            cleanFilters({ q: 'ana', estado: 'todos' }, { estado: 'activos' }),
        ).toEqual({ q: 'ana', estado: 'todos' });
    });
});
