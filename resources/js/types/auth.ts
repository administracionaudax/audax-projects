/** Contrato con App\Http\Middleware\HandleInertiaRequests::share(). */

export type Role = 'admin' | 'department_manager' | 'employee' | 'client';

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
