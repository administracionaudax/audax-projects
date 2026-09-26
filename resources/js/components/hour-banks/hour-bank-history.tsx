import { Link } from '@inertiajs/react';
import { ArrowRight, History } from 'lucide-react';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { bankDates } from '@/components/hour-banks/hour-bank-card';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { HourBankChainItem } from '@/types';

/**
 * Histórico de renovaciones del proyecto (SPEC §8.8): cada cadena, de la bolsa más antigua a
 * la más reciente. Las horas de cada bolsa se quedan en ella.
 */
export function HourBankHistory({
    projectId,
    chains,
}: {
    projectId: number;
    chains: HourBankChainItem[][];
}) {
    if (chains.length === 0) {
        return (
            <EmptyState
                icon={History}
                title={t('hour_banks.history.empty')}
                description={t('hour_banks.history.empty_description')}
            />
        );
    }

    return (
        <ul className="grid gap-3">
            {chains.map((chain) => (
                <li
                    key={chain[0].id}
                    className="rounded-md border p-3"
                    data-test="renewal-chain"
                >
                    <ol
                        aria-label={t('hour_banks.history.chain_label', {
                            name: chain[0].name,
                        })}
                        className="flex flex-wrap items-center gap-2"
                    >
                        {chain.map((bank, index) => (
                            <li
                                key={bank.id}
                                className="flex items-center gap-2"
                            >
                                {index > 0 ? (
                                    <ArrowRight
                                        aria-hidden="true"
                                        className="size-4 text-muted-foreground"
                                    />
                                ) : null}
                                <span className="grid gap-1 rounded-[3px] bg-muted px-2.5 py-1.5">
                                    <Link
                                        href={urls.hourBank(projectId, bank.id)}
                                        className={cn(
                                            'rounded-[3px] text-sm text-primary-text hover:underline',
                                            FOCUS_RING,
                                        )}
                                    >
                                        {bank.name}
                                    </Link>
                                    <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <HourBankStatusBadge
                                            status={bank.status}
                                        />
                                        {bankDates(bank)}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ol>
                </li>
            ))}
        </ul>
    );
}
