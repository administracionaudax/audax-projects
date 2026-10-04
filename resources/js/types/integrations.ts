/**
 * Integraciones (Fase 9, D-142): contrato con App\Http\Controllers\Integrations\IntegrationsController
 * y la prop compartida `integrations` (HandleInertiaRequests).
 */

/** Prop compartida: sin credenciales, o para un colaborador externo, google_sheets es false. */
export type IntegrationsSharedProps = {
    google_sheets: boolean;
    google_connected: boolean;
};

export type GoogleConnectionData = {
    email: string;
    /** ISO 8601 en UTC. */
    connected_at: string | null;
};

/** Props de settings/integrations. */
export type IntegrationsPageProps = {
    google: {
        /** ¿Están las credenciales OAuth en el servidor? */
        available: boolean;
        connection: GoogleConnectionData | null;
    };
};
