import type { ActiveTimer, AppConfig, Auth } from '@/types/auth';
import type { ChatSharedProps } from '@/types/chat';
import type { IntegrationsSharedProps } from '@/types/integrations';
import type { RealtimeConfig } from '@/lib/realtime';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /** Solo en páginas de usuarios internos autenticados. */
            timer?: ActiveTimer | null;
            notifications?: { unread: number };
            config?: AppConfig;
            /** Conexión de Echo con Reverb (Fase 6); null si el tiempo real está apagado. */
            realtime?: RealtimeConfig | null;
            /** Total sin leer del chat para la navegación (Fase 6, C1). */
            chat?: ChatSharedProps;
            /** Aviso de privacidad pendiente de leer (D-075). */
            privacy?: { needs_acknowledgement: boolean };
            /** La Weekly (Fase 10, F-003): mi weekly pendiente de la semana activa (0 o 1). */
            weeklies?: { pending: number };
            /** Google Sheets (Fase 9, D-142): si se ofrece y si la cuenta está conectada. */
            integrations?: IntegrationsSharedProps;
            [key: string]: unknown;
        };
    }
}
