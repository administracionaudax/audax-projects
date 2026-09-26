import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

export function NavMain({ items }: { items: NavItem[] }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    // Inicio (/) solo está activo en su propia URL; el resto, también en sus subpáginas.
    const isActive = (item: NavItem) =>
        toUrl(item.href) === '/'
            ? isCurrentUrl(item.href)
            : isCurrentOrParentUrl(item.href);

    return (
        <SidebarGroup className="px-2 py-0">
            <nav aria-label={t('nav.main_label')}>
                <SidebarMenu>
                    {items.map((item) => {
                        const active = isActive(item);

                        return (
                            <SidebarMenuItem key={toUrl(item.href)}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={active}
                                    tooltip={{ children: item.title }}
                                >
                                    <Link
                                        href={item.href}
                                        prefetch
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                    >
                                        {item.icon && (
                                            <item.icon
                                                aria-hidden="true"
                                                strokeWidth={1.5}
                                            />
                                        )}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>
            </nav>
        </SidebarGroup>
    );
}
