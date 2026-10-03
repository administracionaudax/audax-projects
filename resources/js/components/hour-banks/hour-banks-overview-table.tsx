import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { hourBankFigures } from '@/components/charts/thresholds';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { bankDates } from '@/components/hour-banks/hour-bank-card';
import { HourBankMiniMeter } from '@/components/hour-banks/hour-bank-mini-meter';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { HourBankCard } from '@/types';

/**
 * Tabla de la vista global de bolsas (SPEC §8): de más a menos consumida, con la barra, las
 * cifras, el exceso en rojo con icono, las horas comprometidas (y su aviso), las fechas y el
 * proyecto. En móvil se desplaza dentro de su contenedor.
 */
export function HourBanksOverviewTable({
    banks,
    thresholds,
}: {
    banks: HourBankCard[];
    /** Umbrales configurados en % (config.hour_bank_thresholds; D-035). */
    thresholds?: readonly number[];
}) {
    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={t('hour_banks.overview.table_label')}
            tabIndex={0}
        >
            <table className="w-full min-w-[64rem] text-sm">
                <caption className="sr-only">
                    {t('hour_banks.overview.table_caption')}
                </caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.overview.bank')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.overview.client')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.overview.department')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.overview.status')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.overview.consumption')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('hour_banks.overview.overage')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('hour_banks.overview.committed')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('hour_banks.overview.dates')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {banks.map((bank) => {
                        const figures = hourBankFigures(
                            bank.consumed_minutes,
                            bank.total_minutes,
                            bank.committed_minutes,
                            bank.overage_minutes,
                        );
                        const shortfall = figures.shortfall;

                        return (
                            <tr
                                key={bank.id}
                                className="border-b last:border-0 even:bg-muted"
                            >
                                <th
                                    scope="row"
                                    className="px-3 py-2 text-left font-normal"
                                >
                                    <Link
                                        href={urls.hourBank(
                                            bank.project_id,
                                            bank.id,
                                        )}
                                        className={cn(
                                            'rounded-md text-primary-text hover:underline',
                                            FOCUS_RING,
                                        )}
                                    >
                                        {bank.name}
                                    </Link>
                                    {bank.project ? (
                                        <span className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <span
                                                aria-hidden="true"
                                                className="size-2 shrink-0 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        bank.project.color,
                                                }}
                                            />
                                            {bank.project.code} ·{' '}
                                            {bank.project.name}
                                        </span>
                                    ) : null}
                                </th>
                                <td className="px-3 py-2">
                                    {bank.project?.client?.name ?? (
                                        <span className="text-muted-foreground">
                                            {t('projects.table.no_client')}
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {bank.department?.name ?? (
                                        <span className="text-muted-foreground">
                                            {t(
                                                'hour_banks.card.any_department',
                                            )}
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2">
                                    <HourBankStatusBadge status={bank.status} />
                                </td>
                                <td className="px-3 py-2">
                                    <HourBankMiniMeter
                                        name={bank.name}
                                        consumed={bank.consumed_minutes}
                                        total={bank.total_minutes}
                                        overage={bank.overage_minutes}
                                        thresholds={thresholds}
                                    />
                                    <span className="tabular mt-1 block text-xs text-muted-foreground">
                                        {t('hour_banks.overview.figures', {
                                            consumed: formatMinutes(
                                                bank.consumed_minutes,
                                            ),
                                            total: formatMinutes(
                                                bank.total_minutes,
                                            ),
                                            remaining: formatMinutes(
                                                bank.remaining_minutes,
                                            ),
                                        })}
                                    </span>
                                </td>
                                <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                    {bank.overage_minutes > 0 ? (
                                        <span className="inline-flex items-center gap-1 font-medium text-danger">
                                            <TriangleAlert
                                                aria-hidden="true"
                                                className="size-3.5"
                                            />
                                            +
                                            {formatMinutes(
                                                bank.overage_minutes,
                                            )}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            0:00
                                        </span>
                                    )}
                                </td>
                                <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                    {formatMinutes(bank.committed_minutes)}
                                    {shortfall > 0 &&
                                    (figures.overage === 0 ||
                                        figures.remaining > 0) ? (
                                        <span className="mt-0.5 flex items-center justify-end gap-1 text-xs text-foreground">
                                            <TriangleAlert
                                                aria-hidden="true"
                                                className="size-3.5 text-warning"
                                            />
                                            {t(
                                                'hour_banks.overview.shortfall',
                                                {
                                                    minutes:
                                                        formatMinutes(
                                                            shortfall,
                                                        ),
                                                },
                                            )}
                                        </span>
                                    ) : null}
                                </td>
                                <td className="px-3 py-2 text-xs whitespace-nowrap">
                                    {bankDates(bank)}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
