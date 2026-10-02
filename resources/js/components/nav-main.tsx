import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

/** Id del contador de una entrada (lo describe para el lector de pantalla). */
function badgeId(item: NavItem): string {
    return `nav-badge-${toUrl(item.href).replace(/[^a-z0-9]+/gi, '-')}`;
}

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
                        const children = item.items ?? [];
                        // En una subpágina, la sección queda resaltada, pero la página actual es
                        // la subpágina (un solo aria-current="page").
                        const childActive = children.some(isActive);

                        return (
                            <SidebarMenuItem key={toUrl(item.href)}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={active}
                                    tooltip={{
                                        children: item.badge
                                            ? `${item.title} · ${item.badge.label}`
                                            : item.title,
                                    }}
                                >
                                    <Link
                                        href={item.href}
                                        prefetch
                                        aria-current={
                                            active && !childActive
                                                ? 'page'
                                                : undefined
                                        }
                                        aria-describedby={
                                            item.badge
                                                ? badgeId(item)
                                                : undefined
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
                                {item.badge ? (
                                    <SidebarMenuBadge
                                        id={badgeId(item)}
                                        className="rounded-full bg-primary text-primary-foreground peer-hover/menu-button:text-primary-foreground peer-data-[active=true]/menu-button:text-primary-foreground"
                                        data-test="nav-badge"
                                    >
                                        <span aria-hidden="true">
                                            {item.badge.count > 99
                                                ? '99+'
                                                : item.badge.count}
                                        </span>
                                        <span className="sr-only">
                                            {item.badge.label}
                                        </span>
                                    </SidebarMenuBadge>
                                ) : null}
                                {children.length > 0 ? (
                                    <SidebarMenuSub>
                                        {children.map((child) => {
                                            const current = isActive(child);

                                            return (
                                                <SidebarMenuSubItem
                                                    key={toUrl(child.href)}
                                                >
                                                    <SidebarMenuSubButton
                                                        asChild
                                                        isActive={current}
                                                    >
                                                        <Link
                                                            href={child.href}
                                                            prefetch
                                                            aria-current={
                                                                current
                                                                    ? 'page'
                                                                    : undefined
                                                            }
                                                        >
                                                            <span>
                                                                {child.title}
                                                            </span>
                                                        </Link>
                                                    </SidebarMenuSubButton>
                                                </SidebarMenuSubItem>
                                            );
                                        })}
                                    </SidebarMenuSub>
                                ) : null}
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>
            </nav>
        </SidebarGroup>
    );
}
