import { CalendarRange, OctagonAlert, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { LOAD_LEVELS, loadLevel } from '@/components/charts/thresholds';
import type { WorkloadRow, WorkloadTotals } from '@/components/workload/types';
import { formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Carga sin capacidad (p. ej. vencidas que caen hoy en un festivo): también es sobrecarga. */
export function isOverloaded({ planned, capacity }: WorkloadTotals): boolean {
    return capacity <= 0
        ? planned > 0
        : loadLevel(planned, capacity) === 'over';
}

function describe(row: WorkloadRow): string {
    if (row.total.capacity <= 0) {
        return t('workload_page.person_no_capacity', {
            name: row.name,
            planned: formatMinutes(row.total.planned),
        });
    }

    return t('workload_page.person_ratio', {
        name: row.name,
        percent: formatPercent(row.total.planned / row.total.capacity, 0),
        planned: formatMinutes(row.total.planned),
        capacity: formatMinutes(row.total.capacity),
    });
}

/**
 * La pregunta principal (SPEC §9): quién va sobrecargado en el horizonte. Por el total de cada
 * persona (más del 120 % o carga sin capacidad; del 100 % al 120 %) y, aunque el total cuadre, quién
 * tiene días (o semanas, en el horizonte de 3 meses) sobrecargados. Siempre con icono y texto, nunca solo color.
 */
export function WorkloadAlerts({
    rows,
    byWeek = false,
}: {
    rows: WorkloadRow[];
    /** Horizonte de 3 meses: las columnas son semanas. */
    byWeek?: boolean;
}) {
    const over = rows.filter((row) => isOverloaded(row.total));
    const high = rows.filter(
        (row) =>
            row.total.capacity > 0 &&
            loadLevel(row.total.planned, row.total.capacity) === 'high',
    );
    const flagged = new Set([...over, ...high].map((row) => row.id));
    const peaks = rows
        .filter((row) => !flagged.has(row.id))
        .map((row) => ({
            row,
            count: row.cells.filter((cell) => isOverloaded(cell)).length,
        }))
        .filter((item) => item.count > 0);

    if (over.length === 0 && high.length === 0 && peaks.length === 0) {
        const Icon = LOAD_LEVELS.balanced.icon;

        return (
            <p
                className="flex items-center gap-1.5 text-sm"
                data-test="workload-alerts"
            >
                <Icon
                    aria-hidden="true"
                    className="size-4 shrink-0 text-success"
                />
                {t('workload_page.nobody_over')}
            </p>
        );
    }

    return (
        <ul className="grid gap-1 text-sm" data-test="workload-alerts">
            <Alert
                icon={OctagonAlert}
                tone="text-danger"
                title={t('workload_page.over', { count: over.length })}
                items={over.map(describe)}
            />
            <Alert
                icon={TriangleAlert}
                tone="text-warning"
                title={t('workload_page.high', { count: high.length })}
                items={high.map(describe)}
            />
            <Alert
                icon={CalendarRange}
                tone="text-danger"
                title={t(
                    byWeek
                        ? 'workload_page.peaks_weeks'
                        : 'workload_page.peaks',
                    { count: peaks.length },
                )}
                items={peaks.map(({ row, count }) =>
                    t(
                        byWeek
                            ? 'workload_page.peak_weeks'
                            : 'workload_page.peak_days',
                        { name: row.name, count },
                    ),
                )}
            />
        </ul>
    );
}

function Alert({
    icon: Icon,
    tone,
    title,
    items,
}: {
    icon: LucideIcon;
    tone: string;
    title: string;
    items: string[];
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <li className="flex items-start gap-1.5">
            <Icon
                aria-hidden="true"
                className={cn('mt-0.5 size-4 shrink-0', tone)}
            />
            <span>
                <span className="font-medium">{title}</span> {items.join(' · ')}
            </span>
        </li>
    );
}
