import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';

export function AppShell({ children }: { children: ReactNode }) {
    const isOpen = usePage().props.sidebarOpen;

    return <SidebarProvider defaultOpen={isOpen}>{children}</SidebarProvider>;
}
