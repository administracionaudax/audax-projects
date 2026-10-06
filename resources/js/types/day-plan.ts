/**
 * Plan del día (docs/PLAN-CARGAS.md, Nivel 1; D-250 a D-256). Contrato con
 * App\Domain\DayPlan\DayPlanPresenter, MyDay, TeamDay, TeamWeek y HomeDayPlanCard.
 *
 * Las cifras (horas previstas e imputadas, temporizador) y los comentarios solo llegan a la propia
 * persona, a su responsable y a los admins (D-251): para el resto llegan a null.
 */

export type DayPlanStatus = 'pending' | 'done' | 'not_done' | 'carried';

export type DayPlanComment = {
    id: number;
    body: string;
    user: { id: number; name: string };
    /** Instante ISO en UTC. */
    created_at: string | null;
    can_delete: boolean;
};

export type DayPlanLine = {
    id: number;
    user_id: number;
    /** Fecha local YYYY-MM-DD. */
    date: string;
    position: number;
    text: string;
    status: DayPlanStatus;
    not_done_reason: string | null;
    /** «↻ ×N»: veces que se ha pasado de un día a otro. */
    carry_count: number;
    carried_from_date: string | null;
    origin: 'manual' | 'task' | 'carried';
    client: { id: number; name: string } | null;
    project: { id: number; code: string; name: string; color: string } | null;
    task: {
        id: number;
        title: string;
        project_id: number;
        is_completed: boolean;
    } | null;
    created_at: string | null;
    /** Creada el mismo día después de la hora límite («añadida a las 12:40»). */
    added_late: boolean;
    planned_minutes: number | null;
    logged_minutes: number | null;
    /** Con el temporizador en marcha. */
    running: boolean;
    comments: DayPlanComment[] | null;
};

export type DayPlanPendingLine = {
    id: number;
    date: string;
    text: string;
    carry_count: number;
    client: string | null;
    project: string | null;
};

export type MyDayData = {
    date: string;
    today: string;
    horizon_end: string;
    /** Hora límite «HH:MM» (D-252). */
    deadline: string;
    can: { write: boolean; close: boolean };
    plan: { note: string | null; published_at: string | null };
    items: DayPlanLine[];
    summary: {
        capacity_minutes: number;
        planned_minutes: number;
        logged_minutes: number;
        done: number;
        total: number;
        /** Líneas hechas con tarea y horas previstas y sin horas («Imputar lo previsto», D-254). */
        loggable: number;
    };
    pending: DayPlanPendingLine[];
    running_item_id: number | null;
};

export type DayPlanTargetClient = { id: number; name: string };

export type DayPlanTargetProject = {
    id: number;
    code: string;
    name: string;
    color: string;
    client_id: number | null;
    client_name: string | null;
    is_mine: boolean;
    is_internal: boolean;
};

export type DayPlanTargets = {
    clients: DayPlanTargetClient[];
    projects: DayPlanTargetProject[];
};

export type MyDayPageProps = {
    day: MyDayData;
    /** Prop diferida: llega tras pintar la página. */
    targets?: DayPlanTargets;
};

export type DayPlanSuggestedTask = {
    id: number;
    title: string;
    reason: 'overdue' | 'due' | 'logged' | 'in_progress';
    due_date: string | null;
    project: { id: number; code: string; name: string; color: string };
};

/** Tarjeta «Mi día» de Inicio (prop diferida `day_plan`; null = sin tarjeta). */
export type HomeDayPlanCard = {
    date: string;
    deadline: string;
    items: DayPlanLine[];
    done: number;
    total: number;
    pending: number;
    running_item_id: number | null;
    can_write: boolean;
};

/** Estado del día de una persona en «Equipo hoy» y en la semana. */
export type DayPlanDayState =
    | 'plan'
    | 'no_plan'
    | 'not_yet'
    | 'away'
    | 'holiday'
    | 'off'
    | 'future';

export type DayPlanPerson = {
    id: number;
    name: string;
    avatar: string | null;
    department: { id: number; name: string } | null;
};

export type TeamDayRow = {
    user: DayPlanPerson;
    state: DayPlanDayState;
    /** Motivo de la ausencia, solo para quien puede verlo (D-088); festivo, para todos. */
    reason: string | null;
    note: string | null;
    items: DayPlanLine[];
    /** Cifras: solo la persona, su responsable y los admins (D-251). */
    figures: {
        published_at: string | null;
        capacity_minutes: number;
        planned_minutes: number;
        logged_minutes: number;
        done: number;
        total: number;
        carried: number;
        running: {
            item_id: number | null;
            text: string;
            started_at: string;
        } | null;
    } | null;
    can_comment: boolean;
    can_remind: boolean;
    reminded: boolean;
};

export type TeamDayPageProps = {
    date: string;
    today: string;
    deadline: string;
    past_deadline: boolean;
    department: number | 'all';
    departments: { id: number; name: string }[];
    rows: TeamDayRow[];
    summary: {
        people: number;
        with_plan: number;
        without_plan: number;
        away: number;
        figures: { done: number; total: number; carried: number } | null;
    };
};

export type TeamWeekCell = {
    date: string;
    state: DayPlanDayState;
    reason: string | null;
    items: DayPlanLine[];
    figures: { done: number; total: number; carried: number } | null;
};

export type TeamWeekRow = {
    user: DayPlanPerson;
    days: TeamWeekCell[];
    figures: { done: number; total: number; days_with_plan: number } | null;
};

export type TeamWeekPageProps = {
    week: string;
    previous_week: string;
    next_week: string;
    current_week: string;
    days: string[];
    today: string;
    department: number | 'all';
    departments: { id: number; name: string }[];
    rows: TeamWeekRow[];
};
