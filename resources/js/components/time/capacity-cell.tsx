import { LOAD_LEVELS, loadLevel } from '@/components/charts/thresholds';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Horas imputadas frente a la capacidad con el semáforo de carga (SPEC §7 y §9): icono de nivel,
 * «imputadas / capacidad» y el nivel en texto para lectores de pantalla. Nunca solo color.
 */
export function CapacityCell({
    logged,
    capacity,
    className,
    compact = false,
}: {
    logged: number;
    capacity: number;
    className?: string;
    /** Sin fondo: para filas de totales densas. */
    compact?: boolean;
}) {
    const level =
        capacity <= 0 && logged > 0 ? 'over' : loadLevel(logged, capacity);
    const meta = LOAD_LEVELS[level];
    const Icon = meta.icon;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md text-xs text-foreground',
                !compact && ['px-1.5 py-0.5', meta.surface],
                className,
            )}
        >
            <Icon
                aria-hidden="true"
                className={cn('size-3.5 shrink-0', meta.tone)}
            />
            <span className="tabular">
                {capacity > 0
                    ? t('hours.capacity.value', {
                          logged: formatMinutes(logged),
                          capacity: formatMinutes(capacity),
                      })
                    : formatMinutes(logged)}
            </span>
            <span className="sr-only">
                {capacity > 0
                    ? `(${meta.label})`
                    : t('hours.capacity.no_capacity')}
            </span>
        </span>
    );
}
