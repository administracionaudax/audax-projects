import { AppContent } from '@/components/app-content';
import { AppFreshness } from '@/components/app-freshness';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { GlobalSearchProvider } from '@/components/global-search';
import { PrivacyNoticeBanner } from '@/components/privacy/privacy-notice-banner';
import { GlobalBanner } from '@/components/weeklies/global-banner';
import { ModulePreviewBanner } from '@/components/weeklies/module-preview-banner';
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
                    <GlobalBanner />
                    <ModulePreviewBanner />
                    <PrivacyNoticeBanner />
                    {children}
                </AppContent>
                <AppFreshness />
            </AppShell>
        </GlobalSearchProvider>
    );
}
