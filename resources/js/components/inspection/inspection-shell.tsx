import { router } from '@inertiajs/react';
import { Eye, LogOut } from 'lucide-react';
import type { ReactNode } from 'react';
import { AudaxWordmark } from '@/components/app-logo';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { logout } from '@/routes/inspection';
import type { InspectionPortalAccess } from '@/types/people-register';

/**
 * Marco de las páginas de la Inspección de Trabajo (D-353): fuera de la app, con el aviso fijo de
 * solo lectura, a quién se dio el acceso, hasta cuándo vale y «Salir».
 */
export function InspectionShell({
    access,
    children,
}: {
    access?: InspectionPortalAccess;
    children: ReactNode;
}) {
    return (
        <div className="min-h-svh bg-background text-foreground">
            <header className="border-b">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div className="flex items-center gap-2">
                        <AudaxWordmark className="h-3.5" />
                        <span className="text-sm">
                            {t('people.portal.brand')}
                        </span>
                    </div>
                    {access ? (
                        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            <span>
                                {access.name}
                                {access.reference
                                    ? ` · ${access.reference}`
                                    : ''}
                            </span>
                            <span>
                                {t('people.portal.valid_until', {
                                    date: formatDateTime(access.valid_until),
                                })}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => router.post(logout.url())}
                                data-test="inspection-logout"
                            >
                                <LogOut aria-hidden="true" />
                                {t('people.portal.logout')}
                            </Button>
                        </div>
                    ) : null}
                </div>
                <p
                    className="flex items-center justify-center gap-2 bg-info-soft px-4 py-1.5 text-center text-xs"
                    role="note"
                    data-test="read-only-banner"
                >
                    <Eye aria-hidden="true" className="size-3.5" />
                    {t('people.portal.read_only')}
                </p>
            </header>
            <main className="mx-auto grid max-w-6xl gap-6 px-4 py-6">
                {children}
            </main>
        </div>
    );
}
