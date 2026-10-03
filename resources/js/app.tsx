import '@fontsource/dm-sans/latin-400.css';
import '@fontsource/dm-sans/latin-500.css';
import '@fontsource/dm-sans/latin-600.css';
import '@fontsource/dm-sans/latin-400-italic.css';
import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import PortalLayout from '@/layouts/portal-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { t } from '@/lib/i18n';
import { registerServiceWorker } from '@/lib/register-sw';
import type { Auth } from '@/types';

const appName = import.meta.env.VITE_APP_NAME || t('brand.app_name');

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    layout: (name, page) => {
        switch (true) {
            // La guía de estilo es pública en desarrollo y trae su propia maquetación. La página
            // de error, también: en un 404 de una ruta que no existe no hay props compartidas.
            case name === 'styleguide' || name === 'error':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('portal/'):
                return PortalLayout;
            // Los ajustes son comunes: los clientes los ven dentro del portal.
            case name.startsWith('settings/'):
                return (page.props as { auth?: Auth }).auth?.user?.is_client
                    ? [PortalLayout, SettingsLayout]
                    : [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#0171FF',
    },
});

// Aplica el tema claro/oscuro guardado antes del primer render.
initializeTheme();

// PWA: solo en producción (compilación) y si el navegador lo admite.
registerServiceWorker();
