import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    History,
    RotateCcw,
    SearchX,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { AuditFilters } from '@/components/audit/audit-filters';
import { AuditTable } from '@/components/audit/audit-table';
import { EmptyState } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { index as adminIndex } from '@/routes/admin';
import { index as auditIndex } from '@/routes/admin/audit';
import type { AuditPageProps } from '@/types/audit';

/**
 * Carga y error de las visitas de esta página (filtros y paginación): se muestra «Cargando…» en
 * la tabla y, si la visita falla, un aviso con «Reintentar».
 */
function useVisitState(): { loading: boolean; failed: boolean } {
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const offStart = router.on('start', () => {
            setLoading(true);
            setFailed(false);
        });
        const offFinish = router.on('finish', () => setLoading(false));
        const offNetwork = router.on('networkError', () => setFailed(true));
        const offHttp = router.on('httpException', () => setFailed(true));

        return () => {
            offStart();
            offFinish();
            offNetwork();
            offHttp();
        };
    }, []);

    return { loading, failed };
}

/**
 * Auditoría visible (SPEC §14 y §15, D-074), solo para el admin: quién cambió qué, cuándo y el
 * antes y el después de cada campo, con filtros en la URL, paginación por cursor (de lo más
 * reciente a lo más antiguo) y exportación a CSV con los mismos filtros.
 */
export default function AdminAudit({
    entries,
    pagination,
    filters,
    options,
    exportUrl,
}: AuditPageProps) {
    const { loading, failed } = useVisitState();
    const filtered = Object.values(filters).some((value) => value !== null);

    return (
        <>
            <Head title={t('audit.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight">
                        <KeywordText text={t('audit.heading')} />
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('audit.description')}
                    </p>
                </header>

                <AuditFilters
                    filters={filters}
                    options={options}
                    exportUrl={exportUrl}
                />

                {failed ? (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center gap-3 rounded-md bg-danger-soft px-3 py-2 text-sm text-foreground"
                    >
                        <TriangleAlert
                            aria-hidden="true"
                            className="size-4 shrink-0 text-danger"
                        />
                        <span className="flex-1">{t('audit.error')}</span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => router.reload()}
                        >
                            <RotateCcw aria-hidden="true" />
                            {t('audit.retry')}
                        </Button>
                    </div>
                ) : null}

                <section
                    aria-label={t('audit.table.label')}
                    aria-busy={loading}
                    className="grid gap-3"
                >
                    <p
                        role="status"
                        className="flex min-h-5 items-center gap-2 text-sm text-muted-foreground"
                    >
                        {loading ? (
                            <>
                                <Spinner />
                                {t('audit.loading')}
                            </>
                        ) : entries.length > 0 ? (
                            t('audit.count', { count: entries.length })
                        ) : null}
                    </p>

                    {entries.length === 0 ? (
                        filtered ? (
                            <EmptyState
                                icon={SearchX}
                                title={t('audit.empty_filtered')}
                                description={t(
                                    'audit.empty_filtered_description',
                                )}
                            />
                        ) : (
                            <EmptyState
                                icon={History}
                                title={t('audit.empty')}
                                description={t('audit.empty_description')}
                            />
                        )
                    ) : (
                        <AuditTable entries={entries} />
                    )}

                    {pagination.prev || pagination.next ? (
                        <nav
                            aria-label={t('audit.pagination.label')}
                            className="flex flex-wrap items-center justify-between gap-3"
                        >
                            <PageLink
                                href={pagination.prev}
                                label={t('audit.pagination.newer')}
                                direction="previous"
                            />
                            <PageLink
                                href={pagination.next}
                                label={t('audit.pagination.older')}
                                direction="next"
                            />
                        </nav>
                    ) : null}
                </section>
            </div>
        </>
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
    const content = (
        <>
            {direction === 'previous' && <Icon aria-hidden="true" />}
            {label}
            {direction === 'next' && <Icon aria-hidden="true" />}
        </>
    );

    if (href === null) {
        return (
            <Button variant="outline" size="sm" disabled>
                {content}
            </Button>
        );
    }

    return (
        <Button asChild variant="outline" size="sm">
            <Link
                href={href}
                preserveScroll
                rel={direction === 'previous' ? 'prev' : 'next'}
            >
                {content}
            </Link>
        </Button>
    );
}

AdminAudit.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('audit.title'), href: auditIndex() },
    ],
};
