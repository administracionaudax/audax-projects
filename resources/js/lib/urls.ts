/**
 * URLs de la app entre áreas (contrato de la Fase 1). Dentro de su área, cada página usa las
 * rutas tipadas de Wayfinder (`@/routes/...`); para enlazar a OTRA área se usan estas funciones,
 * que no dependen de controladores ajenos. Las URL visibles están en español (D-016).
 */

export type ProjectTab =
    | 'resumen'
    | 'tareas'
    | 'gantt'
    | 'bolsas'
    | 'horas'
    | 'archivos'
    | 'ajustes';

export const urls = {
    home: () => '/',
    myTasks: () => '/mis-tareas',
    clients: () => '/clientes',
    client: (id: number) => `/clientes/${id}`,
    /**
     * Clientes activos para selectores (GET → [{id, name}], tipo Option). Con `include`, añade ese
     * cliente aunque esté desactivado (p. ej. el cliente actual de un proyecto que se edita).
     */
    clientOptions: (include?: number) =>
        include
            ? `/clientes/opciones?incluir=${include}`
            : '/clientes/opciones',
    projects: () => '/proyectos',
    project: (id: number, tab: ProjectTab = 'resumen') =>
        tab === 'resumen' ? `/proyectos/${id}` : `/proyectos/${id}/${tab}`,
    /** Pestaña Gantt del proyecto (Fase 4). */
    projectGantt: (id: number) => `/proyectos/${id}/gantt`,
    /** Gantt multiproyecto (Fase 4). */
    gantt: () => '/gantt',
    /** Vista Calendario de la pestaña Tareas (D-061); `month` en formato "2026-10". */
    projectCalendar: (id: number, month?: string) =>
        month
            ? `/proyectos/${id}/tareas?vista=calendario&mes=${month}`
            : `/proyectos/${id}/tareas?vista=calendario`,
    /** Abre el panel lateral de la tarea sobre la lista de tareas de su proyecto. */
    task: (projectId: number, taskId: number) =>
        `/proyectos/${projectId}/tareas?tarea=${taskId}`,
    /**
     * Enlace estable a una tarea (/tareas/{id}): redirige al panel en el proyecto que tenga al
     * abrirlo. Para los enlaces que se guardan (notificaciones) y pueden quedar viejos si la tarea
     * se mueve de proyecto.
     */
    taskById: (taskId: number) => `/tareas/${taskId}`,
    hourBanks: () => '/bolsas',
    hourBank: (projectId: number, bankId: number) =>
        `/proyectos/${projectId}/bolsas/${bankId}`,
    /** Hoja semanal; `week` en formato ISO "2026-W39". */
    timesheet: (week?: string) => (week ? `/horas?semana=${week}` : '/horas'),
    approvals: () => '/horas/aprobaciones',
    timeLocks: () => '/horas/bloqueo',
    notifications: () => '/notificaciones',
    /** Temporizador (Agente D): POST inicia {task_id}, POST …/parar lo para e imputa, DELETE lo descarta. */
    timerStart: () => '/temporizador',
    timerStop: () => '/temporizador/parar',
    timerDiscard: () => '/temporizador',
    admin: () => '/admin',
    adminUsers: () => '/admin/usuarios',
} as const;
