import { Link } from '@inertiajs/react';
import {
    ChartGantt,
    Clock,
    ListChecks,
    TriangleAlert,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { ClientPortalSettingsDialog } from '@/components/portal/access/client-portal-settings-dialog';
import { InvitePortalUserDialog } from '@/components/portal/access/invite-portal-user-dialog';
import { PortalUserList } from '@/components/portal/access/portal-user-list';
import type { ClientPortalAccess } from '@/components/portal/access/types';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

/**
 * Sección «Acceso al portal» de la ficha de cliente (D-063, D-064): sus usuarios del portal con
 * invitar, reenviar, revocar y reactivar; los ajustes del portal del cliente (cómo se nombra a las
 * personas, qué horas ve y los avisos por email) y los proyectos abiertos al portal. Quien no lo
 * gestiona (portal null) solo ve quién lo hace.
 */
export function ClientPortalSection({
    clientId,
    clientName,
    portal,
}: {
    clientId: number;
    clientName: string;
    portal: ClientPortalAccess | null | undefined;
}) {
    if (!portal) {
        return (
            <p
                className="text-sm text-muted-foreground"
                data-test="portal-managed-by"
            >
                {t('portal_access.section.managed_by')}
            </p>
        );
    }

    const { can, users, settings, projects } = portal;

    return (
        <div className="grid gap-6" data-test="client-portal">
            {portal.client_active ? null : (
                <Alert role="status">
                    <TriangleAlert aria-hidden="true" />
                    <AlertDescription>
                        {t('portal_access.section.client_inactive')}
                    </AlertDescription>
                </Alert>
            )}

            <section
                aria-labelledby={`portal-users-${clientId}`}
                className="grid gap-3"
            >
                <h3
                    id={`portal-users-${clientId}`}
                    className="text-sm font-medium"
                >
                    {t('portal_access.users.title', { count: users.length })}
                </h3>
                {users.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={t('portal_access.users.empty')}
                        description={t('portal_access.users.empty_description')}
                    />
                ) : (
                    <PortalUserList
                        clientId={clientId}
                        users={users}
                        canManage={can.manageUsers}
                        clientActive={portal.client_active}
                    />
                )}
                {can.manageUsers ? (
                    <div>
                        <InvitePortalUserDialog
                            clientId={clientId}
                            clientName={clientName}
                            disabled={!portal.client_active}
                        />
                    </div>
                ) : null}
            </section>

            <section
                aria-labelledby={`portal-settings-${clientId}`}
                className="grid gap-3"
            >
                <h3
                    id={`portal-settings-${clientId}`}
                    className="text-sm font-medium"
                >
                    {t('portal_access.settings.title')}
                </h3>
                <dl className="grid gap-3" data-test="portal-settings-summary">
                    <Detail label={t('portal_access.settings.person_display')}>
                        {t(
                            `portal_access.person_display.${settings.person_display}`,
                        )}
                    </Detail>
                    <Detail
                        label={t('portal_access.settings.entry_visibility')}
                    >
                        {t(
                            `portal_access.entry_visibility.${settings.entry_visibility}`,
                        )}
                    </Detail>
                    <Detail label={t('portal_access.settings.notify')}>
                        {settings.notify_thresholds
                            ? t('portal_access.settings.notify_on')
                            : t('portal_access.settings.notify_off')}
                    </Detail>
                </dl>
                {can.updateSettings ? (
                    <div>
                        <ClientPortalSettingsDialog
                            clientId={clientId}
                            settings={settings}
                            options={portal.options}
                        />
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        {t('portal_access.settings.who')}
                    </p>
                )}
            </section>

            <section
                aria-labelledby={`portal-projects-${clientId}`}
                className="grid gap-3"
            >
                <h3
                    id={`portal-projects-${clientId}`}
                    className="text-sm font-medium"
                >
                    {t('portal_access.projects.title')}
                </h3>
                {projects.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('portal_access.projects.empty')}
                    </p>
                ) : (
                    <ul className="grid gap-2" data-test="portal-open-projects">
                        {projects.map((project) => (
                            <li key={project.id} className="grid gap-1">
                                <Link
                                    href={urls.project(project.id, 'ajustes')}
                                    aria-label={t(
                                        'portal_access.projects.settings_link',
                                        { project: project.name },
                                    )}
                                    className={cn(
                                        'w-fit rounded-sm text-sm text-primary-text hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {project.name}
                                </Link>
                                <div className="flex flex-wrap gap-1.5">
                                    {project.project_visible ? (
                                        <StatusBadge
                                            tone="info"
                                            icon={ListChecks}
                                        >
                                            {t('portal_access.projects.tasks')}
                                        </StatusBadge>
                                    ) : null}
                                    {project.show_task_hours ? (
                                        <StatusBadge tone="info" icon={Clock}>
                                            {t('portal_access.projects.hours')}
                                        </StatusBadge>
                                    ) : null}
                                    {project.gantt_visible ? (
                                        <StatusBadge
                                            tone="info"
                                            icon={ChartGantt}
                                        >
                                            {t('portal_access.projects.gantt')}
                                        </StatusBadge>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
