/**
 * Plantillas de proyecto (D-058) y tareas recurrentes (D-059), Fase 4. Coincide con
 * App\Http\Controllers\Templates\*, App\Http\Controllers\Recurring\*, App\Domain\Templates\* y
 * App\Domain\Recurring\*.
 */
import type { ProjectsPaginated } from './projects';
import type { TaskPriority } from './domain';

/** Una tarea de la estructura de una plantilla (ProjectTemplateService::normalize). */
export interface TemplateTask {
    ref: string;
    parent_ref: string | null;
    title: string;
    task_type_id: number | null;
    priority: TaskPriority;
    estimated_minutes: number | null;
    is_milestone: boolean;
    /** Días naturales desde el inicio (día 0). */
    start_offset_days: number;
    /** Días naturales; la entrega es inicio + duración − 1. Un hito, 1. */
    duration_days: number;
}

/** Dependencia fin-inicio: `to_ref` depende de `from_ref`. */
export interface TemplateDependency {
    from_ref: string;
    to_ref: string;
}

export interface TemplateStructure {
    tasks: TemplateTask[];
    dependencies: TemplateDependency[];
}

/** ProjectTemplateService::stats(). */
export interface TemplateStats {
    tasks: number;
    subtasks: number;
    milestones: number;
    dependencies: number;
    /** Del día 0 a la entrega más tardía. */
    duration_days: number;
}

/** Plantilla activa para los selectores (TemplateItems::options). */
export interface TemplateOption {
    id: number;
    name: string;
    description: string | null;
    stats: TemplateStats;
}

/** Fila de /admin/plantillas (TemplateItems::rows). */
export interface TemplateRow extends TemplateOption {
    is_active: boolean;
    /** Instantes ISO en UTC. */
    updated_at: string | null;
    deleted_at: string | null;
}

export type TemplateStatusFilter = 'activas' | 'inactivas' | 'todas';

export type TemplatesIndexProps = {
    templates: ProjectsPaginated<TemplateRow>;
    filters: { q: string; estado: TemplateStatusFilter; papelera: boolean };
    trashedCount: number;
};

export interface TemplateTypeOption {
    id: number;
    name: string;
    /** false: el tipo está desactivado (solo aparece porque la plantilla lo usa). */
    is_active: boolean;
}

export type TemplateEditProps = {
    /** null: plantilla nueva vacía. `id` null: copia sin guardar («Duplicar»). */
    template: {
        id: number | null;
        name: string;
        description: string | null;
        is_active: boolean;
        structure: TemplateStructure;
    } | null;
    types: TemplateTypeOption[];
    priorities: TaskPriority[];
    limits: { max_tasks: number; max_days: number };
};

/** Prop diferida `templating` de projects/settings (ProjectTemplatingSettings). */
export interface ProjectTemplatingSettings {
    templates: TemplateOption[];
    banks: { id: number; name: string }[];
    uses_hour_banks: boolean;
    /** Y-m-d: inicio del proyecto o hoy. */
    default_start: string;
    task_count: number;
    archived: boolean;
    max_tasks: number;
}

export type RecurringFrequency = 'weekly' | 'monthly';

/** RecurringRuleItems::build(). */
export interface RecurringRuleItem {
    id: number;
    project_id: number;
    project: {
        id: number;
        name: string;
        code: string;
        archived: boolean;
    } | null;
    title: string;
    description: string | null;
    task_type_id: number | null;
    assignee_user_id: number | null;
    assignee: { id: number; name: string; is_active: boolean } | null;
    hour_bank_id: number | null;
    hour_bank: { id: number; name: string; open: boolean } | null;
    estimated_minutes: number | null;
    priority: TaskPriority;
    frequency: RecurringFrequency;
    interval: number;
    /** 1 = lunes … 7 = domingo. */
    weekday: number | null;
    /** 1-31; si el mes es más corto, su último día. */
    month_day: number | null;
    due_offset_days: number;
    starts_on: string;
    ends_on: string | null;
    is_active: boolean;
    last_generated_on: string | null;
    /** «Cada 2 semanas, los lunes». */
    summary: string;
    /** Y-m-d de la próxima tarea, o null (desactivada o terminada). */
    next_date: string | null;
    /** Por qué no creará sus tareas como se espera (bolsa cerrada, responsable de baja…). */
    warnings: string[];
}

export interface RecurringInstance {
    id: number;
    title: string;
    occurrence_date: string | null;
    due_date: string | null;
    status: {
        id: number;
        name: string;
        color: string;
        category: 'todo' | 'in_progress' | 'done';
    };
    assignee: { id: number; name: string } | null;
}

export interface RecurringOptions {
    members: { id: number; name: string }[];
    types: { id: number; name: string }[];
    banks: { id: number; name: string }[];
    uses_hour_banks: boolean;
}

/** Prop diferida `recurring` de projects/settings (ProjectRecurringSettings). */
export interface ProjectRecurringSettings {
    rules: RecurringRuleItem[];
    recent: RecurringInstance[];
    options: RecurringOptions;
    archived: boolean;
    /** Hoy en Madrid (Y-m-d). */
    today: string;
}

export type RecurringStatusFilter = 'activas' | 'inactivas' | 'todas';

export type RecurringIndexProps = {
    rules: ProjectsPaginated<RecurringRuleItem>;
    filters: { estado: RecurringStatusFilter; proyecto: number | null };
    projects: { id: number; name: string; code: string }[];
};
