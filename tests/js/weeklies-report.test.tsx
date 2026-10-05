// @vitest-environment jsdom
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    url: '/weeklies/7',
    props: { auth: { user: { id: 1 }, can: {} } } as Record<string, unknown>,
}));

const router = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        router,
        usePage: () => page,
        Link: ({
            href,
            children,
            prefetch: _prefetch,
            preserveScroll: _preserveScroll,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            prefetch?: boolean;
            preserveScroll?: boolean;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

import { TooltipProvider } from '@/components/ui/tooltip';
import {
    deviationLabel,
    projectProgress,
} from '@/components/weeklies/client-report-card';
import {
    dispatchWeeklyAudioPlay,
    formatPlaybackRate,
    InlineAudioPlayer,
    ReportAudioPlayer,
    sectionStart,
    timeSections,
    WEEKLY_AUDIO_PLAY_EVENT,
} from '@/components/weeklies/weekly-audio';
import {
    closeBlockers,
    closeHint,
} from '@/components/weeklies/weekly-close-dialog';
import {
    weeklyClipboardText,
    WeeklyReportView,
} from '@/components/weeklies/weekly-report-view';
import type {
    WeeklyAudioSection,
    WeeklyClientUpdate,
    WeeklyProjectSnapshot,
    WeeklyShowPageProps,
} from '@/types/weeklies';

function project(
    extra: Partial<WeeklyProjectSnapshot> = {},
): WeeklyProjectSnapshot {
    return {
        project_id: 1,
        code: 'ACME-BH1',
        name: 'Bolsa web',
        billing_type: 'hour_bank',
        budget_minutes: 600,
        consumed_minutes: 660,
        expected_minutes: null,
        deviation_minutes: null,
        week_minutes: 90,
        ...extra,
    };
}

function client(
    id: number | null,
    name: string,
    extra: Partial<WeeklyClientUpdate> = {},
): WeeklyClientUpdate {
    return {
        client_id: id,
        client_name: name,
        status: 'on_track',
        executive_summary: `Resumen de ${name}.`,
        next_steps: [],
        milestones: [],
        tags: [],
        satisfaction_score: null,
        has_reports: true,
        projects: [],
        ...extra,
    };
}

function section(
    key: string,
    kind: WeeklyAudioSection['kind'],
    durationMs: number,
    clientId: number | null = null,
): WeeklyAudioSection {
    return {
        id: key.length,
        key,
        kind,
        client_id: clientId,
        position: 0,
        script: 'Guion',
        duration_ms: durationMs,
        generated_at: null,
        url: `/weeklies/7/audio/${key}?signature=x`,
    };
}

function props(
    extra: Partial<WeeklyShowPageProps> = {},
    cycle: Partial<WeeklyShowPageProps['cycle']> = {},
): WeeklyShowPageProps {
    return {
        cycle: {
            id: 7,
            number: 'W41-26',
            label: 'Semana 41 (Lun 05/10 - Vie 09/10)',
            start_date: '2026-10-05',
            end_date: '2026-10-09',
            deadline_date: '2026-10-09',
            status: 'active',
            has_report: true,
            report_state: 'done',
            report_generated_at: null,
            audio_state: null,
            has_audio: false,
            submission_count_at_generation: 2,
            closed_at: null,
            report: {
                global_summary: 'Semana intensa con dos entregas.',
                team_risks: ['Vacaciones de dos personas'],
                client_updates: [
                    client(10, 'Acme', {
                        status: 'blocked',
                        next_steps: ['Reclamar textos'],
                        milestones: [{ date: '20/10', label: 'Lanzamiento' }],
                        tags: ['Web'],
                        satisfaction_score: 62,
                        projects: [project()],
                    }),
                    client(20, 'Beta'),
                    client(null, 'General / Interno'),
                ],
            },
            report_text: '# Semana 41\n\nTexto del informe',
            report_error: null,
            report_edited_at: null,
            audio_error: null,
            has_full_audio: false,
            audio_sections: [],
            ...cycle,
        },
        team: {
            members: [],
            counts: { submitted: 2, expected: 3, exempt: 0, pending: 1 },
        },
        reports: {
            '10': [
                {
                    author: {
                        id: 3,
                        name: 'Elena Empleada',
                        avatar: null,
                        department_id: null,
                        is_active: true,
                    },
                    body: 'Hemos subido la home.',
                    submitted_at: '2026-10-07T08:00:00Z',
                    project_id: null,
                },
            ],
        },
        stale: false,
        submitted_count: 2,
        my_client_ids: [10],
        progress: { report: null, audio: null },
        close: { blockers: ['audio'], pending: 1 },
        report_request: {
            kind: 'weekly',
            route_params: { cycle: 7 },
            query: {},
        },
        can: {
            generate: true,
            edit: true,
            extendDeadline: true,
            close: true,
            delete: true,
        },
        ...extra,
    };
}

function renderView(value: WeeklyShowPageProps) {
    return render(
        <TooltipProvider>
            <WeeklyReportView {...value} />
        </TooltipProvider>,
    );
}

beforeEach(() => {
    Object.values(router).forEach((fn) => fn.mockReset());
    vi.spyOn(window.HTMLMediaElement.prototype, 'play').mockImplementation(
        function (this: HTMLMediaElement) {
            this.dispatchEvent(new Event('play'));

            return Promise.resolve();
        },
    );
    vi.spyOn(window.HTMLMediaElement.prototype, 'pause').mockImplementation(
        function (this: HTMLMediaElement) {
            this.dispatchEvent(new Event('pause'));
        },
    );
    Element.prototype.scrollIntoView = vi.fn();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('el informe de la weekly (F-072 a F-091)', () => {
    it('pinta el resumen global, los riesgos y una tarjeta por cliente con su estado, pasos, hitos, etiquetas, satisfacción y proyectos', () => {
        renderView(props());

        expect(
            screen.getByRole('heading', { name: 'Resumen global' }),
        ).toBeTruthy();
        expect(
            screen.getByText('Semana intensa con dos entregas.'),
        ).toBeTruthy();
        expect(
            within(
                document.querySelector(
                    '[data-test="weekly-risks"]',
                ) as HTMLElement,
            ).getByText('Vacaciones de dos personas'),
        ).toBeTruthy();

        const cards = document.querySelectorAll(
            '[data-test="weekly-client-card"]',
        );
        expect(cards).toHaveLength(3);
        const acme = cards[0] as HTMLElement;
        expect(
            within(acme)
                .getByRole('link', { name: 'Acme' })
                .getAttribute('href'),
        ).toBe('/clientes/10');
        expect(within(acme).getByText('Bloqueado')).toBeTruthy();
        expect(within(acme).getByText('Satisfacción 62 / 100')).toBeTruthy();
        expect(within(acme).getByText('Reclamar textos')).toBeTruthy();
        expect(within(acme).getByText('20/10')).toBeTruthy();
        expect(within(acme).getByText('Lanzamiento')).toBeTruthy();
        expect(within(acme).getByText('Web')).toBeTruthy();
        expect(within(acme).getByText('Excedido en 1:00')).toBeTruthy();
        expect(within(acme).getByText('11:00 / 10:00')).toBeTruthy();
        expect(
            within(cards[1] as HTMLElement).getByText(
                'No hay pasos definidos.',
            ),
        ).toBeTruthy();
        // «General / Interno» no lleva enlace a una ficha.
        expect(within(cards[2] as HTMLElement).queryByRole('link')).toBeNull();

        const index = screen.getAllByRole('navigation', {
            name: 'Índice de clientes',
        })[0];
        expect(
            within(index)
                .getAllByRole('link')
                .map((link) => link.textContent),
        ).toEqual(['Acme', 'Beta', 'General / Interno']);
    });

    it('«Solo mis proyectos» deja los clientes de mis proyectos y lo dice si no hay ninguno (F-080)', async () => {
        const user = userEvent.setup();
        const view = renderView(props());

        await user.click(
            screen.getByRole('button', { name: 'Solo mis proyectos' }),
        );
        expect(
            document.querySelectorAll('[data-test="weekly-client-card"]'),
        ).toHaveLength(1);
        expect(
            screen
                .getByRole('button', { name: 'Solo mis proyectos' })
                .getAttribute('aria-pressed'),
        ).toBe('true');

        view.unmount();
        renderView(props({ my_client_ids: [] }));
        await user.click(
            screen.getByRole('button', { name: 'Solo mis proyectos' }),
        );
        expect(
            screen.getByText(
                'No formas parte de ningún proyecto incluido en esta weekly.',
            ),
        ).toBeTruthy();
    });

    it('generar el texto: «Actualizar» si hay nuevos reportes; con la semana cerrada no se regenera (F-072)', async () => {
        const user = userEvent.setup();
        const view = renderView(props({ stale: true }));

        expect(
            screen.getAllByText('Hay nuevos reportes').length,
        ).toBeGreaterThan(0);
        await user.click(
            screen.getAllByRole('button', { name: 'Actualizar texto' })[0],
        );
        expect(router.post).toHaveBeenCalledWith(
            '/weeklies/7/informe',
            {},
            { preserveScroll: true },
        );

        view.unmount();
        renderView(props({}, { status: 'closed' }));
        const button = screen.getAllByRole('button', {
            name: 'Texto cerrado',
        })[0] as HTMLButtonElement;
        expect(button.disabled).toBe(true);
    });

    it('el audio se genera con el texto; sin texto, no se puede (F-084)', async () => {
        const user = userEvent.setup();
        renderView(props());

        await user.click(
            screen.getAllByRole('button', { name: 'Generar audio' })[0],
        );
        expect(router.post).toHaveBeenCalledWith(
            '/weeklies/7/audio',
            {},
            { preserveScroll: true },
        );
    });

    it('mientras se genera, avisa de que tarda y dice el paso', () => {
        renderView(
            props(
                {
                    progress: {
                        report: { step: 'clients', done: 2, total: 5 },
                        audio: null,
                    },
                },
                { report_state: 'running' },
            ),
        );

        const notice = document.querySelector(
            '[data-test="weekly-generating"]',
        ) as HTMLElement;
        expect(notice.textContent).toContain(
            'La generación puede tardar varios minutos',
        );
        expect(notice.textContent).toContain('Informe: clientes, 2 de 5');
        expect(
            (
                screen.getAllByRole('button', {
                    name: 'Regenerar texto',
                })[0] as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('cerrar exige el texto y el audio, y avisa de quien falta (F-089)', async () => {
        const user = userEvent.setup();
        const view = renderView(props());

        expect(
            document.querySelector('[data-test="weekly-close-hint"]')
                ?.textContent,
        ).toBe('Bloqueado: falta generar el audio.');
        expect(
            (
                screen.getAllByRole('button', {
                    name: 'Cerrar semana',
                })[0] as HTMLButtonElement
            ).disabled,
        ).toBe(true);

        view.unmount();
        renderView(props({ close: { blockers: [], pending: 2 } }));
        expect(
            document.querySelector('[data-test="weekly-close-hint"]')
                ?.textContent,
        ).toBe('Puedes cerrar. Aviso: 2 sin reportar (se cerrará igualmente).');
        await user.click(
            screen.getAllByRole('button', { name: 'Cerrar semana' })[0],
        );
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(
                'Puedes cerrar. Aviso: 2 sin reportar (se cerrará igualmente).',
            ),
        ).toBeTruthy();
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Sí, cerrar la semana',
            }),
        );
        expect(router.post).toHaveBeenCalledWith(
            '/weeklies/7/cerrar',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('enseña los reportes originales de un cliente (F-078)', async () => {
        const user = userEvent.setup();
        renderView(props());

        await user.click(
            screen.getByRole('button', { name: 'Ver reportes (1)' }),
        );
        const dialog = screen.getByRole('dialog', { name: 'Reportes: Acme' });
        expect(within(dialog).getByText('Hemos subido la home.')).toBeTruthy();
        expect(within(dialog).getByText(/Elena Empleada/)).toBeTruthy();
    });

    it('pantalla completa: sin las acciones ni el equipo, y Escape vuelve (F-081)', async () => {
        const user = userEvent.setup();
        renderView(props());
        const view = document.querySelector(
            '[data-test="weekly-report-view"]',
        ) as HTMLElement;

        await user.click(
            screen.getByRole('button', { name: 'Ver a pantalla completa' }),
        );
        expect(view.dataset.fullscreen).toBe('true');
        expect(screen.queryByText('Estado del equipo')).toBeNull();

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(view.dataset.fullscreen).toBe('false');
    });

    it('copia el texto final del informe (F-082)', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });
        renderView(props());

        fireEvent.click(
            screen.getAllByRole('button', { name: 'Copiar texto' })[0],
        );
        await act(async () => Promise.resolve());

        expect(writeText).toHaveBeenCalledWith(
            '# Semana 41\n\nTexto del informe',
        );
        expect(screen.getAllByText('Texto copiado').length).toBeGreaterThan(0);
    });

    it('sin informe, lo dice; la plantilla no ve las acciones de gestión', () => {
        renderView(
            props(
                {
                    can: {
                        generate: false,
                        edit: false,
                        extendDeadline: false,
                        close: false,
                        delete: false,
                    },
                },
                { report: null, report_text: null },
            ),
        );

        expect(
            screen.getByText(
                'El informe de esta semana aún no se ha generado.',
            ),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: 'Generar texto' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Cerrar semana' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Editar informe' }),
        ).toBeNull();
        expect(
            screen
                .getAllByRole('link', { name: 'Descargar HTML' })[0]
                .getAttribute('href'),
        ).toBe('/weeklies/7/informe/pdf?formato=html');
    });

    it('con audio: el reproductor principal y uno en cada cliente con su sección (F-085 y F-086)', () => {
        renderView(
            props(
                {},
                {
                    has_full_audio: true,
                    audio_sections: [
                        section('intro', 'intro', 10_000),
                        section('client-10', 'client', 30_000, 10),
                        section('outro', 'outro', 5_000),
                    ],
                },
            ),
        );

        const main = screen.getByRole('group', { name: 'Audio de la weekly' });
        expect(main.querySelector('audio')?.getAttribute('src')).toBe(
            '/weeklies/7/audio',
        );
        expect(
            screen
                .getByRole('group', { name: 'Audio de Acme' })
                .querySelector('audio')
                ?.getAttribute('src'),
        ).toBe('/weeklies/7/audio/client-10?signature=x');
        expect(
            screen
                .getAllByRole('link', { name: 'Descargar audio' })[0]
                .getAttribute('href'),
        ).toBe('/weeklies/7/audio?descargar=1');
    });
});

describe('los reproductores del informe', () => {
    it('reparte las secciones en la línea de tiempo y salta a la de un cliente', () => {
        const timed = timeSections([
            section('intro', 'intro', 10_000),
            section('client-10', 'client', 30_000, 10),
            section('outro', 'outro', 0),
        ]);

        expect(timed.map((s) => [s.start, s.duration])).toEqual([
            [0, 10],
            [10, 30],
            [40, 0],
        ]);
        // El audio completo dura 80 s (no 40): la posición va en proporción.
        expect(sectionStart(timed[1], 40, 80)).toBe(20);
        expect(sectionStart(timed[1], 0, 80)).toBe(10);
        expect(formatPlaybackRate(1.25)).toBe('x1,25');
    });

    it('la marca de un cliente lleva su nombre y lleva el audio a su parte', () => {
        render(
            <ReportAudioPlayer
                src="/weeklies/7/audio"
                playerId="main"
                labels={{ 'client-10': 'Acme' }}
                sections={[
                    section('intro', 'intro', 10_000),
                    section('client-10', 'client', 30_000, 10),
                ]}
            />,
        );
        const audio = document.querySelector('audio') as HTMLAudioElement;
        Object.defineProperty(audio, 'duration', {
            value: 80,
            configurable: true,
        });
        fireEvent.loadedMetadata(audio);

        fireEvent.click(
            screen.getByRole('button', { name: 'Ir al audio de Acme' }),
        );

        expect(audio.currentTime).toBe(20);
    });

    it('solo suena uno a la vez: al empezar otro, el anterior se para', async () => {
        render(
            <>
                <InlineAudioPlayer src="/a.mp3" name="Acme" playerId="a" />
                <InlineAudioPlayer src="/b.mp3" name="Beta" playerId="b" />
            </>,
        );
        const [first] = Array.from(document.querySelectorAll('audio'));
        const pause = vi.spyOn(first, 'pause');

        fireEvent.click(
            screen.getByRole('button', { name: 'Reproducir el audio de Acme' }),
        );
        await act(async () => Promise.resolve());
        expect(
            screen.getByRole('button', { name: 'Pausar el audio de Acme' }),
        ).toBeTruthy();

        act(() => {
            dispatchWeeklyAudioPlay('b');
        });
        expect(pause).toHaveBeenCalled();

        const listener = vi.fn();
        window.addEventListener(WEEKLY_AUDIO_PLAY_EVENT, listener);
        fireEvent.click(
            screen.getByRole('button', { name: 'Reproducir el audio de Beta' }),
        );
        await act(async () => Promise.resolve());
        expect(listener).toHaveBeenCalled();
        window.removeEventListener(WEEKLY_AUDIO_PLAY_EVENT, listener);
    });

    it('la barra se maneja con el teclado', () => {
        render(<InlineAudioPlayer src="/a.mp3" name="Acme" />);
        const audio = document.querySelector('audio') as HTMLAudioElement;
        Object.defineProperty(audio, 'duration', {
            value: 60,
            configurable: true,
        });
        fireEvent.loadedMetadata(audio);
        const slider = screen.getByRole('slider', {
            name: 'Posición del audio',
        });

        fireEvent.keyDown(slider, { key: 'ArrowRight' });
        expect(audio.currentTime).toBe(5);
        fireEvent.keyDown(slider, { key: 'End' });
        expect(audio.currentTime).toBe(60);
        expect(slider.getAttribute('aria-valuetext')).toBe('1:00 de 1:00');
    });
});

describe('piezas del informe', () => {
    it('el cierre: qué falta y el aviso de quien no ha enviado', () => {
        expect(
            closeBlockers({
                status: 'active',
                has_report: false,
                has_audio: false,
            }),
        ).toEqual(['report', 'audio']);
        expect(closeHint(['report', 'audio'], 3)).toBe(
            'Bloqueado: falta generar el texto y generar el audio.',
        );
        expect(closeHint([], 0)).toBe('Lista para cerrar.');
        expect(closeHint(['not_active'], 0)).toBe(
            'Esta semana ya está cerrada.',
        );
    });

    it('la desviación de un fee frente a lo esperado', () => {
        expect(
            deviationLabel(
                project({ billing_type: 'monthly_fee', deviation_minutes: 90 }),
            ),
        ).toBe('+1:30 sobre lo esperado');
        expect(deviationLabel(project({ deviation_minutes: -45 }))).toBe(
            '-0:45 por debajo de lo esperado',
        );
        expect(deviationLabel(project({ deviation_minutes: 0 }))).toBe(
            'En línea con lo esperado',
        );
        expect(deviationLabel(project({ deviation_minutes: null }))).toBeNull();
        expect(projectProgress(project({ budget_minutes: null }))).toBeNull();
        expect(projectProgress(project())).toBeCloseTo(110);
    });

    it('sin texto final, el texto para copiar sale del informe', () => {
        const text = weeklyClipboardText(
            props({}, { report_text: null }).cycle,
        );

        expect(text).toContain('# Weekly W41-26 - Semana 41');
        expect(text).toContain('## Acme');
        expect(text).toContain('Estado: Bloqueado');
        expect(text).toContain('- Vacaciones de dos personas');
    });
});
