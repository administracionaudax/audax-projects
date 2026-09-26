import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { AdminPaginated } from '@/types';

/**
 * Paginación de un listado (anterior / siguiente y «x–y de z»). Conserva los filtros: las URL de
 * Laravel ya los llevan (withQueryString).
 */
export function Pagination({
    page,
    label,
}: {
    page: Pick<AdminPaginated<unknown>, 'links' | 'meta'>;
    /** Nombre accesible de la navegación (p. ej. «Páginas de usuarios»). */
    label: string;
}) {
    const { meta, links } = page;

    if (meta.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label={label}
            className="flex flex-wrap items-center justify-between gap-3 text-sm"
        >
            <p className="text-muted-foreground" aria-live="polite">
                {t('admin.pagination.summary', {
                    from: meta.from ?? 0,
                    to: meta.to ?? 0,
                    total: meta.total,
                })}
            </p>
            {meta.last_page > 1 ? (
                <div className="flex items-center gap-2">
                    <PageLink
                        href={links.prev}
                        label={t('admin.pagination.previous')}
                        direction="previous"
                    />
                    <span className="text-muted-foreground">
                        {t('admin.pagination.page', {
                            page: meta.current_page,
                            pages: meta.last_page,
                        })}
                    </span>
                    <PageLink
                        href={links.next}
                        label={t('admin.pagination.next')}
                        direction="next"
                    />
                </div>
            ) : null}
        </nav>
    );
}

function PageLink({
    href,
    label,
    direction,
}: {
    href: string | null;
    label: string;
    direction: 'previous' | 'next';
}) {
    const Icon = direction === 'previous' ? ChevronLeft : ChevronRight;

    if (href === null) {
        return (
            <Button variant="outline" size="sm" disabled>
                {direction === 'previous' && <Icon aria-hidden="true" />}
                {label}
                {direction === 'next' && <Icon aria-hidden="true" />}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link
                href={href}
                preserveScroll
                rel={direction === 'previous' ? 'prev' : 'next'}
            >
                {direction === 'previous' && <Icon aria-hidden="true" />}
                {label}
                {direction === 'next' && <Icon aria-hidden="true" />}
            </Link>
        </Button>
    );
}
