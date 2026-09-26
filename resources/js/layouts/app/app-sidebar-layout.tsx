import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { GlobalSearchProvider } from '@/components/global-search';
import { SkipLink } from '@/components/skip-link';
import { ThemeSync } from '@/components/theme-sync';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <GlobalSearchProvider>
            <ThemeSync />
            <SkipLink />
            <AppShell>
                <AppSidebar />
                <AppContent className="min-w-0 overflow-x-clip">
                    <AppSidebarHeader breadcrumbs={breadcrumbs} />
                    {children}
                </AppContent>
            </AppShell>
        </GlobalSearchProvider>
    );
}
