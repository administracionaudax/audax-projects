import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    CalendarClock,
    CalendarOff,
    CircleCheck,
    CircleGauge,
    TriangleAlert,
} from 'lucide-react';
import type {
    R1Member,
    R1OccupancyThresholds,
} from '@/components/reports/r1-types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type OccupancyLevel = 'none' | 'upcoming' | 'low' | 'ok' | 'high';

/**
 * Nivel de ocupación frente a los umbrales configurados (D-047: 70 % y 110 % por defecto): por
 * debajo del bajo, «baja»; por encima del alto, «alta»; sin capacidad, «sin jornada», o «aún sin
 * días» si tiene jornada en el periodo pero todavía no ha pasado ninguno de esos días (hasta ayer):
 * el primer día del periodo, o en uno futuro, nadie sale «baja». La ocupación es la del SPEC §10
 * (contra la capacidad del periodo).
 */
export function occupancyLevel(
    occupancy: number | null,
    thresholds: R1OccupancyThresholds,
    upcoming = false,
): OccupancyLevel {
    if (upcoming) {
        return 'upcoming';
    }

    if (occupancy === null) {
        return 'none';
    }

    // Los ratios llegan con 4 decimales: se pasan a % redondeando para que 1,1 × 100 (que en
    // coma flotante es 110,00000000000001) cuente como el 110 %, dentro del umbral.
    const percent = Math.round(occupancy * 10000) / 100;

    if (percent < thresholds.low) {
        return 'low';
    }

    return percent > thresholds.high ? 'high' : 'ok';
}

const LEVELS: Record<
    OccupancyLevel,
    { icon: LucideIcon; tone: string; label: TranslationKey }
> = {
    none: {
        icon: CalendarOff,
        tone: 'text-muted-foreground',
        label: 'reports_r1.occupancy.none',
    },
    upcoming: {
        icon: CalendarClock,
        tone: 'text-muted-foreground',
        label: 'reports_r1.occupancy.upcoming',
    },
    low: {
        icon: CircleGauge,
        tone: 'text-info',
        label: 'reports_r1.occupancy.low',
    },
    ok: {
        icon: CircleCheck,
        tone: 'text-success',
        label: 'reports_r1.occupancy.ok',
    },
    high: {
        icon: TriangleAlert,
        tone: 'text-warning',
        label: 'reports_r1.occupancy.high',
    },
};

/** Ocupación con su estado: icono y texto, nunca solo color. */
export function OccupancyStatus({
    occupancy,
    thresholds,
    upcoming = false,
}: {
    occupancy: number | null;
    thresholds: R1OccupancyThresholds;
    upcoming?: boolean;
}) {
    const level = LEVELS[occupancyLevel(occupancy, thresholds, upcoming)];
    const Icon = level.icon;

    return (
        <span className="inline-flex items-center justify-end gap-1.5 whitespace-nowrap">
            <Icon aria-hidden="true" className={cn('size-3.5', level.tone)} />
            <span className="tabular">
                {occupancy === null ? '—' : formatPercent(occupancy)}
            </span>
            <span className="text-xs text-muted-foreground">
                {t(level.label)}
            </span>
        </span>
    );
}

function percent(ratio: number | null): string {
    return ratio === null ? '—' : formatPercent(ratio);
}

/**
 * Ocupación y facturabilidad de cada miembro del departamento (SPEC §10.4), con enlace a su
 * informe personal; con datos económicos, además ingreso y rentabilidad. Se desplaza dentro de su
 * contenedor en móvil.
 */
export function R1MembersTable({
    members,
    thresholds,
    financials,
    personHref,
}: {
    members: R1Member[];
    thresholds: R1OccupancyThresholds;
    financials: boolean;
    personHref: (member: R1Member) => string;
}) {
    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={t('reports_r1.department.members_table')}
            tabIndex={0}
        >
            <table
                className="w-full min-w-[44rem] text-sm"
                data-test="r1-members"
            >
                <caption className="sr-only">
                    {t('reports_r1.department.members_table')}
                </caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('reports_r1.columns.person')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.capacity')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.logged')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.billable')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.occupancy')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.billability')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('reports_r1.columns.billable_productivity')}
                        </th>
                        {financials ? (
                            <>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {t('reports_r1.columns.income')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {t('reports_r1.columns.margin')}
                                </th>
                            </>
                        ) : null}
                    </tr>
                </thead>
                <tbody>
                    {members.map((member) => (
                        <tr key={member.id} className="border-b last:border-0">
                            <th
                                scope="row"
                                className="px-3 py-1.5 text-left font-normal"
                            >
                                <Link
                                    href={personHref(member)}
                                    className={cn(
                                        'rounded-sm hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {member.name}
                                </Link>
                                {member.is_active ? null : (
                                    <span className="ml-2 text-xs text-muted-foreground">
                                        {t('reports_r1.inactive')}
                                    </span>
                                )}
                            </th>
                            <td className="tabular px-3 py-1.5 text-right">
                                {formatMinutes(member.capacity_minutes)}
                                {member.capacity_to_date_minutes <
                                member.capacity_minutes ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {t('reports_r1.department.to_date', {
                                            capacity: formatMinutes(
                                                member.capacity_to_date_minutes,
                                            ),
                                        })}
                                    </span>
                                ) : null}
                            </td>
                            <td className="tabular px-3 py-1.5 text-right">
                                {formatMinutes(member.logged_minutes)}
                            </td>
                            <td className="tabular px-3 py-1.5 text-right">
                                {formatMinutes(member.billable_minutes)}
                            </td>
                            <td className="px-3 py-1.5 text-right">
                                <OccupancyStatus
                                    occupancy={member.occupancy}
                                    thresholds={thresholds}
                                    upcoming={
                                        member.capacity_minutes > 0 &&
                                        member.capacity_to_date_minutes === 0
                                    }
                                />
                            </td>
                            <td className="tabular px-3 py-1.5 text-right">
                                {percent(member.billability)}
                            </td>
                            <td className="tabular px-3 py-1.5 text-right">
                                {percent(member.billable_productivity)}
                            </td>
                            {financials ? (
                                <>
                                    <td className="tabular px-3 py-1.5 text-right">
                                        {formatCurrency(member.income)}
                                    </td>
                                    <td className="tabular px-3 py-1.5 text-right">
                                        {formatCurrency(member.margin)}
                                    </td>
                                </>
                            ) : null}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
