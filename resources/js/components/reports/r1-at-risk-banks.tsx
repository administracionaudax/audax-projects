import { Link, usePage } from '@inertiajs/react';
import { CircleCheck, Wallet } from 'lucide-react';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import { EmptyState } from '@/components/empty-state';
import type { R1AtRisk } from '@/components/reports/r1-types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';

/**
 * Bolsas en riesgo (SPEC §10.1): las abiertas desde el primer umbral de consumo, de más a menos
 * consumida, con su barra (colores por umbral, exceso en rojo con icono) y enlace a su detalle.
 */
export function R1AtRiskBanks({ atRisk }: { atRisk: R1AtRisk }) {
    const thresholds = usePage().props.config?.hour_bank_thresholds;

    if (atRisk.count === 0) {
        return (
            <EmptyState
                icon={CircleCheck}
                title={t('reports_r1.at_risk.empty', {
                    threshold: atRisk.threshold,
                })}
            />
        );
    }

    return (
        <div className="grid gap-3">
            <ul className="grid gap-3 md:grid-cols-2" data-test="r1-at-risk">
                {atRisk.banks.map((bank) => (
                    <li
                        key={bank.id}
                        className="grid gap-3 rounded-md border bg-card p-4"
                    >
                        <div className="min-w-0">
                            <Link
                                href={urls.hourBank(bank.project.id, bank.id)}
                                className={cn(
                                    'inline-flex max-w-full items-center gap-2 rounded-sm hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                <Wallet
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-muted-foreground"
                                />
                                <span className="truncate">{bank.name}</span>
                            </Link>
                            <p className="truncate text-xs text-muted-foreground">
                                <span
                                    aria-hidden="true"
                                    className="mr-1.5 inline-block size-2 rounded-full align-middle"
                                    style={{
                                        backgroundColor: bank.project.color,
                                    }}
                                />
                                {bank.project.code} · {bank.project.name}
                                {bank.client ? ` · ${bank.client}` : ''}
                            </p>
                        </div>
                        <HourBankMeter
                            name={bank.name}
                            consumed={bank.consumed_minutes}
                            total={bank.total_minutes}
                            overage={bank.overage_minutes}
                            committed={bank.committed_minutes}
                            thresholds={thresholds}
                        />
                    </li>
                ))}
            </ul>
            {atRisk.count > atRisk.banks.length ? (
                <p className="text-sm text-muted-foreground">
                    {t('reports_r1.at_risk.more', {
                        shown: atRisk.banks.length,
                        count: atRisk.count,
                    })}{' '}
                    <Link
                        href={`${urls.hourBanks()}?proximas=1`}
                        className={cn(
                            'rounded-sm text-primary-text hover:underline',
                            FOCUS_RING,
                        )}
                    >
                        {t('reports_r1.at_risk.see_all')}
                    </Link>
                </p>
            ) : null}
        </div>
    );
}
