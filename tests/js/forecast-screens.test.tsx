// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import {
    configure,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { AllocationDialog } from '@/components/forecast/allocation-dialog';
import { DeviationBadge } from '@/components/forecast/deviation';
import {
    ForecastCell,
    forecastCellLabel,
    GapCell,
    noCapacityReason,
} from '@/components/forecast/forecast-cell';
import { ForecastCellTooltip } from '@/components/forecast/forecast-cell-tooltip';
import { ForecastMatrix } from '@/components/forecast/forecast-matrix';
import { ImpactGrid, ImpactSentence } from '@/components/forecast/impact';
import { LayerBar } from '@/components/forecast/layer-bar';
import { LayerToggles } from '@/components/forecast/layer-toggles';
import {
    COLLABORATORS_GROUP,
    matrixGroups,
    matrixRows,
} from '@/components/forecast/matrix-model';
import { MyForecastCard } from '@/components/forecast/my-forecast';
import {
    ALL_LAYERS,
    boardFigures,
    bucketLabel,
    cellItems,
    formatHours,
    impactWorst,
    parseLayers,
    serializeLayers,
} from '@/lib/forecast';
import type {
    ForecastBoard,
    ForecastImpact,
    ForecastPerson,
    LoadCell,
    LoadSource,
    MyForecast,
} from '@/types/forecast';

configure({ testIdAttribute: 'data-test' });

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: '/prevision', props: { auth: { can: {} } } }),
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...(rest as Record<string, string>)}>
            {children}
        </a>
    ),
}));

/*
| Pantallas de la previsión (D-290 a D-307): el modelo de la matriz, la celda y su tooltip, la
| matriz con su teclado, el impacto «sin / con», las capas, el formulario de una asignación y la
| tarjeta «Mi carga».
*/

const cell = (
    capacity: number,
    real = 0,
    firm = 0,
    tentative = 0,
): LoadCell => ({ capacity, real, firm, tentative });

function person(
    overrides: Partial<ForecastPerson> & Pick<ForecastPerson, 'id' | 'name'>,
): ForecastPerson {
    return {
        department_id: 1,
        avatar: null,
        collaborator: false,
        has_schedule: true,
        weekly_minutes: 2400,
        cells: [cell(2400, 1200), cell(2400, 1200, 960, 480)],
        absences: [null, null],
        ...overrides,
    };
}

function source(overrides: Partial<LoadSource>): LoadSource {
    return {
        allocation_id: 1,
        layer: 'real',
        user_id: 10,
        department_id: null,
        mode: 'total',
        project: { id: 5, code: 'KIW', name: 'App fase 2', color: '#0171FF' },
        forecast: null,
        client_name: 'Kiwi',
        minutes: [1200, 1200],
        ...overrides,
    };
}

function board(): ForecastBoard {
    return {
        period: {
            from: '2026-11-02',
            to: '2026-11-15',
            granularity: 'week',
            today: '2026-11-02',
            counts_from: '2026-11-02',
        },
        buckets: [
            { key: '2026-W45', from: '2026-11-02', to: '2026-11-08' },
            { key: '2026-W46', from: '2026-11-09', to: '2026-11-15' },
        ],
        holidays: [[], [{ date: '2026-11-09', name: 'Almudena' }]],
        people: [
            person({ id: 10, name: 'Luis Martín' }),
            person({
                id: 11,
                name: 'Ana López',
                cells: [cell(2400, 600), cell(0)],
                absences: [null, { days: 5, partial: false, type: 'vacation' }],
            }),
            person({
                id: 20,
                name: 'Amparo',
                department_id: null,
                collaborator: true,
                has_schedule: false,
                weekly_minutes: 0,
                cells: [cell(0, 600), cell(0)],
            }),
        ],
        departments: [
            {
                id: 1,
                name: 'Diseño',
                color: '#0171FF',
                people: 2,
                cells: [cell(4800, 1800, 0, 1200), cell(2400, 1200, 960, 480)],
                gaps: [
                    { real: 0, firm: 0, tentative: 1200 },
                    { real: 0, firm: 0, tentative: 0 },
                ],
            },
        ],
        totals: [cell(4800, 1800, 0, 1200), cell(2400, 1200, 960, 480)],
        sources: [
            source({}),
            source({
                allocation_id: 2,
                layer: 'firm',
                project: null,
                forecast: { id: 7, name: 'Web', color: '#5E2DAD' },
                client_name: 'Hotel',
                minutes: [0, 960],
            }),
            source({
                allocation_id: 3,
                layer: 'tentative',
                user_id: null,
                department_id: 1,
                project: null,
                forecast: { id: 8, name: 'Branding', color: '#5E2DAD' },
                client_name: null,
                minutes: [1200, 0],
            }),
            source({ allocation_id: 4, user_id: 11, minutes: [600, 0] }),
            source({ allocation_id: 5, user_id: 20, minutes: [600, 0] }),
        ],
        overdue: [],
        unscheduled: [],
    };
}

describe('lib de las pantallas', () => {
    it('las capas van en la URL en español y sin parámetro si están todas', () => {
        expect(parseLayers(null)).toEqual(ALL_LAYERS);
        expect(parseLayers('real,posible')).toEqual({
            real: true,
            firm: false,
            tentative: true,
        });
        expect(serializeLayers(ALL_LAYERS)).toBeNull();
        expect(
            serializeLayers({ real: true, firm: true, tentative: false }),
        ).toBe('real,seguro');
    });

    it('horas redondeadas con espacio duro y etiquetas de periodo', () => {
        expect(formatHours(74400)).toBe('1.240 h');
        expect(formatHours(20)).toBe('<1 h');
        expect(
            bucketLabel(
                { key: '2026-W46', from: '2026-11-09', to: '2026-11-15' },
                'week',
            ),
        ).toEqual({
            short: 'S46',
            sub: '9 nov',
            long: 'semana 46 (9 nov – 15 nov)',
        });
    });

    it('de qué proyectos sale una celda: de real a posible y de más a menos horas', () => {
        const items = cellItems(board().sources, 1, () => true);

        expect(items.map((item) => [item.layer, item.title])).toEqual([
            ['real', 'Kiwi · App fase 2'],
            ['firm', 'Hotel · Web'],
        ]);
        expect(items[0].minutes).toBe(1200);
        expect(items[1].href).toBe('/prevision/proyectos/7');
    });

    it('las cifras del horizonte con las capas encendidas', () => {
        const figures = boardFigures(board(), ALL_LAYERS, 'Sin departamento');

        expect(figures.capacity).toBe(7200);
        expect(figures.load).toBe(5640);
        expect(figures.realOnly).toBe(3000);
        expect(figures.gapsByDepartment).toEqual([
            { name: 'Diseño', minutes: 1200 },
        ]);
        expect(
            boardFigures(
                board(),
                { real: true, firm: false, tentative: false },
                '',
            ).gapMinutes,
        ).toBe(0);
    });

    it('el peor caso del impacto es la celda con más % con el previsto', () => {
        const buckets = board().buckets;
        const worst = impactWorst(
            [
                {
                    name: 'Diseño',
                    cells: [
                        { capacity: 2400, without: 2000, with: 2400 },
                        { capacity: 2400, without: 2200, with: 2650 },
                    ],
                },
            ],
            buckets,
        );

        expect(worst).toMatchObject({
            name: 'Diseño',
            without: 92,
            with: 110,
            level: 'high',
        });
        expect(worst?.bucket.key).toBe('2026-W46');
    });
});

describe('modelo de la matriz', () => {
    it('departamentos y, aparte, los colaboradores externos (D-300); la búsqueda filtra personas', () => {
        const groups = matrixGroups(board());

        expect(groups.map((group) => group.key)).toEqual([
            'd1',
            COLLABORATORS_GROUP,
        ]);
        expect(groups[1].people.map((item) => item.name)).toEqual(['Amparo']);
        expect(groups[1].cells[0]).toEqual(cell(0, 600));
        expect(
            matrixGroups(board(), 'lopez').map((group) =>
                group.people.map((item) => item.name),
            ),
        ).toEqual([['Ana López']]);
    });

    it('filas visibles: plegado, solo la cabecera; la fila «Sin persona» solo con huecos', () => {
        const groups = matrixGroups(board());
        const open = matrixRows(groups, () => true, ALL_LAYERS).map(
            (row) => row.key,
        );
        const folded = matrixRows(
            groups,
            (key) => key !== 'd1',
            ALL_LAYERS,
        ).map((row) => row.key);

        expect(open).toEqual([
            'g:d1',
            'p:10',
            'p:11',
            'h:1',
            'g:collaborators',
            'p:20',
        ]);
        expect(folded).toEqual(['g:d1', 'g:collaborators', 'p:20']);
        expect(
            matrixRows(groups, () => true, {
                real: true,
                firm: true,
                tentative: false,
            }).map((row) => row.key),
        ).not.toContain('h:1');
    });
});

describe('celdas', () => {
    it('la celda dice su % con icono y su nombre accesible el nivel y las horas', () => {
        const value = cell(2400, 1200, 960, 960);
        const { container } = render(
            <ForecastCell
                cell={value}
                layers={ALL_LAYERS}
                absence={null}
                reason="none"
            />,
        );

        expect(container?.textContent).toContain('130 %');
        expect(container.querySelector('[data-level="over"]')).not.toBeNull();
        expect(
            forecastCellLabel({
                name: 'Luis Martín',
                period: 'semana 46 (9 nov – 15 nov)',
                cell: value,
                layers: ALL_LAYERS,
                absence: { days: 1, partial: false, type: null },
                reason: 'none',
            }),
        ).toBe(
            'Luis Martín, semana 46 (9 nov – 15 nov): 130 %, Sobrecarga, 52 h de 40 h, capacidad reducida por ausencia',
        );
    });

    it('con ausencia parcial lleva la muesca; sin capacidad dice el motivo; sin jornada, nunca un %', () => {
        const absence = { days: 1, partial: true, type: null };
        const { container, rerender } = render(
            <ForecastCell
                cell={cell(1920, 960)}
                layers={ALL_LAYERS}
                absence={absence}
                reason="none"
            />,
        );
        expect(
            container.querySelector('[data-test="absence-notch"]'),
        ).not.toBeNull();

        const full = { days: 5, partial: false, type: null };
        rerender(
            <ForecastCell
                cell={cell(0)}
                layers={ALL_LAYERS}
                absence={full}
                reason={noCapacityReason({
                    cell: cell(0),
                    absence: full,
                    holidays: 0,
                    hasSchedule: true,
                })}
            />,
        );
        expect(container?.textContent).toContain('Ausencia');

        rerender(
            <ForecastCell
                cell={cell(0, 600)}
                layers={ALL_LAYERS}
                absence={null}
                reason={noCapacityReason({
                    cell: cell(0),
                    absence: null,
                    holidays: 0,
                    hasSchedule: false,
                })}
            />,
        );
        expect(container?.textContent).toContain('10 h');
        expect(container?.textContent).toContain('Sin jornada');
        expect(container?.textContent).not.toContain('%');
    });

    it('la barra de capas va del 0 al 150 % y marca si se pasa', () => {
        const { container, rerender } = render(
            <LayerBar
                minutes={cell(2400, 1200, 0, 600)}
                capacity={2400}
                layers={ALL_LAYERS}
            />,
        );
        const segments = container.querySelectorAll('[data-layer]');

        expect(segments).toHaveLength(2);
        expect((segments[0] as HTMLElement).style.width).toContain('33.3');
        expect(
            container.querySelector('[data-test="layer-bar-more"]'),
        ).toBeNull();

        rerender(
            <LayerBar
                minutes={cell(2400, 4000)}
                capacity={2400}
                layers={ALL_LAYERS}
            />,
        );
        expect(
            container.querySelector('[data-test="layer-bar-more"]'),
        ).not.toBeNull();
    });

    it('un hueco: borde discontinuo y horas, vacío sin nada', () => {
        const { container, rerender } = render(
            <GapCell
                minutes={{ real: 0, firm: 0, tentative: 1200 }}
                layers={ALL_LAYERS}
            />,
        );
        expect(container?.textContent).toContain('20 h');

        rerender(
            <GapCell
                minutes={{ real: 0, firm: 0, tentative: 1200 }}
                layers={{ real: true, firm: true, tentative: false }}
            />,
        );
        expect(container.textContent).toBe('');
    });

    it('el tooltip: el % grande, las filas por capa y «y N proyectos más»', () => {
        const items = Array.from({ length: 9 }, (_, index) => ({
            key: `p${index}`,
            layer: index === 0 ? ('tentative' as const) : ('real' as const),
            title: `Proyecto ${index}`,
            minutes: 60,
            href: '#',
        }));
        render(
            <ForecastCellTooltip
                title="Diseño · semana 46"
                load={2880}
                capacity={2400}
                items={items}
                layers={{ ...ALL_LAYERS, tentative: false }}
            />,
        );
        const tooltip = screen.getByTestId('forecast-tooltip');

        expect(tooltip?.textContent).toContain('120 %');
        expect(tooltip?.textContent).toContain('Alta');
        expect(tooltip?.textContent).toContain('y 2 proyectos más');
        expect(tooltip?.textContent).toContain('Se pasa en 8 h');
        expect(tooltip?.textContent).toContain(
            'Proyecto 0 · posible (capa apagada)',
        );
    });
});

describe('matriz', () => {
    function renderMatrix(onOpen = vi.fn(), onToggle = vi.fn()) {
        const data = board();
        render(
            <ForecastMatrix
                board={data}
                groups={matrixGroups(data)}
                layers={ALL_LAYERS}
                isExpanded={() => true}
                onToggle={onToggle}
                openKey={null}
                onOpen={onOpen}
            />,
        );

        return { onOpen, onToggle };
    }

    it('una rejilla con una sola parada de tabulación; las flechas mueven el foco e Intro abre el panel', async () => {
        const user = userEvent.setup();
        const { onOpen } = renderMatrix();
        const grid = screen.getByRole('grid');
        const focusable = grid.querySelectorAll('[tabindex="0"]');

        expect(focusable).toHaveLength(1);
        (focusable[0] as HTMLElement).focus();
        await user.keyboard('{ArrowDown}');
        expect(document.activeElement?.getAttribute('aria-label')).toMatch(
            /^Luis Martín, semana 45/,
        );
        await user.keyboard('{ArrowRight}');
        expect(document.activeElement?.getAttribute('aria-label')).toMatch(
            /^Luis Martín, semana 46/,
        );
        await user.keyboard('{Enter}');
        expect(onOpen).toHaveBeenCalledWith(
            expect.objectContaining({ index: 1 }),
        );
    });

    it('el tooltip sale con el foco y dice de dónde sale cada hora; Escape lo cierra', async () => {
        const user = userEvent.setup();
        renderMatrix();
        const cellButton = screen.getAllByTestId('forecast-cell-button')[0];

        fireEvent.focus(cellButton);
        expect(screen.getByRole('tooltip')?.textContent).toContain(
            'Kiwi · App fase 2',
        );
        cellButton.focus();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('tooltip')).toBeNull();
    });

    it('la cabecera de cada grupo se pliega con su botón o con Espacio; los festivos van en la cabecera', async () => {
        const user = userEvent.setup();
        const { onToggle } = renderMatrix();
        const toggles = screen.getAllByTestId('forecast-group-toggle');

        expect(toggles[0]?.getAttribute('aria-expanded')).toBe('true');
        await user.click(toggles[0]);
        expect(onToggle).toHaveBeenCalledWith('d1');
        expect(screen.getAllByTestId('holiday-mark')).toHaveLength(1);
        expect(
            screen.getByRole('columnheader', { name: /semana 46.*Almudena/ }),
        ).toBeTruthy();
        expect(screen.getByText('Colaboradores externos')).toBeTruthy();
        expect(
            screen.getByText('Colaboración externa · sin jornada'),
        ).toBeTruthy();
    });
});

describe('capas', () => {
    it('tres casillas que son leyenda y filtro', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<LayerToggles value={ALL_LAYERS} onChange={onChange} />);

        const button = screen.getByRole('button', { name: /Previsto posible/ });
        expect(button?.getAttribute('aria-pressed')).toBe('true');
        await user.click(button);
        expect(onChange).toHaveBeenCalledWith({
            real: true,
            firm: true,
            tentative: false,
        });
    });
});

describe('impacto «sin / con»', () => {
    const impact: ForecastImpact = {
        buckets: board().buckets,
        granularity: 'week',
        layer: 'tentative',
        departments: [
            {
                id: 1,
                name: 'Diseño',
                color: '#0171FF',
                cells: [
                    { capacity: 2400, without: 2184, with: 2424 },
                    { capacity: 2400, without: 2000, with: 2000 },
                ],
            },
        ],
        people: [
            {
                id: 10,
                name: 'Luis Martín',
                department_id: 1,
                cells: [
                    { capacity: 2400, without: 1920, with: 3120 },
                    { capacity: 2400, without: 1920, with: 1920 },
                ],
            },
        ],
    };

    it('la frase con el peor caso de departamento y de persona', () => {
        render(<ImpactSentence impact={impact} />);

        expect(screen.getByTestId('impact-sentence')?.textContent).toContain(
            'Si se coge, Diseño pasa del 91 % al 101 % la semana 45 (2 nov – 8 nov)Alta, y Luis Martín llega al 130 %Sobrecarga.',
        );
    });

    it('la rejilla por departamento y persona, con «91 → 101 %» y su nombre accesible', () => {
        render(<ImpactGrid impact={impact} />);

        expect(screen.getAllByTestId('impact-row')).toHaveLength(2);
        expect(screen.getAllByTestId('impact-cell')[0]?.textContent).toContain(
            '91→101 %',
        );
        expect(
            screen.getByRole('cell', {
                name: /semana 45.*del 91 % al 101 %, Alta/,
            }),
        ).toBeTruthy();
    });
});

describe('desviación', () => {
    it('flecha, signo y aviso por encima de ±10 %', () => {
        const { container, rerender } = render(
            <DeviationBadge percent={12.5} />,
        );
        expect(container?.textContent).toContain('+12,5 %');
        expect(container?.textContent).toContain('desviación alta');

        rerender(<DeviationBadge percent={0.2} />);
        expect(container?.textContent).toContain('Igual que lo estimado');
    });
});

describe('formulario de una asignación', () => {
    it('cuatro modos; en %, el porcentaje; envía al previsto', async () => {
        const user = userEvent.setup();
        const post = vi.spyOn(coreRouter, 'post').mockImplementation(() => {});
        render(
            <AllocationDialog
                container={{ kind: 'forecast', id: 7 }}
                people={[
                    { id: 10, name: 'Luis Martín', department_id: 1 },
                    {
                        id: 20,
                        name: 'Amparo',
                        department_id: null,
                        collaborator: true,
                    },
                ]}
                departments={[{ id: 1, name: 'Diseño', color: '#0171FF' }]}
                defaults={{ start: '2026-11-02', end: '2026-12-18' }}
                open
                onOpenChange={() => {}}
            />,
        );

        const form = screen.getByTestId('allocation-form');
        expect(within(form).getAllByRole('radio')).toHaveLength(6);
        expect(
            within(form).getByRole('group', { name: 'Colaboradores externos' }),
        ).toBeTruthy();
        await user.selectOptions(
            within(form).getByTestId('allocation-person'),
            '20',
        );
        await user.click(
            within(form).getByRole('radio', { name: '% de la jornada' }),
        );
        const percent = within(form).getByLabelText(
            'Porcentaje de su jornada',
        ) as HTMLInputElement;
        await user.type(percent, '50');
        await user.click(
            within(form).getByRole('button', { name: 'Añadir asignación' }),
        );

        expect(post).toHaveBeenCalledWith(
            '/prevision/proyectos/7/asignaciones',
            expect.objectContaining({
                user_id: 20,
                department_id: null,
                mode: 'percent',
                percent: 50,
                minutes: null,
                start_date: '2026-11-02',
                end_date: '2026-12-18',
            }),
            expect.anything(),
        );
        post.mockRestore();
    });
});

describe('Mi carga en Inicio', () => {
    it('esta semana y la que viene, las 12 semanas y «Lo que viene» con los posibles dichos en texto (P8)', () => {
        const data: MyForecast = {
            board: {
                ...board(),
                people: [person({ id: 10, name: 'Luis Martín' })],
                departments: [],
            },
            allocations: [
                {
                    id: 1,
                    layer: 'real',
                    mode: 'per_day',
                    minutes: 480,
                    percent: null,
                    start_date: '2026-10-01',
                    end_date: '2026-11-27',
                    note: null,
                    container: {
                        kind: 'project',
                        id: 5,
                        name: 'App fase 2',
                        client_name: 'Kiwi',
                    },
                },
                {
                    id: 2,
                    layer: 'tentative',
                    mode: 'percent',
                    minutes: null,
                    percent: 50,
                    start_date: '2026-11-09',
                    end_date: '2026-12-18',
                    note: null,
                    container: {
                        kind: 'forecast',
                        id: 8,
                        name: 'Web y branding',
                        client_name: 'Hotel Mar Azul',
                    },
                },
            ],
        };
        render(<MyForecastCard data={data} />);
        const card = screen.getByTestId('my-forecast');

        expect(card?.textContent).toContain('Esta semana');
        expect(card?.textContent).toContain('La semana que viene');
        expect(card?.textContent).toContain('Hotel Mar Azul · Web y branding');
        expect(card?.textContent).toContain(
            'Previsto posible: puede no salir · 50 % de tu jornada',
        );
        expect(card?.textContent).not.toContain('App fase 2');
        expect(screen.getByTestId('stacked-columns')).toBeTruthy();
    });
});
