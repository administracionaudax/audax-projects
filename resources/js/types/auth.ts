/** Contrato con App\Http\Middleware\HandleInertiaRequests::share(). */
import type { AppModule, WeeklyAwayStatus } from './weeklies';

export type Role =
    | 'admin'
    | 'department_manager'
    | 'employee'
    | 'collaborator'
    | 'client';

export type ThemePreference = 'light' | 'dark' | 'system';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    theme_preference: ThemePreference;
    two_factor_enabled: boolean;
    roles: Role[];
    is_client: boolean;
    /** Colaborador externo (D-134): solo sus proyectos, sus tareas y sus chats. */
    is_collaborator: boolean;
    /** «Estoy fuera» de la Weekly (D-228), si sigue activo. */
    weekly_away?: WeeklyAwayStatus | null;
};

export type Abilities = {
    viewHourBanks: boolean;
    viewAdmin: boolean;
    viewFinancials: boolean;
    /** Crear clientes y proyectos (D-022). */
    createClients: boolean;
    createProjects: boolean;
    /** Aprobar o devolver semanas (D-020). */
    approveTime: boolean;
    /** Bloquear horas al facturar (D-034). */
    lockTime: boolean;
    manageUsers: boolean;
    manageSettings: boolean;
    /** «Ausencias del equipo»: aprobar y registrar las de su equipo (D-049). */
    viewTeamAbsences: boolean;
    /** Clientes, Carga, Ausencias e Informes: todos los internos salvo los colaboradores externos (D-134). */
    viewClients: boolean;
    viewWorkload: boolean;
    viewAbsences: boolean;
    viewReports: boolean;
    /** La Weekly (Fase 10, D-147): usarla (plantilla interna) y gestionarla (admins y responsables). */
    useWeeklies?: boolean;
    manageWeeklies?: boolean;
    /** «Uso de IA» (F-180): solo admins. */
    viewAiUsage?: boolean;
    /** Plan del día (D-251): la plantilla interna con el módulo day_plan visible. */
    useDayPlan?: boolean;
    /** Previsión (D-284): la global (admins, responsables y manage-forecast) y la propia carga. */
    viewForecast?: boolean;
    useForecast?: boolean;
};

/** Temporizador activo del usuario (props compartidas `timer`, SPEC §7). */
export type ActiveTimer = {
    task_id: number;
    task_title: string;
    project_id: number;
    project_code: string;
    project_name: string;
    /** Instante ISO en UTC. */
    started_at: string;
    description: string | null;
    /** Línea del plan del día desde la que se arrancó (D-254). */
    day_plan_item?: { id: number; text: string } | null;
};

/** Configuración compartida con todas las páginas internas. */
export type AppConfig = {
    /** Umbrales de alerta de bolsa en % (por defecto 75, 90, 100; D-035). */
    hour_bank_thresholds: number[];
    /** Aviso del temporizador pasadas estas horas (SPEC §7). */
    timer_warning_hours: number;
    /** Redondeo del temporizador en minutos. */
    timer_rounding_minutes: number;
    /** Ajuste «descripción obligatoria» de las entradas de horas (SPEC §7). */
    description_required: boolean;
    /** Chat (Fase 6): tamaño máximo de cada adjunto en MB (por defecto 50). */
    max_attachment_mb?: number;
    /** Chat (Fase 6): duración máxima de los audios en segundos (por defecto 300). */
    max_audio_seconds?: number;
    /** Fase 10 (F-177): módulos activos; apagado, sus rutas dan 404 y no salen en la navegación. */
    modules?: Record<AppModule, boolean>;
    /** Modo de prueba (D-239): módulos apagados que esta persona (un admin) ve solo por la prueba. */
    modules_preview?: AppModule[];
    /** Fase 10 (F-178): aviso global en todas las páginas internas. */
    global_banner?: GlobalBanner | null;
};

/** Ajuste `global_banner` (F-178). */
export type GlobalBanner = {
    message: string;
    tone: 'info' | 'warning';
};

export type Auth = {
    user: User | null;
    can: Abilities;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
