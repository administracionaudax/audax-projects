import { Link } from '@inertiajs/react';
import { ArrowRight, FileDown, History, Wallet } from 'lucide-react';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { bankDates } from '@/components/hour-banks/hour-bank-card';
import { R2OverageValue } from '@/components/reports/r2-breakdown-table';
import type { R2ClientBank } from '@/components/reports/r2-types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { hourBankPdf } from '@/routes/reports';

/**
 * Bolsas de un cliente en su informe (SPEC §10.2): cada una con su barra de consumo (dentro y
 * exceso por separado, como en la ficha), lo imputado en el periodo del informe y la descarga
 * del PDF de consumo para el cliente (D-045). Quien ve este informe puede descargarlo: admins y
 * responsables ven el detalle de cualquier bolsa y un gestor solo ve sus proyectos.
 */
export function R2BankList({
    banks,
    thresholds,
}: {
    banks: ReadonlyArray<R2ClientBank>;
    thresholds?: readonly number[];
}) {
    if (banks.length === 0) {
        return (
            <EmptyState
                icon={Wallet}
                title={t('reports_r2.banks.empty')}
                description={t('reports_r2.banks.empty_description')}
            />
        );
    }

    return (
        <ul className="grid gap-4 lg:grid-cols-2">
            {banks.map((bank) => (
                <li
                    key={bank.id}
                    className="grid min-w-0 content-start gap-4 rounded-md border bg-card p-4"
                >
                    <header className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0 space-y-1">
                            <h3 className="text-base font-normal break-words">
                                <Link
                                    href={urls.hourBank(
                                        bank.project.id,
                                        bank.id,
                                    )}
                                    className={cn(
                                        'rounded-[3px] text-primary-text hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {bank.name}
                                </Link>
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                <span className="tabular">
                                    {bank.project.code}
                                </span>{' '}
                                · {bank.project.name} · {bankDates(bank)}
                            </p>
                        </div>
                        <HourBankStatusBadge status={bank.status} />
                    </header>

                    <HourBankMeter
                        name={bank.name}
                        consumed={bank.consumed_minutes}
                        total={bank.total_minutes}
                        overage={bank.overage_minutes}
                        committed={bank.committed_minutes}
                        thresholds={thresholds}
                    />

                    <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3 text-sm">
                        <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="text-muted-foreground">
                                {t('reports_r2.banks.period')}
                            </span>
                            <span className="tabular">
                                {t('reports_r2.banks.period_in', {
                                    hours: formatMinutes(
                                        bank.period_in_bank_minutes,
                                    ),
                                })}
                            </span>
                            {bank.period_overage_minutes > 0 ? (
                                <R2OverageValue
                                    minutes={bank.period_overage_minutes}
                                />
                            ) : null}
                        </p>
                        <a
                            href={hourBankPdf.url({
                                project: bank.project.id,
                                hourBank: bank.id,
                            })}
                            download
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-[3px] text-primary-text hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            <FileDown aria-hidden="true" className="size-4" />
                            {t('reports_r2.banks.pdf', { name: bank.name })}
                        </a>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/**
 * Histórico de renovaciones del cliente (SPEC §8.8): cada cadena de la bolsa más antigua a la
 * más reciente, con el consumo final de cada una (dentro y exceso). Las horas nunca se mueven
 * entre bolsas, así que cada una conserva las suyas.
 */
export function R2RenewalHistory({
    chains,
}: {
    chains: ReadonlyArray<ReadonlyArray<R2ClientBank>>;
}) {
    if (chains.length === 0) {
        return (
            <EmptyState
                icon={History}
                title={t('reports_r2.history.empty')}
                description={t('reports_r2.history.empty_description')}
            />
        );
    }

    return (
        <ul className="grid gap-3">
            {chains.map((chain) => (
                <li
                    key={chain[0].id}
                    className="grid gap-2 rounded-md border p-3"
                >
                    <p className="text-sm text-muted-foreground">
                        <span className="tabular">{chain[0].project.code}</span>{' '}
                        · {chain[0].project.name}
                    </p>
                    <ol
                        aria-label={t('reports_r2.history.chain_label', {
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
                                <span className="grid gap-1 rounded-[3px] bg-muted px-2.5 py-1.5 text-sm">
                                    <Link
                                        href={urls.hourBank(
                                            bank.project.id,
                                            bank.id,
                                        )}
                                        className={cn(
                                            'rounded-[3px] text-primary-text hover:underline',
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
                                    <span className="tabular flex flex-wrap items-center gap-2 text-xs">
                                        {t('reports_r2.history.consumed', {
                                            consumed: formatMinutes(
                                                bank.consumed_minutes,
                                            ),
                                            total: formatMinutes(
                                                bank.total_minutes,
                                            ),
                                        })}
                                        {bank.overage_minutes > 0 ? (
                                            <R2OverageValue
                                                minutes={bank.overage_minutes}
                                            />
                                        ) : null}
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
