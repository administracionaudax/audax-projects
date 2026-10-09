import { Link } from '@inertiajs/react';
import { ArrowRightLeft } from 'lucide-react';
import { HelpTip } from '@/components/billing/help-tip';
import { PageSection } from '@/components/projects-list/page-section';
import { useIsMobile } from '@/hooks/use-mobile';
import { FOCUS_RING } from '@/lib/focus-ring';
import {
    formatCurrency,
    formatDate,
    formatMinutes,
    formatPercent,
} from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type { UnbilledDetail, UnbilledLine } from '@/types/billing-rules';

/** Qué es la línea: «Precio cerrado», «Exceso de bolsa», «Fee mensual»… */
export function unbilledLineKind(line: UnbilledLine): string {
    return t(`billing.unbilled.lines.kind.${line.source}`);
}

/**
 * El detalle de una línea en texto (D-432 y D-433): lo facturado del precio cerrado con sus horas y
 * su % consumido, cuándo se facturará el exceso (o a qué bolsa ha pasado), los meses de fee sin
 * factura y desde cuándo.
 */
export function unbilledLineDetail(line: UnbilledLine): string {
    const since = line.oldest
        ? t('billing.unbilled.lines.since', { date: formatDate(line.oldest) })
        : null;

    switch (line.source) {
        case 'fixed': {
            const fixed = line.fixed;
            if (fixed === null) {
                return since ?? '';
            }
            const hours =
                fixed.budget_minutes !== null && fixed.consumption_pct !== null
                    ? t('billing.unbilled.lines.fixed_budget', {
                          hours: formatMinutes(fixed.real_minutes),
                          budget: formatMinutes(fixed.budget_minutes),
                          pct: formatPercent(fixed.consumption_pct / 100),
                      })
                    : t('billing.unbilled.lines.fixed_hours', {
                          hours: formatMinutes(fixed.real_minutes),
                      });

            return [
                t('billing.unbilled.lines.fixed', {
                    invoiced: formatCurrency(fixed.invoiced),
                    price: formatCurrency(fixed.price),
                }),
                hours,
            ].join(' · ');
        }
        case 'overage':
            return [t('billing.unbilled.lines.overage_next'), since]
                .filter(Boolean)
                .join(' · ');
        case 'carried':
            return t('billing.unbilled.lines.carried', {
                bank: line.next_bank?.name ?? '',
            });
        case 'fees':
            return [
                tCount('billing.unbilled.lines.fees', line.months ?? 0),
                since,
            ]
                .filter(Boolean)
                .join(' · ');
        case 'hours':
            return [
                since,
                line.pending_minutes > 0
                    ? t('billing.unbilled.lines.pending', {
                          hours: formatMinutes(line.pending_minutes),
                      })
                    : null,
            ]
                .filter(Boolean)
                .join(' · ');
        default:
            return since ?? '';
    }
}

/** Las horas de la línea: las sin facturar (o traspasadas); en los importes fijos, nada. */
function lineHours(line: UnbilledLine): string {
    return ['hours', 'overage', 'carried'].includes(line.source)
        ? formatMinutes(line.minutes)
        : '—';
}

function lineAmount(line: UnbilledLine) {
    if (line.source === 'carried') {
        return (
            <span className="text-muted-foreground">
                {t('billing.unbilled.lines.carried_amount')}
            </span>
        );
    }

    return line.amount === null ? '—' : formatCurrency(line.amount);
}

function LineTarget({ line }: { line: UnbilledLine }) {
    return (
        <>
            <span className="block text-xs text-muted-foreground">
                {unbilledLineKind(line)}
            </span>
            <Link
                href={`/proyectos/${line.project.id}/facturacion`}
                className={cn('rounded-md hover:underline', FOCUS_RING)}
            >
                <span className="text-muted-foreground">
                    {line.project.code}
                </span>{' '}
                {line.bank ? line.bank.name : line.project.name}
            </Link>
        </>
    );
}

/**
 * «Por facturar» de un cliente, por línea (D-432 y D-433): horas sin facturar de cada proyecto por
 * horas, precios cerrados con lo que queda (y sus horas como contexto), fees, bolsas sin factura y
 * excesos de bolsa: el de la bolsa activa se facturará con la próxima; el de una renovada sale como
 * «Pasado a la bolsa siguiente», sin importe. Tabla en escritorio y tarjetas en el móvil (D-415).
 */
export function UnbilledLines({
    client,
    detail,
    from,
    to,
}: {
    client: string;
    detail: UnbilledDetail;
    from: string;
    to: string;
}) {
    const mobile = useIsMobile();
    const financials = detail.financials;
    const caption = t('billing.unbilled.lines.caption');

    return (
        <PageSection
            title={t('billing.unbilled.lines.title', { client })}
            description={t('billing.unbilled.lines.description', {
                from: formatDate(from),
                to: formatDate(to),
            })}
            action={
                <HelpTip topic={t('billing.unbilled.lines.title', { client })}>
                    <p>{t('billing.unbilled.help_fixed')}</p>
                </HelpTip>
            }
        >
            {mobile ? (
                <ul className="grid gap-2" data-test="unbilled-lines-cards">
                    {detail.lines.map((line, index) => (
                        <li
                            key={`${line.source}-${line.project.id}-${line.bank?.id ?? 0}-${index}`}
                            className="grid gap-1.5 rounded-md border bg-card p-3 text-sm"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <LineTarget line={line} />
                                </div>
                                <span className="tabular shrink-0 text-right">
                                    {financials
                                        ? lineAmount(line)
                                        : lineHours(line)}
                                </span>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {line.source === 'carried' ? (
                                    <ArrowRightLeft
                                        aria-hidden="true"
                                        className="mr-1 inline size-3.5"
                                    />
                                ) : null}
                                {unbilledLineDetail(line)}
                            </p>
                        </li>
                    ))}
                </ul>
            ) : (
                <div
                    className={cn(
                        'overflow-x-auto rounded-md border',
                        FOCUS_RING,
                    )}
                    role="region"
                    aria-label={caption}
                    tabIndex={0}
                >
                    <table
                        className="tabular w-full text-sm"
                        data-test="unbilled-lines"
                    >
                        <caption className="sr-only">{caption}</caption>
                        <thead>
                            <tr className="border-b">
                                <th
                                    scope="col"
                                    className="w-[32%] px-3 py-2 text-left"
                                >
                                    {t('billing.unbilled.lines.col.what')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-left">
                                    {t('billing.unbilled.lines.col.detail')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right whitespace-nowrap"
                                >
                                    {t('billing.unbilled.lines.col.hours')}
                                </th>
                                {financials ? (
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right whitespace-nowrap"
                                    >
                                        {t('billing.unbilled.lines.col.amount')}
                                    </th>
                                ) : null}
                            </tr>
                        </thead>
                        <tbody>
                            {detail.lines.map((line, index) => (
                                <tr
                                    key={`${line.source}-${line.project.id}-${line.bank?.id ?? 0}-${index}`}
                                    className="border-b last:border-0 even:bg-muted"
                                    data-test={`unbilled-line-${line.source}`}
                                >
                                    <th
                                        scope="row"
                                        className="px-3 py-2 text-left font-normal"
                                    >
                                        <LineTarget line={line} />
                                    </th>
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {line.source === 'carried' ? (
                                            <ArrowRightLeft
                                                aria-hidden="true"
                                                className="mr-1 inline size-3.5"
                                            />
                                        ) : null}
                                        {unbilledLineDetail(line)}
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap">
                                        {lineHours(line)}
                                    </td>
                                    {financials ? (
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            {lineAmount(line)}
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t">
                                <th
                                    scope="row"
                                    colSpan={2}
                                    className="px-3 py-2 text-left font-normal"
                                >
                                    {t('billing.unbilled.lines.total')}
                                </th>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {formatMinutes(detail.totals.minutes)}
                                </td>
                                {financials ? (
                                    <td
                                        className="px-3 py-2 text-right whitespace-nowrap"
                                        data-test="unbilled-lines-total"
                                    >
                                        {formatCurrency(
                                            detail.totals.amount ?? '0',
                                        )}
                                    </td>
                                ) : null}
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </PageSection>
    );
}
