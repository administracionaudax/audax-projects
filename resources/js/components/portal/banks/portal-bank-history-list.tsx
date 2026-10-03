import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show } from '@/routes/portal/banks';
import { bankDates, consumedOf, projectLabel } from './format';
import type { PortalBank } from './types';

/**
 * Bolsas anteriores del cliente (cerradas y renovadas, D-064: forman el histórico): nombre con
 * enlace a su detalle, proyecto, vigencia, lo consumido de lo contratado, el exceso en rojo con
 * icono y el estado. En filas que se adaptan al móvil (sin tabla ancha).
 */
export function PortalBankHistoryList({ banks }: { banks: PortalBank[] }) {
    return (
        <ul className="grid gap-2" data-test="portal-bank-history">
            {banks.map((bank) => (
                <li
                    key={bank.id}
                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md border bg-card px-4 py-3"
                >
                    <div className="grid min-w-0 gap-0.5">
                        <Link
                            href={show(bank.id)}
                            className={cn(
                                'w-fit max-w-full rounded-md break-words text-primary-text hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {bank.name}
                        </Link>
                        <p className="text-xs break-words text-muted-foreground">
                            {projectLabel(bank.project)} · {bankDates(bank)}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span className="tabular">
                            {consumedOf(bank.figures)}
                        </span>
                        {bank.figures.overage_minutes > 0 ? (
                            <span className="inline-flex items-center gap-1 font-medium text-danger">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                                {t('portal_banks.overage', {
                                    minutes: formatMinutes(
                                        bank.figures.overage_minutes,
                                    ),
                                })}
                            </span>
                        ) : null}
                        <HourBankStatusBadge status={bank.status} />
                    </div>
                </li>
            ))}
        </ul>
    );
}
