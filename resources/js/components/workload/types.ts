/**
 * Contrato de la vista «Carga» (SPEC §9, D-051, D-052) con App\Http\Controllers\Workload y
 * App\Domain\Workload\{WorkloadBoard,CapacityExplainer,MyWorkload}. Todas las horas en minutos
 * enteros; las fechas, locales "YYYY-MM-DD".
 */

export type WorkloadHorizonKey =
    | 'semana-actual'
    | 'semana-que-viene'
    | '4-semanas'
    | '3-meses';

/** Por qué una celda no tiene capacidad (gris). El texto lo compone el frontend. */
export type WorkloadReason = {
    type: 'holiday' | 'absence' | 'off' | 'mixed';
    /** Nombre del festivo o tipo de ausencia («Vacaciones») si es uno solo. */
    label: string | null;
};

/** Hay capacidad, pero menos que la jornada (festivos, ausencias completas o parciales). */
export type WorkloadReduced = {
    holidays: number;
    absence_days: number;
    partial_minutes: number;
    absence_label: string | null;
};

export type WorkloadTotals = { planned: number; capacity: number };

export type WorkloadCellData = WorkloadTotals & {
    reason: WorkloadReason | null;
    reduced: WorkloadReduced | null;
    /** La celda de hoy lleva el restante de tareas vencidas. */
    overdue: boolean;
};

export type WorkloadColumn = {
    /** Fecha del día o primer día de la semana: lo que va en ?celda=persona:fecha. */
    key: string;
    from: string;
    to: string;
    today: boolean;
    weekend: boolean;
};

export type WorkloadDepartment = {
    id: number | null;
    name: string | null;
    color: string | null;
};

export type WorkloadRow = {
    id: number;
    name: string;
    is_me: boolean;
    cells: WorkloadCellData[];
    total: WorkloadTotals;
};

export type WorkloadGroup = {
    department: WorkloadDepartment;
    people: WorkloadRow[];
    /** Totales del departamento por columna. */
    totals: WorkloadTotals[];
    total: WorkloadTotals;
};

export type WorkloadMatrixData = {
    columns: WorkloadColumn[];
    groups: WorkloadGroup[];
    totals: WorkloadTotals[];
    total: WorkloadTotals;
};

export type WorkloadHorizon = {
    key: WorkloadHorizonKey;
    from: string;
    to: string;
    by_week: boolean;
    today: string;
    options: WorkloadHorizonKey[];
};

/** Filtros en la URL (WorkloadFilters::toQuery), sin la celda. */
export type WorkloadQuery = {
    horizonte: WorkloadHorizonKey;
    departamento?: number[];
    persona?: number[];
    cliente?: number[];
    proyecto?: number[];
};

export type WorkloadFilterKey = Exclude<keyof WorkloadQuery, 'horizonte'>;

export type WorkloadFiltersProps = {
    query: WorkloadQuery;
    /** Admin o responsable: ve a su equipo y tiene filtros de persona y departamento. */
    sees_team: boolean;
    sees_unassigned: boolean;
};

/** Persona del alcance con su carga en el horizonte (filtro y selector de responsable). */
export type WorkloadPerson = {
    id: number;
    name: string;
    department_id: number | null;
    department: string | null;
    planned: number;
    capacity: number;
    is_me: boolean;
};

/** Miembro de un proyecto que gestiona quien mira, fuera de su alcance: solo el nombre. */
export type WorkloadExtraPerson = {
    id: number;
    name: string;
    department: string | null;
};

export type WorkloadOptions = {
    departments: { id: number; name: string; color: string }[];
    clients: { id: number; name: string }[];
    projects: { id: number; name: string; client_id: number | null }[];
};

export type WorkloadTask = {
    id: number;
    title: string;
    parent_title: string | null;
    project: { id: number; code: string; name: string; color: string };
    client: string | null;
    hour_bank: string | null;
    assignee_id: number | null;
    estimated_minutes: number | null;
    logged_minutes: number;
    remaining_minutes: number;
    start_date: string | null;
    due_date: string | null;
    overdue: boolean;
    /** TaskPolicy::update y el alcance de la vista (D-052). */
    can_edit: boolean;
    /** A quién se puede asignar; null si no puede cambiar el responsable. */
    assignee_ids: number[] | null;
};

export type WorkloadCellTask = WorkloadTask & {
    /** Minutos que pone en la celda (el día o la semana). */
    minutes: number;
};

export type WorkloadDay = WorkloadTotals & {
    date: string;
    base: number;
    reason: WorkloadReason | null;
    reduced: WorkloadReduced | null;
};

export type WorkloadCellPanelData = WorkloadCellData & {
    key: string;
    person: { id: number; name: string; department: string | null };
    from: string;
    to: string;
    days: WorkloadDay[];
    tasks: WorkloadCellTask[];
    extra_people: WorkloadExtraPerson[];
};

export type WorkloadUnplannedTask = WorkloadTask & {
    assignee: { id: number; name: string };
    missing: ('estimate' | 'due_date')[];
};

export type WorkloadUnassignedGroup = {
    department: WorkloadDepartment;
    total: number;
    remaining_minutes: number;
    tasks: WorkloadTask[];
};

/**
 * Tarea de un proyecto que gestiona quien mira y que no le llega por su equipo (D-052): de la
 * persona responsable solo se ve el nombre, nunca su carga.
 */
export type WorkloadManagedTask = WorkloadTask & {
    assignee: { id: number; name: string } | null;
    missing: ('estimate' | 'due_date')[];
};

export type WorkloadTrays = {
    /** Tareas que se pintan como mucho en cada bandeja (vencidas y más urgentes primero). */
    limit: number;
    unplanned: { total: number; tasks: WorkloadUnplannedTask[] };
    unassigned: {
        visible: boolean;
        total: number;
        groups: WorkloadUnassignedGroup[];
    };
    /** «De tus proyectos»: solo para quien gestiona proyectos (y no es admin). */
    managed: {
        visible: boolean;
        total: number;
        /** Cuántas del total están sin asignar. */
        unassigned: number;
        tasks: WorkloadManagedTask[];
    };
    extra_people: WorkloadExtraPerson[];
};

export type WorkloadPageProps = {
    horizon: WorkloadHorizon;
    filters: WorkloadFiltersProps;
    matrix: WorkloadMatrixData;
    people: WorkloadPerson[];
    options: WorkloadOptions;
    trays: WorkloadTrays;
    cell: WorkloadCellPanelData | null;
};

/** «Mi carga» en Inicio (prop diferida `workload`, App\Domain\Workload\MyWorkload). */
export type MyWorkloadWeek = WorkloadTotals & {
    key: Extract<WorkloadHorizonKey, 'semana-actual' | 'semana-que-viene'>;
    from: string;
    to: string;
    reason: WorkloadReason | null;
    reduced: WorkloadReduced | null;
};

export type MyWorkloadData = {
    weeks: MyWorkloadWeek[];
    overdue: number;
    unplanned: number;
};
