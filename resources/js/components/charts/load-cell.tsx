import {
    formatLoadPercent,
    LOAD_LEVELS,
    loadLevel,
} from '@/components/charts/thresholds';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type LoadCellProps = {
    /** Minutos planificados en el día o la semana. */
    planned: number;
    /** Capacidad en minutos (0 = vacaciones, festivo o día no laborable). */
    capacity: number;
    /** Motivo de la capacidad 0 ("Vacaciones", "Festivo"…). */
    reason?: string;
    className?: string;
};

/**
 * Celda del semáforo de carga (SPEC §9). Nunca solo color: icono de estado,
 * cifras "planificado / capacidad" y porcentaje, más la etiqueta del nivel para lectores de pantalla.
 * El nivel sale del mismo porcentaje redondeado que se enseña (loadPercent).
 */
export function LoadCell({
    planned,
    capacity,
    reason,
    className,
}: LoadCellProps) {
    const level = loadLevel(planned, capacity);
    const meta = LOAD_LEVELS[level];
    const Icon = meta.icon;

    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-0.5 rounded-md px-2 py-1.5 text-foreground',
                meta.surface,
                className,
            )}
        >
            <span className="flex items-center gap-1 text-xs">
                <Icon
                    aria-hidden="true"
                    className={cn('size-3.5 shrink-0', meta.tone)}
                />
                {level === 'none' ? (
                    <span className="truncate">{reason ?? meta.label}</span>
                ) : (
                    <>
                        <span className="tabular font-medium">
                            {formatLoadPercent(planned, capacity)}
                        </span>
                        <span className="sr-only">{meta.label}</span>
                    </>
                )}
            </span>
            <span className="tabular truncate text-xs text-muted-foreground">
                {level === 'none'
                    ? t('load.planned', { minutes: formatMinutes(planned) })
                    : `${formatMinutes(planned)} / ${formatMinutes(capacity)}`}
            </span>
        </div>
    );
}
