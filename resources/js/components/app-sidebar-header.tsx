import { usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SearchTrigger } from '@/components/global-search';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import { TimerChip } from '@/components/time/timer-chip';
import { TimerStopDialog } from '@/components/time/timer-stop-dialog';
import { useTimeWarnings } from '@/components/time/use-time-warnings';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * Cabecera de la app: menú, migas, temporizador activo (SPEC §7) o «Imputar horas», y búsqueda.
 * También pinta los avisos de imputación y el diálogo de «no se ha podido imputar» del
 * temporizador, que comparten todas las páginas.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { timer, config, auth } = usePage().props;
    const [logging, setLogging] = useState(false);
    const internal = auth.user !== null && !auth.user.is_client;

    useTimeWarnings();

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {internal ? (
                timer ? (
                    <TimerChip
                        timer={timer}
                        warningHours={config?.timer_warning_hours ?? 10}
                    />
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        className="shrink-0 px-2.5 sm:px-3"
                        onClick={() => setLogging(true)}
                        aria-label={t('hours.header.log_time')}
                        data-test="header-log-time"
                    >
                        <Plus aria-hidden="true" />
                        <span className="hidden sm:inline">
                            {t('hours.header.log_time')}
                        </span>
                    </Button>
                )
            ) : null}
            <SearchTrigger className="shrink-0" />
            {internal ? (
                <>
                    <TimeEntryDialog open={logging} onOpenChange={setLogging} />
                    <TimerStopDialog />
                </>
            ) : null}
        </header>
    );
}
