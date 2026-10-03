import { Link } from '@inertiajs/react';
import { ArrowRight, History } from 'lucide-react';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { bankDates } from '@/components/hour-banks/hour-bank-card';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { HourBankChainItem, HourBankHistoryProject } from '@/types';

/**
 * Histórico de renovaciones (SPEC §8.8): cada cadena, de la bolsa más antigua a la más reciente.
 * Las horas de cada bolsa se quedan en ella. En el de un cliente (`projects`), cada cadena dice
 * de qué proyecto es.
 */
export function HourBankHistory({
    chains,
    projects,
    emptyDescription = t('hour_banks.history.empty_description'),
}: {
    chains: HourBankChainItem[][];
    /** Proyectos de las cadenas: solo en el histórico de un cliente. */
    projects?: HourBankHistoryProject[];
    emptyDescription?: string;
}) {
    if (chains.length === 0) {
        return (
            <EmptyState
                icon={History}
                title={t('hour_banks.history.empty')}
                description={emptyDescription}
            />
        );
    }

    const byId = new Map(
        (projects ?? []).map((project) => [project.id, project]),
    );

    return (
        <ul className="grid gap-3">
            {chains.map((chain) => {
                const project = projects
                    ? byId.get(chain[0].project_id)
                    : undefined;

                return (
                    <li
                        key={chain[0].id}
                        className="grid gap-2 rounded-md border p-3"
                        data-test="renewal-chain"
                    >
                        {project ? (
                            <Link
                                href={urls.project(project.id)}
                                className={cn(
                                    'flex w-fit max-w-full min-w-0 items-center gap-2 rounded-md text-sm hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                <span
                                    aria-hidden="true"
                                    className="size-2.5 shrink-0 rounded-full"
                                    style={{ backgroundColor: project.color }}
                                />
                                <span className="tabular shrink-0 text-muted-foreground">
                                    {project.code}
                                </span>
                                <span className="truncate">{project.name}</span>
                            </Link>
                        ) : null}
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
                                    <span className="grid gap-1 rounded-md bg-muted px-2.5 py-1.5">
                                        <Link
                                            href={urls.hourBank(
                                                bank.project_id,
                                                bank.id,
                                            )}
                                            className={cn(
                                                'rounded-md text-sm text-primary-text hover:underline',
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
                );
            })}
        </ul>
    );
}
