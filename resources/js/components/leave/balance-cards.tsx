import { TriangleAlert } from 'lucide-react';
import { balanceParts } from '@/lib/leave';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LeaveBalance } from '@/types/leave';

/**
 * Los saldos de una persona, una tarjeta por tipo con saldo (vacaciones, fuerza mayor…), como el
 * resumen de Woffu: lo disponible en grande y debajo lo asignado, disfrutado, pendiente, arrastrado
 * con su caducidad y lo que caduca pronto. Un saldo negativo se marca con icono y texto.
 */
export function LeaveBalanceCards({
    balances,
    title,
    headingLevel = 2,
}: {
    balances: LeaveBalance[];
    title: string;
    headingLevel?: 2 | 3;
}) {
    if (balances.length === 0) {
        return null;
    }

    const Heading = headingLevel === 2 ? 'h2' : 'h3';

    return (
        <section aria-label={title} className="grid gap-3">
            <Heading className="text-lg">{title}</Heading>
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {balances.map((balance) => {
                    const parts = balanceParts(balance);

                    return (
                        <li
                            key={`${balance.type.id}-${balance.year}`}
                            className={cn(
                                'grid gap-1 rounded-md border p-4',
                                parts.negative && 'border-danger',
                            )}
                            data-test="leave-balance"
                        >
                            <p className="text-sm text-muted-foreground">
                                {balance.type.name}
                            </p>
                            <p
                                className="tabular flex items-center gap-1.5 text-xl"
                                data-test="leave-balance-available"
                            >
                                {parts.negative ? (
                                    <TriangleAlert
                                        aria-hidden="true"
                                        className="size-4 shrink-0 text-danger"
                                    />
                                ) : null}
                                {parts.headline}
                            </p>
                            {parts.negative ? (
                                <p className="text-sm">
                                    {t('leave.balance.negative')}
                                </p>
                            ) : null}
                            <ul className="grid gap-0.5 text-sm text-muted-foreground">
                                {parts.details.map((detail) => (
                                    <li key={detail}>{detail}</li>
                                ))}
                            </ul>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
