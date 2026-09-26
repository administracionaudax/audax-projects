import { Link } from '@inertiajs/react';
import { Ban, CalendarDays, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { HourBank, HourBankCard as HourBankCardData } from '@/types';

/** «Del 01/09/2026 al 31/12/2026», «Desde el 01/09/2026». */
export function bankDates(bank: Pick<HourBank, 'start_date' | 'end_date'>) {
    return bank.end_date
        ? t('hour_banks.card.dates_range', {
              from: formatDate(bank.start_date),
              to: formatDate(bank.end_date),
          })
        : t('hour_banks.card.dates_from', {
              from: formatDate(bank.start_date),
          });
}

/**
 * Tarjeta de bolsa (SPEC §8, UI): barra de consumo con colores por umbral, consumidas /
 * totales / restantes / exceso, horas comprometidas y su aviso, departamento, fechas, estado,
 * política efectiva y las acciones que se puedan hacer.
 */
export function HourBankCard({
    projectId,
    bank,
    actions,
    headingLevel = 'h2',
    thresholds,
}: {
    projectId: number;
    bank: HourBankCardData;
    actions?: ReactNode;
    headingLevel?: 'h2' | 'h3';
    /** Umbrales configurados en % (config.hour_bank_thresholds; D-035). */
    thresholds?: readonly number[];
}) {
    const Heading = headingLevel;
    const closed = bank.status === 'closed' || bank.status === 'renewed';

    return (
        <article
            className={cn(
                'grid content-start gap-4 rounded-md border bg-card p-5',
                closed && 'bg-muted/60',
            )}
            data-test="hour-bank-card"
        >
            <header className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <Heading className="text-base font-medium">
                        <Link
                            href={urls.hourBank(projectId, bank.id)}
                            className={cn(
                                'rounded-[3px] text-primary-text hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {bank.name}
                        </Link>
                    </Heading>
                    <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                        <span className="inline-flex items-center gap-1">
                            <Users aria-hidden="true" className="size-3.5" />
                            {bank.department?.name ??
                                t('hour_banks.card.any_department')}
                        </span>
                        <span className="inline-flex items-center gap-1">
                            <CalendarDays
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {bankDates(bank)}
                        </span>
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

            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                {bank.effective_overage_policy === 'block' ? (
                    <Ban aria-hidden="true" className="size-3.5" />
                ) : null}
                {t(`hour_banks.card.policy_${bank.effective_overage_policy}`)}
            </p>

            {bank.status === 'closed' &&
            bank.closed_remaining_minutes !== null ? (
                <p className="text-sm text-muted-foreground">
                    {t('hour_banks.card.closed_remaining', {
                        remaining: formatMinutes(bank.closed_remaining_minutes),
                        date: formatDate(bank.closed_at),
                    })}
                </p>
            ) : null}

            {bank.renewed_from ? (
                <p className="text-sm text-muted-foreground">
                    {t('hour_banks.card.renewed_from')}{' '}
                    <Link
                        href={urls.hourBank(projectId, bank.renewed_from.id)}
                        className={cn(
                            'rounded-[3px] text-primary-text hover:underline',
                            FOCUS_RING,
                        )}
                    >
                        {bank.renewed_from.name}
                    </Link>
                </p>
            ) : null}

            {actions ? <footer>{actions}</footer> : null}
        </article>
    );
}
