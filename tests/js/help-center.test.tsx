// @vitest-environment jsdom
import { configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// La app marca los elementos con data-test.
configure({ testIdAttribute: 'data-test' });

const page = vi.hoisted(() => ({
    url: '/ayuda',
    props: {
        auth: {
            user: { id: 7, name: 'Elena', roles: ['employee'] },
            can: { useWeeklies: true },
        },
        config: { modules: { help: true, suggestions: true } },
    } as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
        router: { ...inertia, on: () => () => {} },
        Link: ({
            href,
            children,
            preserveScroll: _preserveScroll,
            preserveState: _preserveState,
            only: _only,
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

import {
    filterUpdates,
    formatMegabytes,
    monthLabel,
    moveItem,
    searchFaqs,
} from '@/lib/help-center';
import { uploadVideoInChunks } from '@/lib/video-upload';
import Help from '@/pages/help/index';
import type {
    HelpFaqSection,
    HelpPageProps,
    HelpTutorial,
    HelpUpdateEntry,
} from '@/types/weeklies';

const release: HelpUpdateEntry = {
    key: 'release:3',
    kind: 'release',
    id: 3,
    published_on: '2026-10-05',
    title: 'Notas de lanzamiento V.1.10.1',
    subtitle: 'Fichajes más rápidos',
    version: 'V.1.10.1',
    status: 'new',
    release: {
        id: 3,
        major_version: 1,
        month_number: 10,
        week_of_month: 1,
        version: 'V.1.10.1',
        summary: 'Fichajes más rápidos',
        is_hidden: false,
        changes: [
            { id: 1, description: 'Temporizador en la cabecera', position: 1 },
            { id: 2, description: 'Exportar a Excel', position: 2 },
        ],
    },
    manual: null,
    likes: [
        { id: 8, name: 'Marta', avatar: null },
        { id: 9, name: 'Pablo', avatar: null },
    ],
    liked_by_me: false,
};

const manual: HelpUpdateEntry = {
    key: 'update:1',
    kind: 'manual',
    id: 1,
    published_on: '2026-09-20',
    title: 'Nuevo panel de facturas',
    subtitle: 'Resumen',
    version: null,
    status: 'previous',
    release: null,
    manual: {
        id: 1,
        published_on: '2026-09-20',
        title: 'Nuevo panel de facturas',
        subtitle: 'Resumen',
        body: '<p>Todo sobre <strong>facturación</strong></p>',
    },
    likes: [],
    liked_by_me: true,
};

const faqs: HelpFaqSection[] = [
    {
        id: 1,
        name: 'General',
        position: 1,
        faqs: [
            {
                id: 10,
                help_faq_section_id: 1,
                question: '¿Cómo ficho?',
                answer: '<p>Con el temporizador</p>',
                position: 1,
            },
        ],
    },
    {
        id: 2,
        name: 'Ausencias',
        position: 2,
        faqs: [
            {
                id: 11,
                help_faq_section_id: 2,
                question: '¿Vacaciones?',
                answer: '<p>Pídelas en Ausencias</p>',
                position: 1,
            },
        ],
    },
];

const tutorial: HelpTutorial = {
    id: 4,
    title: 'Cómo imputar',
    description: 'Paso a paso',
    help_release_id: 3,
    version: 'V.1.10.1',
    position: 1,
    video_url: '/ayuda/tutoriales/4/video?signature=x',
    video_name: 'imputar.mp4',
    video_size: 1024,
    video_mime: 'video/mp4',
    created_at: '2026-10-01T10:00:00Z',
    updated_at: '2026-10-01T10:00:00Z',
};

function props(overrides: Partial<HelpPageProps> = {}): HelpPageProps {
    return {
        tab: 'general',
        can: { manage: false, suggestions: true },
        settings: {
            support_url: 'https://soporte.example.com',
            manual: {
                name: 'Manual.pdf',
                size: 2 * 1024 * 1024,
                url: '/ayuda/manual?signature=x',
            },
        },
        updates: [release, manual],
        releases: null,
        tutorials: null,
        faq_sections: null,
        suggestions: null,
        upload: {
            video_max_bytes: 200 * 1024 * 1024,
            video_chunk_bytes: 8 * 1024 * 1024,
            attachment_max_mb: 50,
        },
        ...overrides,
    };
}

afterEach(() => {
    vi.restoreAllMocks();
    Object.values(inertia).forEach((mock) => mock.mockReset());
});

describe('piezas puras del centro de ayuda', () => {
    it('filtra las novedades por texto (también en los cambios y el contenido), tipo y estado (F-152)', () => {
        const all = [release, manual];

        expect(
            filterUpdates(all, { q: 'EXCEL', kind: 'all', status: 'all' }),
        ).toEqual([release]);
        expect(
            filterUpdates(all, {
                q: 'facturacion',
                kind: 'all',
                status: 'all',
            }),
        ).toEqual([manual]);
        expect(
            filterUpdates(all, { q: 'v.1.10', kind: 'all', status: 'all' }),
        ).toEqual([release]);
        expect(
            filterUpdates(all, { q: '', kind: 'manual', status: 'all' }),
        ).toEqual([manual]);
        expect(
            filterUpdates(all, { q: '', kind: 'all', status: 'new' }),
        ).toEqual([release]);
    });

    it('busca en las preguntas y las respuestas y deja solo las secciones con resultados (F-156)', () => {
        expect(searchFaqs(faqs, '')).toBe(faqs);
        expect(
            searchFaqs(faqs, 'ausencias').map((section) => section.name),
        ).toEqual(['Ausencias']);
        expect(
            searchFaqs(faqs, 'temporizador')[0].faqs.map((faq) => faq.id),
        ).toEqual([10]);
        expect(searchFaqs(faqs, 'nada de nada')).toEqual([]);
    });

    it('meses, tamaños y mover en una lista', () => {
        expect(monthLabel(10)).toBe('Octubre');
        expect(formatMegabytes(150 * 1024 * 1024)).toBe('150 MB');
        expect(formatMegabytes(1.5 * 1024 * 1024)).toBe('1,5 MB');
        expect(moveItem([1, 2, 3], 2, 0)).toEqual([3, 1, 2]);
        expect(moveItem([1, 2, 3], 0, 5)).toEqual([1, 2, 3]);
    });
});

describe('subida del vídeo por trozos (D-207)', () => {
    it('reserva la subida, manda los trozos en orden con su posición y reintenta un fallo de red', async () => {
        const sent: { url: string; offset: string | null; size: number }[] = [];
        let failures = 1;

        class FakeXhr {
            upload = {
                onprogress: null as
                    | ((event: { loaded: number }) => void)
                    | null,
            };
            status = 0;
            response: unknown = null;
            responseType = '';
            onload: (() => void) | null = null;
            onerror: (() => void) | null = null;
            onabort: (() => void) | null = null;
            private url = '';
            open(_method: string, url: string) {
                this.url = url;
            }
            setRequestHeader() {}
            abort() {}
            send(body: FormData | string) {
                if (typeof body === 'string') {
                    this.status = 201;
                    this.response = {
                        upload: 'abc',
                        chunk_size: 4,
                        received: 0,
                    };
                    sent.push({ url: this.url, offset: null, size: 0 });
                    queueMicrotask(() => this.onload?.());

                    return;
                }

                const offset = Number(body.get('offset'));
                const chunk = body.get('chunk') as Blob;

                if (offset === 4 && failures > 0) {
                    failures--;
                    queueMicrotask(() => this.onerror?.());

                    return;
                }

                sent.push({
                    url: this.url,
                    offset: String(offset),
                    size: chunk.size,
                });
                this.upload.onprogress?.({ loaded: chunk.size });
                this.status = 200;
                this.response = { received: offset + chunk.size };
                queueMicrotask(() => this.onload?.());
            }
        }

        vi.stubGlobal('XMLHttpRequest', FakeXhr);
        const progress: number[] = [];
        const id = await uploadVideoInChunks(
            new File(['0123456789'], 'v.mp4', { type: 'video/mp4' }),
            {
                onProgress: (value) => progress.push(value.percentage),
            },
        );

        expect(id).toBe('abc');
        expect(sent.map((item) => [item.offset, item.size])).toEqual([
            [null, 0],
            ['0', 4],
            ['4', 4],
            ['8', 2],
        ]);
        expect(sent[1].url).toContain('/ayuda/tutoriales/subidas/abc');
        expect(progress.at(-1)).toBe(100);
        vi.unstubAllGlobals();
    });
});

describe('la página /ayuda', () => {
    it('General: manual, soporte, novedades con estados, «me gusta» y quién lo ha dado (F-150 a F-153 y F-157)', async () => {
        const user = userEvent.setup();
        render(<Help {...props()} />);

        expect(
            screen
                .getByRole('link', { name: /Manual en PDF \(2 MB\)/ })
                .getAttribute('href'),
        ).toBe('/ayuda/manual?signature=x');
        expect(
            screen
                .getByRole('link', { name: /Contactar con soporte/ })
                .getAttribute('href'),
        ).toBe('https://soporte.example.com');

        const list = screen.getByTestId('help-updates');
        expect(
            within(list)
                .getAllByRole('heading')
                .map((heading) => heading.textContent),
        ).toEqual(['Notas de lanzamiento V.1.10.1', 'Nuevo panel de facturas']);
        expect(within(list).getByText('Nueva')).toBeTruthy();
        expect(within(list).getByText('2 cambios registrados')).toBeTruthy();
        expect(
            within(list).getByText('Les gusta a: Marta, Pablo'),
        ).toBeTruthy();

        // Sin permiso, nada de gestión.
        expect(
            screen.queryByRole('button', { name: 'Añadir novedad' }),
        ).toBeNull();

        await user.click(
            screen.getByRole('button', {
                name: 'Me gusta «Notas de lanzamiento V.1.10.1» (2)',
            }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/ayuda/me-gusta',
            { kind: 'release', id: 3 },
            expect.objectContaining({ only: ['updates'] }),
        );

        // El buscador filtra al momento.
        await user.type(
            screen.getByRole('searchbox', { name: 'Buscar en las novedades' }),
            'facturación',
        );
        expect(
            within(screen.getByTestId('help-updates')).getAllByRole('heading'),
        ).toHaveLength(1);

        // El detalle con el contenido.
        await user.click(
            screen.getByRole('button', {
                name: 'Ver el detalle de «Nuevo panel de facturas»',
            }),
        );
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText('facturación')).toBeTruthy();
    });

    it('quien gestiona ve las acciones y «Reportar un bug» lleva a sugerencias en modo bug (F-149 y F-158)', () => {
        render(
            <Help
                {...props({
                    can: { manage: true, suggestions: true },
                    releases: [],
                })}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Añadir novedad' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Añadir versión' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Editar manual y soporte' }),
        ).toBeTruthy();
        expect(screen.getByTestId('help-report-bug').getAttribute('href')).toBe(
            '/ayuda?pestana=sugerencias&vista=feedback&nueva=bug',
        );

        const tabs = screen.getByRole('navigation', {
            name: 'Secciones del centro de ayuda',
        });
        expect(
            within(tabs)
                .getAllByRole('link')
                .map((link) => link.textContent),
        ).toEqual([
            'General',
            'Tutoriales',
            'Preguntas frecuentes',
            'Sugerencias',
        ]);
    });

    it('sin el módulo de sugerencias no hay pestaña ni «Reportar un bug»', () => {
        render(
            <Help {...props({ can: { manage: false, suggestions: false } })} />,
        );

        expect(screen.queryByRole('link', { name: 'Sugerencias' })).toBeNull();
        expect(screen.queryByTestId('help-report-bug')).toBeNull();
    });

    it('Tutoriales: la lista con su versión y el vídeo en un diálogo (F-155)', async () => {
        const user = userEvent.setup();
        render(
            <Help
                {...props({
                    tab: 'tutoriales',
                    updates: null,
                    tutorials: [tutorial],
                    releases: [],
                })}
            />,
        );

        expect(
            within(screen.getByTestId('help-tutorials')).getByText('V.1.10.1'),
        ).toBeTruthy();
        await user.click(
            screen.getByRole('button', { name: 'Ver el vídeo «Cómo imputar»' }),
        );

        const video = await screen.findByTestId('help-video');
        expect(video.getAttribute('src')).toBe(
            '/ayuda/tutoriales/4/video?signature=x',
        );
        expect(video.getAttribute('preload')).toBe('metadata');
    });

    it('Tutoriales: quien gestiona no puede elegir un vídeo de más de 200 MB', async () => {
        const user = userEvent.setup();
        render(
            <Help
                {...props({
                    tab: 'tutoriales',
                    updates: null,
                    tutorials: [],
                    releases: [
                        {
                            id: 3,
                            version: 'V.1.10.1',
                            major_version: 1,
                            month_number: 10,
                            week_of_month: 1,
                            is_hidden: false,
                        },
                    ],
                    can: { manage: true, suggestions: true },
                    upload: {
                        video_max_bytes: 10,
                        video_chunk_bytes: 4,
                        attachment_max_mb: 50,
                    },
                })}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Añadir tutorial' }),
        );
        const dialog = await screen.findByRole('dialog');
        await user.upload(
            within(dialog).getByLabelText('Vídeo'),
            new File(['01234567890123'], 'v.mp4', { type: 'video/mp4' }),
        );

        expect(
            within(dialog).getByText(
                'El vídeo supera el límite de 200 MB permitido para tutoriales.',
            ),
        ).toBeTruthy();
        expect(
            (
                within(dialog).getByLabelText(
                    'Versión del tutorial',
                ) as HTMLInputElement
            ).value,
        ).toBe('3');
    });

    it('Preguntas frecuentes: secciones, desplegar la respuesta y buscar en todas (F-156)', async () => {
        const user = userEvent.setup();
        render(
            <Help
                {...props({
                    tab: 'preguntas',
                    updates: null,
                    faq_sections: faqs,
                })}
            />,
        );

        const question = screen.getByRole('button', { name: '¿Cómo ficho?' });
        expect(question.getAttribute('aria-expanded')).toBe('false');
        await user.click(question);
        expect(question.getAttribute('aria-expanded')).toBe('true');
        expect(screen.getByText('Con el temporizador')).toBeTruthy();

        await user.click(screen.getByRole('button', { name: /Ausencias/ }));
        expect(
            screen.getByRole('button', { name: '¿Vacaciones?' }),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: '¿Cómo ficho?' }),
        ).toBeNull();

        await user.type(
            screen.getByRole('searchbox', {
                name: 'Buscar en las preguntas frecuentes',
            }),
            'temporizador',
        );
        expect(
            screen.getByRole('button', { name: '¿Cómo ficho?' }),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: '¿Vacaciones?' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Gestionar secciones' }),
        ).toBeNull();
    });
});
