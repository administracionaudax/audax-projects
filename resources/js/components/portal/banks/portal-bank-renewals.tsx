import { Link } from '@inertiajs/react';
import { ArrowRight, History } from 'lucide-react';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show } from '@/routes/portal/banks';
import { bankDates, consumedOf } from './format';
import type { PortalBankChainItem } from './types';

/**
 * Histórico de renovaciones de una bolsa en el portal (SPEC §8.8 y §11): su cadena, de la más
 * antigua a la más reciente, solo con bolsas del cliente. Cada eslabón enlaza a su detalle, salvo
 * la bolsa que se está viendo (aria-current). Las horas de cada bolsa se quedan en ella.
 */
export function PortalBankRenewals({
    chain,
    name,
}: {
    chain: PortalBankChainItem[];
    /** Nombre de la bolsa que se está viendo (para el nombre accesible de la cadena). */
    name: string;
}) {
    if (chain.length === 0) {
        return (
            <EmptyState
                icon={History}
                title={t('portal_banks.history.empty')}
                description={t('portal_banks.history.empty_description')}
            />
        );
    }

    return (
        <ol
            aria-label={t('portal_banks.history.chain_label', { name })}
            className="flex flex-wrap items-center gap-2"
            data-test="portal-bank-renewals"
        >
            {chain.map((bank, index) => (
                <li
                    key={bank.id}
                    className="flex max-w-full items-center gap-2"
                >
                    {index > 0 ? (
                        <ArrowRight
                            aria-hidden="true"
                            className="size-4 shrink-0 text-muted-foreground"
                        />
                    ) : null}
                    <span
                        className={cn(
                            'grid min-w-0 gap-1 rounded-[3px] px-2.5 py-1.5',
                            bank.current
                                ? 'border border-foreground/40 bg-card'
                                : 'bg-muted',
                        )}
                    >
                        {bank.current ? (
                            <span
                                aria-current="page"
                                className="text-sm break-words"
                            >
                                {bank.name}
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {t('portal_banks.history.current')}
                                </span>
                            </span>
                        ) : (
                            <Link
                                href={show(bank.id)}
                                className={cn(
                                    'w-fit max-w-full rounded-[3px] text-sm break-words text-primary-text hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                {bank.name}
                            </Link>
                        )}
                        <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            <HourBankStatusBadge status={bank.status} />
                            {bankDates(bank)}
                        </span>
                        <span className="tabular text-xs">
                            {consumedOf(bank.figures)}
                        </span>
                    </span>
                </li>
            ))}
        </ol>
    );
}
