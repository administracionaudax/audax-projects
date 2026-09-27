import type { ActiveTimer, AppConfig, Auth } from '@/types/auth';
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
            [key: string]: unknown;
        };
    }
}
