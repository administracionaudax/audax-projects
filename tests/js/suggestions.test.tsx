// @vitest-environment jsdom
import { configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

configure({ testIdAttribute: 'data-test' });

const page = vi.hoisted(() => ({
    url: '/ayuda?pestana=sugerencias',
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
    visit: vi.fn(),
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

import { SuggestionsTab } from '@/components/suggestions/suggestions-tab';
import {
    buildRoadmap,
    buildTimeline,
    countComments,
    mergeFiles,
    moveRoadmapCard,
    roadmapNeighbours,
    suggestionParams,
    summarizeReactions,
} from '@/lib/suggestions';
import type {
    SuggestionComment,
    SuggestionPost,
    SuggestionPostDetail,
    SuggestionsTabProps,
} from '@/types/weeklies';

const person = (id: number, name: string) => ({
    id,
    name,
    avatar: null,
    department_id: null,
    is_active: true,
});

function post(
    id: number,
    overrides: Partial<SuggestionPost> = {},
): SuggestionPost {
    return {
        id,
        suggestion_board_id: 1,
        suggestion_category_id: 2,
        category: { id: 2, name: 'Ideas', slug: 'ideas' },
        author: person(8, 'Pablo'),
        title: `Sugerencia ${id}`,
        slug: `sugerencia-${id}`,
        preview: 'Una idea',
        status: 'open',
        position: id,
        vote_count: 1,
        comment_count: 0,
        voted_by_me: false,
        last_activity_at: '2026-10-05T10:00:00Z',
        created_at: '2026-10-05T10:00:00Z',
        ...overrides,
    };
}

const comment = (
    id: number,
    overrides: Partial<SuggestionComment> = {},
): SuggestionComment => ({
    id,
    parent_id: null,
    author: person(8, 'Pablo'),
    body: `<p>Comentario ${id}</p>`,
    edited_at: null,
    created_at: `2026-10-05T1${id}:00:00Z`,
    attachments: [],
    reactions: [],
    replies: [],
    ...overrides,
});

function data(
    overrides: Partial<SuggestionsTabProps> = {},
): SuggestionsTabProps {
    return {
        view: 'feedback',
        filters: {
            q: null,
            board: null,
            category: null,
            order: 'trending',
            statuses: ['planned', 'building_now', 'beta', 'completed'],
            limit: 20,
        },
        boards: [
            {
                id: 1,
                name: 'Sugerencias',
                slug: 'sugerencias',
                description: null,
                position: 0,
                is_active: true,
                post_count: 3,
                categories: [
                    {
                        id: 1,
                        suggestion_board_id: 1,
                        name: 'Bugs',
                        slug: 'bugs',
                        description: 'Errores',
                        position: 0,
                        is_active: true,
                        post_count: 1,
                    },
                    {
                        id: 2,
                        suggestion_board_id: 1,
                        name: 'Ideas',
                        slug: 'ideas',
                        description: null,
                        position: 1,
                        is_active: true,
                        post_count: 2,
                    },
                ],
            },
        ],
        bugs_category_id: 1,
        feed: {
            items: [
                post(1, { voted_by_me: true, vote_count: 4, status: 'beta' }),
                post(2),
            ],
            total: 30,
            has_more: true,
        },
        roadmap: null,
        post: null,
        people: [person(8, 'Pablo'), person(9, 'Marta')],
        composer: null,
        can: { create: true, manage_boards: false, moderate: false },
        ...overrides,
    };
}

afterEach(() => {
    vi.restoreAllMocks();
    Object.values(inertia).forEach((mock) => mock.mockReset());
});

describe('piezas puras de las sugerencias', () => {
    it('la URL de la pestaña solo lleva lo que no es por defecto', () => {
        expect(
            suggestionParams({
                view: 'roadmap',
                board: null,
                category: null,
                q: null,
                order: 'trending',
                statuses: ['planned', 'building_now', 'beta', 'completed'],
                limit: null,
            }),
        ).toEqual({ pestana: 'sugerencias' });
        expect(
            suggestionParams({
                view: 'feedback',
                board: 'sugerencias',
                category: 'bugs',
                q: ' pdf ',
                order: 'top',
                statuses: [],
                limit: 40,
                composer: 'bug',
            }),
        ).toEqual({
            pestana: 'sugerencias',
            vista: 'feedback',
            tablero: 'sugerencias',
            categoria: 'bugs',
            q: 'pdf',
            orden: 'top',
            limite: '40',
            nueva: 'bug',
        });
        expect(
            suggestionParams({
                view: 'roadmap',
                board: null,
                category: null,
                q: null,
                order: 'top',
                statuses: ['completed', 'beta'],
                limit: null,
            }),
        ).toEqual({ pestana: 'sugerencias', estados: 'beta,completed' });
    });

    it('la actividad mezcla comentarios y cambios de estado por fecha, y cuenta las respuestas', () => {
        const comments = [
            comment(3),
            comment(1, { replies: [comment(2, { parent_id: 1 })] }),
        ];
        const timeline = buildTimeline(comments, [
            {
                id: 5,
                from_status: 'open',
                to_status: 'planned',
                note: 'Para noviembre',
                changed_by: person(9, 'Marta'),
                created_at: '2026-10-05T12:30:00Z',
            },
        ]);

        expect(
            timeline.map((item) =>
                item.kind === 'comment'
                    ? `c${item.comment.id}`
                    : `s${item.event.id}`,
            ),
        ).toEqual(['c1', 's5', 'c3']);
        expect(countComments(comments)).toBe(3);
    });

    it('resume las reacciones: cuántas, si una es mía y quién', () => {
        const summary = summarizeReactions(
            [
                { reaction: 'rocket', user: person(7, 'Elena') },
                { reaction: 'rocket', user: person(8, 'Pablo') },
                { reaction: 'heart', user: person(8, 'Pablo') },
            ],
            7,
        );

        expect(summary.find((item) => item.reaction === 'rocket')).toEqual({
            reaction: 'rocket',
            count: 2,
            mine: true,
            names: ['Elena', 'Pablo'],
        });
        expect(
            summary.find((item) => item.reaction === 'thumbs_up')?.count,
        ).toBe(0);
    });

    it('mueve tarjetas del roadmap y calcula las vecinas para el servidor', () => {
        const columns = buildRoadmap([
            { status: 'planned', items: [post(1), post(2)] },
            { status: 'beta', items: [post(3)] },
        ]);
        const moved = moveRoadmapCard(columns, 2, 'beta', 0);

        expect(moved.planned).toEqual([1]);
        expect(moved.beta).toEqual([2, 3]);
        expect(roadmapNeighbours(moved, 'beta', 2)).toEqual({
            before_id: 3,
            after_id: null,
        });
        expect(roadmapNeighbours(moved, 'beta', 3)).toEqual({
            before_id: null,
            after_id: 2,
        });
        expect(
            roadmapNeighbours(
                moveRoadmapCard(columns, 1, 'completed', 0),
                'completed',
                1,
            ),
        ).toEqual({ before_id: null, after_id: null });
    });

    it('junta ficheros sin repetir', () => {
        const a = new File(['a'], 'a.txt', { lastModified: 1 });
        const b = new File(['b'], 'b.txt', { lastModified: 1 });

        expect(mergeFiles([a], [a, b]).map((file) => file.name)).toEqual([
            'a.txt',
            'b.txt',
        ]);
    });
});

describe('la pestaña Sugerencias', () => {
    it('Feedback: categorías con su número, orden, votar al momento y «cargar más» (F-162 y F-163)', async () => {
        const user = userEvent.setup();
        render(<SuggestionsTab data={data()} attachmentMaxMb={50} />);

        const categories = screen.getByRole('navigation', {
            name: /Categorías/,
        });
        expect(
            within(categories)
                .getAllByRole('button')
                .map((button) => button.textContent),
        ).toEqual(['Todas las categorías3', 'BugsErrores1', 'Ideas2']);

        const feed = screen.getByTestId('suggestion-feed');
        expect(
            within(feed)
                .getByRole('link', { name: 'Sugerencia 1' })
                .getAttribute('href'),
        ).toBe('/ayuda/sugerencias/1?vista=feedback');
        expect(within(feed).getByText('Beta')).toBeTruthy();

        // Votar: el número cambia antes de que conteste el servidor.
        const vote = within(feed).getByRole('button', {
            name: 'Votar «Sugerencia 2» (1 votos)',
        });
        await user.click(vote);
        expect(vote.getAttribute('aria-pressed')).toBe('true');
        expect(vote.textContent).toBe('2');
        expect(inertia.post).toHaveBeenCalledWith(
            '/ayuda/sugerencias/2/voto',
            {},
            expect.objectContaining({ only: ['suggestions'] }),
        );

        await user.selectOptions(
            screen.getByRole('combobox', { name: 'Orden o estado' }),
            'top',
        );
        expect(inertia.get).toHaveBeenCalledWith(
            '/ayuda?pestana=sugerencias&vista=feedback&orden=top',
            {},
            expect.objectContaining({ only: ['suggestions', 'tab'] }),
        );

        expect(
            screen
                .getByRole('link', { name: 'Cargar más' })
                .getAttribute('href'),
        ).toBe('/ayuda?pestana=sugerencias&vista=feedback&limite=40');
    });

    it('«Reportar un bug» abre el formulario fijado en la categoría Bugs (F-149)', async () => {
        render(
            <SuggestionsTab
                data={data({ composer: 'bug' })}
                attachmentMaxMb={50}
            />,
        );

        const dialog = await screen.findByRole('dialog');
        expect(
            within(dialog).getByRole('heading', { name: 'Reportar un bug' }),
        ).toBeTruthy();
        expect(
            within(dialog).getByText('Se publicará en la categoría «Bugs».'),
        ).toBeTruthy();
        expect(
            within(dialog).queryByRole('combobox', { name: 'Categoría' }),
        ).toBeNull();
    });

    it('Roadmap: columnas por estado y, para quien gestiona, mover con el menú (F-168)', async () => {
        const user = userEvent.setup();
        render(
            <SuggestionsTab
                data={data({
                    view: 'roadmap',
                    feed: null,
                    roadmap: [
                        {
                            status: 'planned',
                            items: [
                                post(1, { status: 'planned' }),
                                post(2, { status: 'planned' }),
                            ],
                            total: 2,
                            has_more: false,
                        },
                        {
                            status: 'building_now',
                            items: [],
                            total: 0,
                            has_more: false,
                        },
                        {
                            status: 'beta',
                            items: [post(3, { status: 'beta' })],
                            total: 1,
                            has_more: false,
                        },
                        {
                            status: 'completed',
                            items: [],
                            total: 0,
                            has_more: false,
                        },
                    ],
                    can: { create: true, manage_boards: true, moderate: true },
                })}
                attachmentMaxMb={50}
            />,
        );

        const roadmap = screen.getByTestId('suggestion-roadmap');
        expect(
            within(roadmap)
                .getByRole('list', { name: 'Sugerencias en «Planificada»' })
                .querySelectorAll('[data-test="roadmap-card"]'),
        ).toHaveLength(2);
        expect(
            within(roadmap).getAllByText('Nada en este estado todavía.'),
        ).toHaveLength(2);
        // El número de comentarios se lee con texto oculto, no con aria-label en un span (axe aria-prohibited-attr).
        for (const count of within(roadmap).getAllByTestId(
            'roadmap-comment-count',
        )) {
            expect(count.hasAttribute('aria-label')).toBe(false);
            expect(count.querySelector('.sr-only')?.textContent).toMatch(
                /comentario/i,
            );
        }

        await user.click(
            within(roadmap).getByRole('button', {
                name: 'Mover «Sugerencia 2» a…',
            }),
        );
        await user.click(await screen.findByRole('menuitem', { name: 'Beta' }));

        expect(inertia.put).toHaveBeenCalledWith(
            '/ayuda/sugerencias/2/orden',
            { status: 'beta', before_id: null, after_id: 3 },
            expect.objectContaining({ only: ['suggestions'] }),
        );
        expect(
            screen.getByRole('button', { name: 'Gestionar categorías' }),
        ).toBeTruthy();
    });

    it('sin permiso, el roadmap no se puede reordenar', () => {
        render(
            <SuggestionsTab
                data={data({
                    view: 'roadmap',
                    feed: null,
                    roadmap: [
                        {
                            status: 'planned',
                            items: [post(1, { status: 'planned' })],
                            total: 1,
                            has_more: false,
                        },
                    ],
                })}
                attachmentMaxMb={50}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Mover «Sugerencia 1»' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Gestionar categorías' }),
        ).toBeNull();
    });

    it('el detalle: actividad con la nota oficial, respuestas anidadas, reacciones y quién ha votado (F-164 a F-167)', async () => {
        const user = userEvent.setup();
        const detail: SuggestionPostDetail = {
            ...post(1, { status: 'planned', author: person(7, 'Elena') }),
            body: '<p>El <strong>detalle</strong></p>',
            board: { id: 1, name: 'Sugerencias', slug: 'sugerencias' },
            attachments: [
                {
                    id: 5,
                    name: 'captura.png',
                    mime: 'image/png',
                    size: 1024,
                    is_image: true,
                    url: '/adjuntos/5?signature=x',
                    thumbnail_url: null,
                },
            ],
            voters: [person(8, 'Pablo')],
            comments: [
                comment(1, {
                    reactions: [
                        { reaction: 'rocket', user: person(7, 'Elena') },
                    ],
                    replies: [
                        comment(2, {
                            parent_id: 1,
                            author: person(7, 'Elena'),
                            body: '<p>Respuesta</p>',
                        }),
                    ],
                }),
            ],
            status_events: [
                {
                    id: 9,
                    from_status: 'open',
                    to_status: 'planned',
                    note: 'Para noviembre',
                    changed_by: person(9, 'Marta'),
                    created_at: '2026-10-05T10:30:00Z',
                },
            ],
            can: {
                update: true,
                delete: true,
                moderate: false,
                interact: true,
            },
        };

        render(
            <SuggestionsTab
                data={data({ post: detail, feed: null })}
                attachmentMaxMb={50}
            />,
        );

        const activity = screen.getByTestId('suggestion-activity');
        expect(
            within(activity).getByText(
                /Marta cambió el estado a «Planificada»/,
            ),
        ).toBeTruthy();
        expect(within(activity).getByText('Para noviembre')).toBeTruthy();
        expect(
            within(activity).getAllByTestId('suggestion-comment'),
        ).toHaveLength(2);
        expect(within(activity).getByText('Respuesta')).toBeTruthy();
        expect(
            within(screen.getByTestId('suggestion-voters')).getByText('Pablo'),
        ).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'captura.png' })
                .getAttribute('href'),
        ).toBe('/adjuntos/5?signature=x');
        // Es suya: la puede editar (también su respuesta) y eliminar; la moderación no es para ella.
        expect(screen.getAllByRole('button', { name: 'Editar' })).toHaveLength(
            2,
        );
        expect(screen.queryByTestId('suggestion-moderation')).toBeNull();

        const rocket = within(activity).getAllByTestId('reaction-rocket')[0];
        expect(rocket.getAttribute('aria-pressed')).toBe('true');
        await user.click(rocket);
        expect(inertia.post).toHaveBeenCalledWith(
            '/ayuda/sugerencias/comentarios/1/reaccion',
            { reaction: 'rocket' },
            expect.objectContaining({ only: ['suggestions'] }),
        );
    });

    it('en un tablero oculto no se vota ni se comenta (D-226)', () => {
        const detail: SuggestionPostDetail = {
            ...post(1),
            body: '<p>x</p>',
            board: { id: 1, name: 'Oculto', slug: 'oculto' },
            attachments: [],
            voters: [],
            comments: [],
            status_events: [],
            can: {
                update: true,
                delete: true,
                moderate: true,
                interact: false,
            },
        };

        render(
            <SuggestionsTab
                data={data({ post: detail, feed: null })}
                attachmentMaxMb={50}
            />,
        );

        expect(
            (
                screen.getByRole('button', {
                    name: /Votar/,
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        expect(screen.getByTestId('suggestion-hidden-board')).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: 'Publicar comentario' }),
        ).toBeNull();
    });

    it('quien gestiona cambia el estado con una nota oficial (F-167)', async () => {
        const user = userEvent.setup();
        const detail: SuggestionPostDetail = {
            ...post(1),
            body: '<p>x</p>',
            board: { id: 1, name: 'Sugerencias', slug: 'sugerencias' },
            attachments: [],
            voters: [],
            comments: [],
            status_events: [],
            can: { update: true, delete: true, moderate: true, interact: true },
        };

        render(
            <SuggestionsTab
                data={data({
                    post: detail,
                    feed: null,
                    can: { create: true, manage_boards: true, moderate: true },
                })}
                attachmentMaxMb={50}
            />,
        );

        const panel = screen.getByTestId('suggestion-moderation');
        await user.selectOptions(
            within(panel).getByRole('combobox', { name: 'Estado' }),
            'building_now',
        );
        await user.type(
            within(panel).getByRole('textbox', { name: /Nota oficial/ }),
            'Empezamos',
        );
        await user.click(
            within(panel).getByRole('button', { name: 'Guardar estado' }),
        );

        expect(inertia.put).toHaveBeenCalledWith(
            '/ayuda/sugerencias/1/estado',
            { status: 'building_now', note: 'Empezamos' },
            expect.objectContaining({ only: ['suggestions'] }),
        );
    });
});
