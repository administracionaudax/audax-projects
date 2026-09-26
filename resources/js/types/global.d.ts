import type { ActiveTimer, AppConfig, Auth } from '@/types/auth';

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
            [key: string]: unknown;
        };
    }
}
