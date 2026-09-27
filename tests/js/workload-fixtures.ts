/**
 * Datos de ejemplo de la vista Carga para los tests de Vitest (mismo escenario que
 * tests/Feature/Workload/Concerns/BuildsWorkloadScenario.php: la semana del 12/10/2026, con el
 * lunes festivo y Elena sobrecargada el 13 y el 14).
 */
import type {
    WorkloadCellData,
    WorkloadCellPanelData,
    WorkloadCellTask,
    WorkloadColumn,
    WorkloadPageProps,
    WorkloadPerson,
    WorkloadRow,
} from '@/components/workload/types';

export const DAYS = [
    '2026-10-12',
    '2026-10-13',
    '2026-10-14',
    '2026-10-15',
    '2026-10-16',
];

export const COLUMNS: WorkloadColumn[] = DAYS.map((day) => ({
    key: day,
    from: day,
    to: day,
    today: false,
    weekend: false,
}));

const HOLIDAY: WorkloadCellData = {
    planned: 0,
    capacity: 0,
    reason: { type: 'holiday', label: 'Fiesta Nacional de España' },
    reduced: null,
    overdue: false,
};

function cell(planned: number, capacity = 480): WorkloadCellData {
    return { planned, capacity, reason: null, reduced: null, overdue: false };
}

export const ELENA: WorkloadRow = {
    id: 3,
    name: 'Elena Empleada',
    is_me: false,
    cells: [
        HOLIDAY,
        { ...cell(600), overdue: true },
        cell(600),
        cell(300),
        cell(300),
    ],
    total: { planned: 1800, capacity: 1920 },
};

export const LUCIA: WorkloadRow = {
    id: 4,
    name: 'Lucía Martín',
    is_me: false,
    cells: [
        HOLIDAY,
        {
            planned: 0,
            capacity: 0,
            reason: { type: 'absence', label: 'Vacaciones' },
            reduced: null,
            overdue: false,
        },
        {
            planned: 0,
            capacity: 0,
            reason: { type: 'absence', label: 'Vacaciones' },
            reduced: null,
            overdue: false,
        },
        cell(240),
        cell(240),
    ],
    total: { planned: 480, capacity: 960 },
};

export const RAUL: WorkloadRow = {
    id: 2,
    name: 'Raúl Responsable',
    is_me: true,
    cells: [HOLIDAY, cell(0), cell(0), cell(0), cell(0)],
    total: { planned: 0, capacity: 1920 },
};

export const PABLO: WorkloadRow = {
    id: 6,
    name: 'Pablo Ruiz',
    is_me: false,
    cells: [
        HOLIDAY,
        cell(240),
        cell(240),
        {
            planned: 240,
            capacity: 240,
            reason: null,
            reduced: {
                holidays: 0,
                absence_days: 0,
                partial_minutes: 240,
                absence_label: 'Formación externa',
            },
            overdue: false,
        },
        cell(240),
    ],
    total: { planned: 960, capacity: 1680 },
};

export const PEOPLE: WorkloadPerson[] = [
    {
        id: 3,
        name: 'Elena Empleada',
        department_id: 1,
        department: 'Diseño',
        planned: 1800,
        capacity: 1920,
        is_me: false,
    },
    {
        id: 4,
        name: 'Lucía Martín',
        department_id: 1,
        department: 'Diseño',
        planned: 480,
        capacity: 960,
        is_me: false,
    },
    {
        id: 2,
        name: 'Raúl Responsable',
        department_id: 1,
        department: 'Diseño',
        planned: 0,
        capacity: 1920,
        is_me: true,
    },
];

export function task(
    overrides: Partial<WorkloadCellTask> = {},
): WorkloadCellTask {
    return {
        id: 41,
        title: 'Pantalla de reservas',
        parent_title: null,
        project: { id: 7, code: 'APP', name: 'App de citas', color: '#179FA5' },
        client: 'Beta',
        hour_bank: null,
        assignee_id: 3,
        estimated_minutes: 600,
        logged_minutes: 0,
        remaining_minutes: 600,
        start_date: '2026-10-13',
        due_date: '2026-10-14',
        overdue: false,
        can_edit: true,
        assignee_ids: [3, 4, 2],
        minutes: 300,
        ...overrides,
    };
}

export function panel(
    overrides: Partial<WorkloadCellPanelData> = {},
): WorkloadCellPanelData {
    return {
        key: '3:2026-10-13',
        person: { id: 3, name: 'Elena Empleada', department: 'Diseño' },
        from: '2026-10-13',
        to: '2026-10-13',
        planned: 600,
        capacity: 480,
        reason: null,
        reduced: null,
        overdue: false,
        days: [
            {
                date: '2026-10-13',
                planned: 600,
                capacity: 480,
                base: 480,
                reason: null,
                reduced: null,
            },
        ],
        tasks: [
            task(),
            task({
                id: 40,
                title: 'Maquetar la home',
                project: {
                    id: 8,
                    code: 'WEB',
                    name: 'Web corporativa',
                    color: '#0171FF',
                },
                client: 'Acme',
                hour_bank: 'Bolsa 2026',
                estimated_minutes: 1200,
                remaining_minutes: 1200,
                start_date: '2026-10-13',
                due_date: '2026-10-16',
            }),
        ],
        extra_people: [],
        ...overrides,
    };
}

export function pageProps(
    overrides: Partial<WorkloadPageProps> = {},
): WorkloadPageProps {
    return {
        horizon: {
            key: 'semana-que-viene',
            from: '2026-10-12',
            to: '2026-10-18',
            by_week: false,
            today: '2026-10-06',
            options: [
                'semana-actual',
                'semana-que-viene',
                '4-semanas',
                '3-meses',
            ],
        },
        filters: {
            query: { horizonte: 'semana-que-viene' },
            sees_team: true,
            sees_unassigned: true,
        },
        matrix: {
            columns: COLUMNS,
            groups: [
                {
                    department: { id: 2, name: 'Desarrollo', color: '#179FA5' },
                    people: [PABLO],
                    totals: PABLO.cells.map(({ planned, capacity }) => ({
                        planned,
                        capacity,
                    })),
                    total: PABLO.total,
                },
                {
                    department: { id: 1, name: 'Diseño', color: '#0171FF' },
                    people: [ELENA, LUCIA, RAUL],
                    totals: [
                        { planned: 0, capacity: 0 },
                        { planned: 600, capacity: 960 },
                        { planned: 600, capacity: 960 },
                        { planned: 540, capacity: 1440 },
                        { planned: 540, capacity: 1440 },
                    ],
                    total: { planned: 2280, capacity: 4800 },
                },
            ],
            totals: [
                { planned: 0, capacity: 0 },
                { planned: 840, capacity: 1440 },
                { planned: 840, capacity: 1440 },
                { planned: 780, capacity: 1680 },
                { planned: 780, capacity: 1920 },
            ],
            total: { planned: 3240, capacity: 6480 },
        },
        people: PEOPLE,
        options: {
            departments: [
                { id: 2, name: 'Desarrollo', color: '#179FA5' },
                { id: 1, name: 'Diseño', color: '#0171FF' },
            ],
            clients: [
                { id: 1, name: 'Acme' },
                { id: 2, name: 'Beta' },
            ],
            projects: [
                { id: 7, name: 'APP · App de citas', client_id: 2 },
                { id: 8, name: 'WEB · Web corporativa', client_id: 1 },
            ],
        },
        trays: {
            limit: 100,
            unplanned: {
                total: 1,
                tasks: [
                    {
                        ...task({
                            id: 50,
                            title: 'Textos legales',
                            estimated_minutes: null,
                            remaining_minutes: 0,
                            start_date: null,
                            due_date: '2026-10-15',
                            assignee_ids: null,
                        }),
                        assignee: { id: 3, name: 'Elena Empleada' },
                        missing: ['estimate'],
                    },
                ],
            },
            unassigned: {
                visible: true,
                total: 1,
                groups: [
                    {
                        department: { id: 1, name: 'Diseño', color: '#0171FF' },
                        total: 1,
                        remaining_minutes: 240,
                        tasks: [
                            task({
                                id: 60,
                                title: 'Banner de campaña',
                                assignee_id: null,
                                estimated_minutes: 240,
                                remaining_minutes: 240,
                                start_date: null,
                                due_date: '2026-10-01',
                                overdue: true,
                            }),
                        ],
                    },
                ],
            },
            managed: { visible: false, total: 0, unassigned: 0, tasks: [] },
            extra_people: [],
        },
        cell: null,
        ...overrides,
    };
}

/** Elemento con `data-test` (la convención del proyecto, también en Playwright). */
export function byTest(
    id: string,
    container: ParentNode = document,
): HTMLElement {
    const element = container.querySelector<HTMLElement>(`[data-test="${id}"]`);

    if (!element) {
        throw new Error(`No hay ningún [data-test="${id}"]`);
    }

    return element;
}

export function allByTest(
    id: string,
    container: ParentNode = document,
): HTMLElement[] {
    return [...container.querySelectorAll<HTMLElement>(`[data-test="${id}"]`)];
}
