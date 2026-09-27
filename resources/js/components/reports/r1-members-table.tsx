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
 * Nivel de una ocupación (o un ritmo) frente a los umbrales configurados (D-047: 70 % y 110 % por
 * defecto): por debajo del bajo, «baja»; por encima del alto, «alta»; sin capacidad, «sin
 * jornada», o «aún sin datos» (upcoming) si todavía no se puede medir.
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

/**
 * Nivel de un miembro (D-080). Periodo cerrado: su ocupación (SPEC §10). Periodo en curso (aún le
 * quedan días con jornada): su ritmo, lo imputado frente a la capacidad transcurrida hasta ayer;
 * contra el periodo entero, a mitad de mes todo el mundo saldría «baja». Si aún no ha pasado
 * ningún día con jornada (el primer día, o un periodo futuro), sin nivel: «aún sin datos».
 */
export function memberOccupancyLevel(
    member: Pick<
        R1Member,
        'occupancy' | 'pace' | 'capacity_minutes' | 'capacity_to_date_minutes'
    >,
    thresholds: R1OccupancyThresholds,
): OccupancyLevel {
    if (member.capacity_to_date_minutes >= member.capacity_minutes) {
        return occupancyLevel(member.occupancy, thresholds);
    }

    if (member.capacity_to_date_minutes === 0 || member.pace === null) {
        return 'upcoming';
    }

    return occupancyLevel(member.pace, thresholds);
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

/**
 * Ocupación (la del SPEC, contra el periodo entero) con el estado de su nivel: icono y texto,
 * nunca solo color. En un periodo en curso, debajo, el ritmo que da ese nivel (D-080).
 */
export function OccupancyStatus({
    occupancy,
    level,
    pace = null,
}: {
    occupancy: number | null;
    level: OccupancyLevel;
    pace?: number | null;
}) {
    const { icon: Icon, tone, label } = LEVELS[level];

    return (
        <span className="inline-flex flex-col items-end">
            <span className="inline-flex items-center justify-end gap-1.5 whitespace-nowrap">
                <Icon aria-hidden="true" className={cn('size-3.5', tone)} />
                <span className="tabular">
                    {occupancy === null ? '—' : formatPercent(occupancy)}
                </span>
                <span className="text-xs text-muted-foreground">
                    {t(label)}
                </span>
            </span>
            {pace === null ? null : (
                <span className="tabular text-xs text-muted-foreground">
                    {t('reports_r1.occupancy.pace', {
                        pace: formatPercent(pace),
                    })}
                </span>
            )}
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
                                    level={memberOccupancyLevel(
                                        member,
                                        thresholds,
                                    )}
                                    pace={
                                        member.capacity_to_date_minutes <
                                        member.capacity_minutes
                                            ? member.pace
                                            : null
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
