import { usePage } from '@inertiajs/react';
import { RealtimeRoot } from '@/components/realtime/realtime-root';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { configureRealtime } from '@/lib/realtime';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    // Tiempo real (Fase 6): Echo se configura una vez, con los datos del servidor.
    configureRealtime(usePage().props.realtime ?? null);

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {/* Presencia y avisos del navegador de la sesión (no pinta nada). */}
            <RealtimeRoot />
            {children}
        </AppLayoutTemplate>
    );
}
