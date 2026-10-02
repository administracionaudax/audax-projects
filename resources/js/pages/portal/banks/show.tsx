import { Head, setLayoutProps } from '@inertiajs/react';
import { ChartColumn, Download } from 'lucide-react';
import { useId } from 'react';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { bankDates, projectLabel } from '@/components/portal/banks/format';
import { PortalBankEntries } from '@/components/portal/banks/portal-bank-entries';
import { PortalBankMonthlyChart } from '@/components/portal/banks/portal-bank-monthly-chart';
import { PortalBankRenewals } from '@/components/portal/banks/portal-bank-renewals';
import { PortalVisibilityNote } from '@/components/portal/banks/portal-visibility-note';
import type { PortalBankShowProps } from '@/components/portal/banks/types';
import { PageSection } from '@/components/projects-list/page-section';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { home } from '@/routes/portal';
import { pdf, show } from '@/routes/portal/banks';

/**
 * Detalle de una bolsa en el portal (SPEC §11, D-064): cifras y barra (dentro y exceso por
 * separado), estado con icono y texto, fechas, consumo por mes con su tabla accesible, las horas
 * que ve el cliente (por meses y paginadas), el histórico de renovaciones y el PDF de consumo en
 * modo portal (D-066). Sin importes, tarifas ni costes.
 */
export default function PortalBankShow({
    bank,
    visibility,
    thresholds,
    months,
    entries,
    filters,
    history,
}: PortalBankShowProps) {
    const figuresId = useId();
    const { figures } = bank;

    setLayoutProps({
        breadcrumbs: [
            { title: t('portal_banks.detail.home'), href: home() },
            { title: bank.name, href: show(bank.id) },
        ],
    });

    return (
        <>
            <Head
                title={t('portal_banks.detail.title', {
                    bank: bank.name,
                    project: bank.project.name,
                })}
            />

            <div className="grid gap-8">
                <header className="grid gap-3">
                    <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                        <div className="min-w-0 space-y-1">
                            <p className="text-sm break-words text-muted-foreground">
                                {projectLabel(bank.project)}
                            </p>
                            <h1 className="text-3xl font-normal tracking-tight break-words">
                                {bank.name}
                            </h1>
                            <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                <span
                                    className="inline-flex"
                                    data-test="portal-bank-status"
                                >
                                    <HourBankStatusBadge status={bank.status} />
                                </span>
                                <span>{bankDates(bank)}</span>
                                {bank.status === 'closed' && bank.closed_at ? (
                                    <span>
                                        {t('portal_banks.dates.closed', {
                                            date: formatDate(bank.closed_at),
                                        })}
                                    </span>
                                ) : null}
                            </p>
                        </div>
                        <Button asChild>
                            <a
                                href={pdf.url(bank.id)}
                                download
                                aria-label={t('portal_banks.detail.pdf_label', {
                                    name: bank.name,
                                })}
                                data-test="portal-bank-pdf"
                            >
                                <Download aria-hidden="true" />
                                {t('portal_banks.detail.pdf')}
                            </a>
                        </Button>
                    </div>
                    <PortalVisibilityNote visibility={visibility} />
                </header>

                <section
                    aria-labelledby={figuresId}
                    className="rounded-md border bg-card p-4 sm:p-5"
                >
                    <h2 id={figuresId} className="sr-only">
                        {t('portal_banks.detail.figures')}
                    </h2>
                    <HourBankMeter
                        name={bank.name}
                        consumed={
                            figures.within_minutes + figures.overage_minutes
                        }
                        total={figures.total_minutes}
                        overage={figures.overage_minutes}
                        thresholds={thresholds}
                        showCommitted={false}
                    />
                </section>

                {months.length === 0 ? (
                    <PageSection title={t('portal_banks.monthly.title')}>
                        <EmptyState
                            icon={ChartColumn}
                            title={t('portal_banks.monthly.empty')}
                            description={t(
                                'portal_banks.monthly.empty_description',
                            )}
                        />
                    </PageSection>
                ) : (
                    // La gráfica ya lleva su título (figcaption): sin h2 repetido.
                    <section aria-label={t('portal_banks.monthly.title')}>
                        <PortalBankMonthlyChart months={months} />
                    </section>
                )}

                <PageSection
                    title={t('portal_banks.entries.title')}
                    description={t('portal_banks.entries.description')}
                >
                    <PortalBankEntries
                        bankId={bank.id}
                        entries={entries}
                        months={months}
                        month={filters.mes}
                    />
                </PageSection>

                <PageSection
                    title={t('portal_banks.history.title')}
                    description={t('portal_banks.history.description')}
                >
                    <PortalBankRenewals chain={history} name={bank.name} />
                </PageSection>
            </div>
        </>
    );
}

PortalBankShow.layout = {
    breadcrumbs: [{ title: t('portal_banks.detail.home'), href: home() }],
};
