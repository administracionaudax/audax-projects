import { Link } from '@inertiajs/react';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { ClientHourBank } from '@/types';

/**
 * Bolsa activa o agotada en la ficha de cliente: consumo con la barra por umbrales configurados
 * (config.hour_bank_thresholds), exceso y horas comprometidas, y enlace a su detalle.
 */
export function ClientHourBankCard({
    bank,
    thresholds,
}: {
    bank: ClientHourBank;
    thresholds?: number[];
}) {
    return (
        <Card className="gap-4" data-test="client-hour-bank">
            <CardHeader className="gap-1">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <h3 className="min-w-0 text-base font-medium break-words">
                        {bank.project ? (
                            <Link
                                href={urls.hourBank(bank.project.id, bank.id)}
                                className={cn(
                                    'rounded-sm hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                {bank.name}
                            </Link>
                        ) : (
                            bank.name
                        )}
                    </h3>
                    <HourBankStatusBadge status={bank.status} />
                </div>
                <p className="text-sm text-muted-foreground">
                    {bank.project
                        ? `${bank.project.code} · ${bank.project.name}`
                        : null}
                    {bank.department ? ` · ${bank.department.name}` : ''}
                </p>
                <p className="text-xs text-muted-foreground">
                    {bank.end_date
                        ? t('clients.banks.period', {
                              from: formatDate(bank.start_date),
                              to: formatDate(bank.end_date),
                          })
                        : t('clients.banks.since', {
                              from: formatDate(bank.start_date),
                          })}
                </p>
            </CardHeader>
            <CardContent>
                <HourBankMeter
                    name={bank.name}
                    consumed={bank.consumed_minutes}
                    total={bank.total_minutes}
                    overage={bank.overage_minutes}
                    committed={bank.committed_minutes}
                    thresholds={thresholds}
                />
            </CardContent>
        </Card>
    );
}
