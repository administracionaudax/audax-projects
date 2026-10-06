import type { WeeklyStreakSummary } from './weeklies';

/**
 * Props de cada página Inertia (contrato con los controladores de app/Http).
 * Las props compartidas (auth, name, sidebarOpen) están en types/global.d.ts.
 */

/** Secciones de la barra lateral que aún son un marcador (routes/web.php). */
export type PlaceholderSection =
    | 'my-tasks'
    | 'projects'
    | 'clients'
    | 'hour-banks'
    | 'time'
    | 'workload'
    | 'reports'
    | 'chat';

export type PlaceholderPageProps = {
    section: PlaceholderSection;
};

export type LoginPageProps = {
    status?: string | null;
    canResetPassword: boolean;
    /** «Entrar con Google» (D-165): credenciales de Google y ajuste activado. */
    googleLogin?: boolean;
};

export type ForgotPasswordPageProps = {
    status?: string | null;
};

export type ResetPasswordPageProps = {
    token: string;
    email: string;
    passwordRules: string;
};

export type AcceptInvitationPageProps = ResetPasswordPageProps & {
    /** «Entrar con Google» en lugar de la contraseña (D-165), con un correo de un dominio permitido. */
    googleLogin?: boolean;
};

export type ProfilePageProps = {
    mustVerifyEmail: boolean;
    status?: string | null;
    /** Puesto (Fase 10, F-026). */
    jobTitle?: string | null;
    /** Departamento, de solo lectura (F-027, D-234). */
    department?: string | null;
    /** Estadísticas de la weekly (F-028, prop diferida); null si no la escribe. */
    weeklyStats?: WeeklyStreakSummary | null;
};

export type SecurityPageProps = {
    passwordRules: string;
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

/** App\Http\Controllers\Settings\SessionsController::index. */
export type ActiveSession = {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    browser: string | null;
    platform: string | null;
    is_current: boolean;
    /** Instante ISO 8601 (UTC). */
    last_active_at: string | null;
};

export type SessionsPageProps = {
    sessions: ActiveSession[];
    /** false si el driver de sesión no es "database": no se pueden listar ni cerrar sesiones. */
    supported: boolean;
};

/** GET /buscar?q= (App\Http\Controllers\SearchController). */
export type SearchResultType =
    | 'page'
    | 'person'
    | 'client'
    | 'project'
    | 'task'
    | 'forecast'
    | 'message';

export type SearchResult = {
    type: SearchResultType;
    id: string | number;
    title: string;
    subtitle?: string | null;
    url: string;
};

export type SearchResponse = {
    results: SearchResult[];
};
