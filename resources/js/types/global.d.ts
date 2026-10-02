import type { ActiveTimer, AppConfig, Auth } from '@/types/auth';
import type { ChatSharedProps } from '@/types/chat';
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
            [key: string]: unknown;
        };
    }
}
