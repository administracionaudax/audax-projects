import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment, useId } from 'react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarSeparator,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useNavSections } from '@/hooks/use-nav-sections';
import type { NavSectionId } from '@/hooks/use-nav-sections';
import { t } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

/** Id del contador de una entrada (lo describe para el lector de pantalla). */
function badgeId(item: NavItem): string {
    return `nav-badge-${toUrl(item.href).replace(/[^a-z0-9]+/gi, '-')}`;
}

/**
 * Bloque de la navegación. Sin `label`, las entradas fijas de arriba (Inicio y Chat); con `label`,
 * una sección plegable con su encabezado (D-260): Proyectos, Weekly, Personas, Facturación y
 * Administración. Un bloque sin entradas no se pinta.
 */
export type NavSection = {
    id: string;
    label?: string;
    items: NavItem[];
};

/**
 * Navegación principal: un solo <nav> con sus bloques, separados por una línea fina (el separador
 * de la barra lateral, D-137). Las secciones con encabezado se pliegan y despliegan (D-260): el
 * encabezado es un botón con `aria-expanded` y `aria-controls`; el estado es de cada persona y se
 * guarda (useNavSections). La sección de la página actual se despliega sola al entrar en ella.
 * Con la barra reducida a iconos no hay encabezados: se ven todas las entradas, con su tooltip.
 */
export function NavMain({
    items,
    sections,
}: {
    items?: NavItem[];
    sections?: NavSection[];
}) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const { state, isMobile } = useSidebar();
    const iconMode = state === 'collapsed' && !isMobile;
    const blocks = (sections ?? [{ id: 'main', items: items ?? [] }]).filter(
        (section) => section.items.length > 0,
    );

    // La sección de la página actual (también en sus subpáginas): se despliega sola al entrar.
    const contains = (item: NavItem): boolean =>
        (toUrl(item.href) === '/'
            ? isCurrentUrl(item.href)
            : isCurrentOrParentUrl(item.href)) ||
        (item.items ?? []).some(contains);
    const activeSection =
        blocks.find((section) => section.label && section.items.some(contains))
            ?.id ?? null;
    const { isOpen, setOpen } = useNavSections(activeSection);

    return (
        <nav aria-label={t('nav.main_label')}>
            {blocks.map((section, index) => (
                <Fragment key={section.id}>
                    {index > 0 ? (
                        <SidebarSeparator
                            className="my-2"
                            data-test="nav-separator"
                        />
                    ) : null}
                    <NavBlock
                        section={section}
                        iconMode={iconMode}
                        open={isOpen(section.id)}
                        onToggle={() =>
                            setOpen(
                                section.id as NavSectionId,
                                !isOpen(section.id),
                            )
                        }
                    />
                </Fragment>
            ))}
        </nav>
    );
}

function NavBlock({
    section,
    iconMode,
    open,
    onToggle,
}: {
    section: NavSection;
    iconMode: boolean;
    open: boolean;
    onToggle: () => void;
}) {
    const labelId = useId();
    const contentId = useId();
    // Encabezado plegable con la barra desplegada (y en el móvil); en modo icono no hay nada que
    // plegar: las entradas se ven siempre y el grupo se nombra con aria-label.
    const collapsible = Boolean(section.label) && !iconMode;
    const expanded = !collapsible || open;

    return (
        <SidebarGroup
            className="px-2 py-0"
            role={section.label ? 'group' : undefined}
            aria-labelledby={collapsible ? labelId : undefined}
            aria-label={
                section.label && !collapsible ? section.label : undefined
            }
            data-test={`nav-section-${section.id}`}
            data-state={collapsible ? (open ? 'open' : 'closed') : undefined}
        >
            {collapsible ? (
                // Encabezado de sección con más presencia que sus entradas: mismo tamaño, peso 500 y
                // color de texto pleno (antes, 12 px y apagado). Las clases van aquí y no en el botón:
                // con `asChild` se suman sin fusionar y ganarían las de serie.
                <SidebarGroupLabel
                    asChild
                    className="h-9 text-sm font-medium text-sidebar-foreground"
                >
                    <button
                        type="button"
                        id={labelId}
                        aria-expanded={open}
                        aria-controls={contentId}
                        onClick={onToggle}
                        className="w-full cursor-pointer hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                        data-test={`nav-section-toggle-${section.id}`}
                    >
                        <span className="truncate">{section.label}</span>
                        <ChevronRight
                            aria-hidden="true"
                            strokeWidth={1.5}
                            className={cn(
                                'ml-auto transition-transform duration-200 motion-reduce:transition-none',
                                open && 'rotate-90',
                            )}
                        />
                    </button>
                </SidebarGroupLabel>
            ) : null}
            <div id={contentId} hidden={!expanded}>
                <NavItems items={section.items} />
            </div>
        </SidebarGroup>
    );
}

function NavItems({ items }: { items: NavItem[] }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    // Inicio (/) solo está activo en su propia URL; el resto, también en sus subpáginas. Una
    // entrada `exact` (el panel de administración) solo en la suya.
    const isActive = (item: NavItem) =>
        toUrl(item.href) === '/' || item.exact
            ? isCurrentUrl(item.href)
            : isCurrentOrParentUrl(item.href);

    return (
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
                                    active && !childActive ? 'page' : undefined
                                }
                                aria-describedby={
                                    item.badge ? badgeId(item) : undefined
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
                                                    <span>{child.title}</span>
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
    );
}
