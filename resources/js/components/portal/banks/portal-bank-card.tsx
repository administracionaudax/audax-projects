import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useId } from 'react';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show } from '@/routes/portal/banks';
import { bankDates, projectLabel } from './format';
import type { PortalBank } from './types';

/**
 * Tarjeta de una bolsa en el inicio del portal (SPEC §11): nombre (enlace al detalle), proyecto,
 * estado con icono y texto, barra con lo que va dentro y el exceso por separado (HourBankMeter con
 * las cifras de PortalBankFigures), consumido, restante, % y fechas. Sin horas comprometidas: son
 * planificación interna.
 */
export function PortalBankCard({
    bank,
    thresholds,
}: {
    bank: PortalBank;
    thresholds?: readonly number[];
}) {
    const titleId = useId();
    const { figures } = bank;

    return (
        <article
            aria-labelledby={titleId}
            className="grid min-w-0 content-start gap-4 rounded-md border bg-card p-4 sm:p-5"
            data-test="portal-bank-card"
        >
            <header className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                <div className="min-w-0 space-y-1">
                    <h3
                        id={titleId}
                        className="text-lg font-normal break-words"
                    >
                        <Link
                            href={show(bank.id)}
                            className={cn(
                                'rounded-md hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {bank.name}
                        </Link>
                    </h3>
                    <p className="text-sm break-words text-muted-foreground">
                        {projectLabel(bank.project)}
                    </p>
                </div>
                <HourBankStatusBadge status={bank.status} />
            </header>

            <HourBankMeter
                name={bank.name}
                consumed={figures.within_minutes + figures.overage_minutes}
                total={figures.total_minutes}
                overage={figures.overage_minutes}
                thresholds={thresholds}
                showCommitted={false}
            />

            <footer className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-sm">
                <span className="text-muted-foreground">{bankDates(bank)}</span>
                <Link
                    href={show(bank.id)}
                    aria-label={t('portal_banks.card.detail_label', {
                        name: bank.name,
                    })}
                    className={cn(
                        'inline-flex items-center gap-1 rounded-md text-primary-text hover:underline',
                        FOCUS_RING,
                    )}
                >
                    {t('portal_banks.card.detail')}
                    <ArrowRight aria-hidden="true" className="size-4" />
                </Link>
            </footer>
        </article>
    );
}
