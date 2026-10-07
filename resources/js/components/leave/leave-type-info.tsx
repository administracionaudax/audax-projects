import { CircleAlert, Info, ShieldCheck, TriangleAlert } from 'lucide-react';
import { formatLeaveAmount } from '@/lib/leave';
import { t } from '@/lib/i18n';
import type { LeaveSimulation, LeaveTypeOption } from '@/types/leave';

/**
 * Lo que hay que saber de un tipo de ausencia al pedirlo (Fase 11, R3): cuánto da, si se paga, si
 * pide justificante o preaviso, si es un dato de salud y su base legal.
 */
export function LeaveTypeInfo({
    id,
    type,
}: {
    id: string;
    type: LeaveTypeOption;
}) {
    const facts: string[] = [];

    if (type.default_amount !== null) {
        facts.push(
            type.travel_extra
                ? t('leave.info.amount_travel', {
                      amount: formatLeaveAmount(type.default_amount, type.unit),
                      extra: formatLeaveAmount(type.travel_extra, type.unit),
                  })
                : t('leave.info.amount', {
                      amount: formatLeaveAmount(type.default_amount, type.unit),
                  }),
        );
    }
    facts.push(t(type.paid ? 'leave.info.paid' : 'leave.info.unpaid'));
    if (type.requires_document) {
        facts.push(t('leave.info.document'));
    }
    if (type.notice_days) {
        facts.push(t('leave.info.notice', { days: type.notice_days }));
    }

    return (
        <div id={id} className="grid gap-1 text-sm text-muted-foreground">
            {type.description ? <p>{type.description}</p> : null}
            <p>{facts.join(' · ')}</p>
            {type.health_data ? (
                <p className="flex items-center gap-1.5">
                    <ShieldCheck
                        aria-hidden="true"
                        className="size-4 shrink-0"
                    />
                    {t('leave.info.health')}
                </p>
            ) : null}
            {type.legal_basis ? (
                <p className="text-xs">{type.legal_basis}</p>
            ) : null}
        </div>
    );
}

/** El resumen de la simulación: cuánto cuesta, el saldo que queda, los avisos y los errores. */
export function SimulationSummary({
    simulation,
}: {
    simulation: LeaveSimulation;
}) {
    const errors = Object.values(simulation.errors).flat();

    if (simulation.cost === null && errors.length === 0) {
        return null;
    }

    return (
        <div
            className="grid gap-1.5 rounded-md border p-3 text-sm"
            aria-live="polite"
            data-test="leave-simulation"
        >
            {simulation.cost ? (
                <p className="flex flex-wrap items-center gap-1.5">
                    <Info
                        aria-hidden="true"
                        className="size-4 shrink-0 text-info"
                    />
                    <span>
                        {t('leave.simulation.cost', {
                            amount: simulation.cost.label,
                        })}
                        {simulation.balance
                            ? ` · ${t('leave.simulation.after', {
                                  amount: simulation.balance.after_label,
                              })}`
                            : ''}
                    </span>
                </p>
            ) : null}
            {errors.map((error) => (
                <p key={error} className="flex gap-1.5">
                    <CircleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-danger"
                    />
                    <span>{error}</span>
                </p>
            ))}
            {simulation.warnings.map((warning) => (
                <p key={warning.code} className="flex gap-1.5">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    <span>{warning.message}</span>
                </p>
            ))}
        </div>
    );
}
