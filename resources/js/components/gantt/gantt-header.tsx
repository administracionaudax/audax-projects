import {
    dayCenterX,
    HEADER_TIER_HEIGHT,
    headerCells,
} from '@/components/gantt/geometry';
import type { Timeline } from '@/components/gantt/geometry';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Cabecera de tiempo (pegajosa arriba) en dos niveles: meses y días, meses y semanas, o años y
 * meses. Es decorativa para los lectores de pantalla (aria-hidden): las fechas van en el nombre
 * accesible de cada barra y en la tabla alternativa.
 */
export function GanttHeader({
    timeline,
    today,
}: {
    timeline: Timeline;
    today: string;
}) {
    const { top, bottom } = headerCells(timeline);
    const showToday = today >= timeline.start && today <= timeline.end;
    const todayX = dayCenterX(timeline, today);

    return (
        <div
            aria-hidden="true"
            className="relative select-none"
            style={{ width: timeline.width, height: HEADER_TIER_HEIGHT * 2 }}
        >
            {top.map((cell) => (
                <div
                    key={cell.key}
                    title={cell.title}
                    className="absolute top-0 flex items-center overflow-clip border-r border-b px-2 text-xs font-medium whitespace-nowrap text-foreground"
                    style={{
                        left: cell.x,
                        width: cell.width,
                        height: HEADER_TIER_HEIGHT,
                    }}
                >
                    <span className="sticky left-[calc(var(--gantt-sidebar)+0.5rem)] first-letter:uppercase">
                        {cell.label}
                    </span>
                </div>
            ))}
            {bottom.map((cell) => (
                <div
                    key={cell.key}
                    title={cell.title}
                    className={cn(
                        'absolute flex items-center justify-center overflow-hidden border-r border-b text-[11px] whitespace-nowrap text-muted-foreground',
                        cell.weekend && 'bg-muted',
                    )}
                    style={{
                        top: HEADER_TIER_HEIGHT,
                        left: cell.x,
                        width: cell.width,
                        height: HEADER_TIER_HEIGHT,
                    }}
                >
                    {cell.label}
                </div>
            ))}
            {showToday ? (
                <span
                    className="absolute z-10 -translate-x-1/2 rounded-md bg-primary px-1 text-[10px] leading-4 text-primary-foreground"
                    style={{ left: todayX, top: HEADER_TIER_HEIGHT + 4 }}
                >
                    {t('gantt.today')}
                </span>
            ) : null}
        </div>
    );
}
