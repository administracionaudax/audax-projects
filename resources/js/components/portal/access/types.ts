/**
 * Acceso al portal, lado interno (Fase 5, D-063 y D-064). Contrato con
 * App\Domain\Portal\Access\ClientPortalAccess y ProjectPortalSettings.
 */
import type {
    PortalEntryVisibility,
    PortalPersonDisplay,
} from '@/types/portal';

/** Invitación de un usuario del portal: pendiente (enlace vigente), caducada o aceptada. */
export type PortalInvitationState = 'pending' | 'expired' | 'accepted';

export type PortalUserRow = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    invitation: PortalInvitationState;
    /** Instante ISO (UTC) en que caduca el enlace pendiente. */
    invitation_expires_at: string | null;
    /** Último inicio de sesión correcto (ISO, UTC). */
    last_login_at: string | null;
};

export type ClientPortalSettings = {
    person_display: PortalPersonDisplay;
    entry_visibility: PortalEntryVisibility;
    notify_thresholds: boolean;
};

/** Proyecto del cliente abierto al portal (vista, horas por tarea y Gantt). */
export type ClientPortalProject = {
    id: number;
    code: string;
    name: string;
    project_visible: boolean;
    show_task_hours: boolean;
    gantt_visible: boolean;
};

/** Prop `portal` de la ficha de cliente (null para quien no gestiona el portal). */
export type ClientPortalAccess = {
    can: { manageUsers: boolean; updateSettings: boolean };
    client_active: boolean;
    users: PortalUserRow[];
    settings: ClientPortalSettings;
    options: {
        person_display: PortalPersonDisplay[];
        entry_visibility: PortalEntryVisibility[];
    };
    projects: ClientPortalProject[];
};

/** Prop `portal` de los ajustes del proyecto. */
export type ProjectPortalSettings = {
    project_visible: boolean;
    show_task_hours: boolean;
    gantt_visible: boolean;
    client: { id: number; name: string; is_active: boolean } | null;
    /** Usuarios del portal activos del cliente. */
    active_users: number;
};

/** Props de /admin/identidad. */
export type IdentityPageProps = {
    identity: {
        company_name: string;
        logo: { url: string; width: number; height: number } | null;
    };
    limits: { max_kb: number; max_side: number };
};
