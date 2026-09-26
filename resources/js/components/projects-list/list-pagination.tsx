import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { ProjectsPaginated } from '@/types';

/**
 * Paginación de los listados del área (anterior / siguiente) con el recuento de resultados.
 * Conserva los filtros de la URL (el servidor los añade a los enlaces).
 */
export function ListPagination({
    page,
    label,
}: {
    page: Pick<ProjectsPaginated<unknown>, 'meta' | 'links'>;
    /** Nombre accesible de la navegación («Páginas de proyectos»). */
    label: string;
}) {
    const { meta, links } = page;

    if (meta.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label={label}
            className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground"
        >
            <p>
                {t('projects.pagination.summary', {
                    from: meta.from ?? 0,
                    to: meta.to ?? 0,
                    total: meta.total,
                })}
            </p>
            {meta.last_page > 1 ? (
                <div className="flex items-center gap-2">
                    <PageLink
                        href={links.prev}
                        label={t('projects.pagination.previous')}
                        icon="prev"
                    />
                    <span className="tabular">
                        {t('projects.pagination.page', {
                            page: meta.current_page,
                            pages: meta.last_page,
                        })}
                    </span>
                    <PageLink
                        href={links.next}
                        label={t('projects.pagination.next')}
                        icon="next"
                    />
                </div>
            ) : null}
        </nav>
    );
}

function PageLink({
    href,
    label,
    icon,
}: {
    href: string | null;
    label: string;
    icon: 'prev' | 'next';
}) {
    const Icon = icon === 'prev' ? ChevronLeft : ChevronRight;

    if (!href) {
        return (
            <Button variant="outline" size="sm" disabled>
                {icon === 'prev' ? <Icon aria-hidden="true" /> : null}
                {label}
                {icon === 'next' ? <Icon aria-hidden="true" /> : null}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} preserveScroll>
                {icon === 'prev' ? <Icon aria-hidden="true" /> : null}
                {label}
                {icon === 'next' ? <Icon aria-hidden="true" /> : null}
            </Link>
        </Button>
    );
}
