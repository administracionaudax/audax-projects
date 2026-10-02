/**
 * Contrato de la Fase 4 (planificación): dependencias fin-inicio (D-056) y propuesta de desplazar
 * sucesoras al reprogramar (D-057). Coincide con App\Http\Controllers\Schedule\*.
 */

/** Dependencia fin-inicio entre dos tareas del mismo proyecto. */
export interface TaskDependencyItem {
    id: number;
    predecessor_task_id: number;
    successor_task_id: number;
    type: 'finish_to_start';
}

/** Una sucesora en conflicto y las fechas a las que se propone llevarla (Y-m-d). */
export interface ShiftProposal {
    task_id: number;
    title: string;
    start_date: string | null;
    due_date: string | null;
    new_start_date: string | null;
    new_due_date: string | null;
    shift_days: number;
    predecessor_id: number;
}

/** Respuesta de POST /tareas/{task}/reprogramar/propuesta. */
export interface ReschedulePreview {
    proposals: ShiftProposal[];
}

/** Cuerpo de POST /tareas/{task}/reprogramar/propuesta y /tareas/{task}/reprogramar. */
export interface RescheduleRequest {
    start_date: string | null;
    due_date: string | null;
    /** Solo al confirmar: desplaza también las sucesoras en conflicto (se recalculan en el servidor). */
    shift_successors?: boolean;
}
