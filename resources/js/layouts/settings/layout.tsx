import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    FileArchive,
    MonitorSmartphone,
    Palette,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { t } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotificationSettings } from '@/routes/notification-settings';
import { index as myDataIndex } from '@/routes/privacy/exports';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as sessionsIndex } from '@/routes/sessions';
import type { NavItem } from '@/types';

/** Con `isClient`, sin las páginas solo para internos (preferencias de notificación y mis datos). */
export function settingsNavItems(isClient = false): NavItem[] {
    return [
        {
            title: t('settings.nav.profile'),
            href: editProfile(),
            icon: UserRound,
        },
        {
            title: t('settings.nav.security'),
            href: editSecurity(),
            icon: ShieldCheck,
        },
        {
            title: t('settings.nav.appearance'),
            href: editAppearance(),
            icon: Palette,
        },
        ...(isClient
            ? []
            : [
                  {
                      title: t('settings.nav.notifications'),
                      href: editNotificationSettings(),
                      icon: Bell,
                  },
              ]),
        {
            title: t('settings.nav.sessions'),
            href: sessionsIndex(),
            icon: MonitorSmartphone,
        },
        // Exportación de los datos personales (D-075): solo la plantilla.
        ...(isClient
            ? []
            : [
                  {
                      title: t('privacy.my_data.nav'),
                      href: myDataIndex(),
                      icon: FileArchive,
                  },
              ]),
    ];
}

/**
 * Ajustes personales. Se anida dentro del layout interno o del portal según
 * `auth.user.is_client` (lo decide resources/js/app.tsx).
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const isClient = usePage().props.auth?.user?.is_client === true;

    return (
        <div className="px-4 py-6">
            {/* Título de la página (h1); cada página de ajustes titula sus secciones en h2. */}
            <Heading
                as="h1"
                title={t('settings.title')}
                description={t('settings.description')}
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label={t('settings.nav.label')}
                    >
                        {settingsNavItems(isClient).map((item) => {
                            const active = isCurrentOrParentUrl(item.href);

                            return (
                                <Button
                                    key={toUrl(item.href)}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn('w-full justify-start', {
                                        'bg-muted': active,
                                    })}
                                >
                                    <Link
                                        href={item.href}
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                    >
                                        {item.icon && (
                                            <item.icon
                                                aria-hidden="true"
                                                className="h-4 w-4"
                                                strokeWidth={1.5}
                                            />
                                        )}
                                        {item.title}
                                    </Link>
                                </Button>
                            );
                        })}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
