import { Head } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { useId } from 'react';
import { EmptyState, HeroEmptyState } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import { PortalBankCard } from '@/components/portal/banks/portal-bank-card';
import { PortalBankHistoryList } from '@/components/portal/banks/portal-bank-history-list';
import { PortalBanksSummary } from '@/components/portal/banks/portal-banks-summary';
import { PortalVisibilityNote } from '@/components/portal/banks/portal-visibility-note';
import type { PortalHomeProps } from '@/components/portal/banks/types';
import { firstName, useRequiredUser } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';

/**
 * Inicio del portal de cliente (SPEC §11, D-064): el resumen (horas de este mes y bolsas cerca del
 * límite), las bolsas activas con su barra y sus cifras, y las bolsas anteriores. Solo con las horas
 * que ve el cliente, con una nota que lo explica. Sin bolsas, un estado vacío grande con el
 * degradado de marca (SPEC §3.1).
 */
export default function PortalHome({
    client,
    visibility,
    thresholds,
    summary,
    banks,
    history,
}: PortalHomeProps) {
    const user = useRequiredUser();
    const greeting = t('portal.greeting', { name: firstName(user) });
    const openId = useId();
    const historyId = useId();

    if (banks.length === 0 && history.length === 0) {
        return (
            <>
                <Head title={t('portal.title')} />
                <HeroEmptyState
                    icon={Wallet}
                    eyebrow={client.name}
                    title={greeting}
                    description={t('portal_banks.home.empty_description')}
                />
            </>
        );
    }

    return (
        <>
            <Head title={t('portal.title')} />

            <div className="grid gap-8">
                <header className="grid gap-3">
                    <div className="space-y-1">
                        <p className="text-sm text-muted-foreground">
                            {client.name}
                        </p>
                        <h1 className="text-3xl font-normal tracking-tight">
                            <KeywordText text={greeting} />
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('portal.subtitle')}
                        </p>
                    </div>
                    <PortalVisibilityNote visibility={visibility} />
                </header>

                <PortalBanksSummary summary={summary} />

                <section aria-labelledby={openId} className="grid gap-4">
                    <h2 id={openId} className="text-lg font-normal">
                        {t('portal_banks.home.open_title')}
                    </h2>
                    {banks.length > 0 ? (
                        <div className="grid gap-4 lg:grid-cols-2">
                            {banks.map((bank) => (
                                <PortalBankCard
                                    key={bank.id}
                                    bank={bank}
                                    thresholds={thresholds}
                                />
                            ))}
                        </div>
                    ) : (
                        <EmptyState
                            icon={Wallet}
                            title={t('portal_banks.home.open_empty')}
                            description={t(
                                'portal_banks.home.open_empty_description',
                            )}
                        />
                    )}
                </section>

                {history.length > 0 ? (
                    <section aria-labelledby={historyId} className="grid gap-4">
                        <div className="space-y-1">
                            <h2 id={historyId} className="text-lg font-normal">
                                {t('portal_banks.home.history_title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('portal_banks.home.history_description')}
                            </p>
                        </div>
                        <PortalBankHistoryList banks={history} />
                    </section>
                ) : null}
            </div>
        </>
    );
}
