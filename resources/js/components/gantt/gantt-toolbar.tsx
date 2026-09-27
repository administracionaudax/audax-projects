import { CalendarCheck, ChartGantt, Table2 } from 'lucide-react';
import { useId } from 'react';
import type { GanttColorMode, GanttScale } from '@/components/gantt/types';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { t } from '@/lib/i18n';

export type GanttViewMode = 'chart' | 'table';

const SCALES: GanttScale[] = ['day', 'week', 'month'];
const COLORS: GanttColorMode[] = ['status', 'assignee'];

/**
 * Controles del Gantt: escala (día, semana o mes), colores (por estado o por responsable), «Ir a
 * hoy» y la vista (diagrama o tabla accesible). Controles segmentados con etiqueta.
 */
export function GanttToolbar({
    scale,
    color,
    view,
    onScaleChange,
    onColorChange,
    onViewChange,
    onToday,
}: {
    scale: GanttScale;
    color: GanttColorMode;
    view: GanttViewMode;
    onScaleChange: (scale: GanttScale) => void;
    onColorChange: (color: GanttColorMode) => void;
    onViewChange: (view: GanttViewMode) => void;
    onToday: () => void;
}) {
    const scaleId = useId();
    const colorId = useId();
    const viewId = useId();

    return (
        <div className="flex flex-wrap items-end gap-x-4 gap-y-3">
            <div className="grid gap-1">
                <span id={viewId} className="text-xs text-muted-foreground">
                    {t('gantt.toolbar.view')}
                </span>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={view}
                    onValueChange={(next) => {
                        if (next === 'chart' || next === 'table') {
                            onViewChange(next);
                        }
                    }}
                    aria-labelledby={viewId}
                >
                    <ToggleGroupItem
                        value="chart"
                        className="gap-1.5 px-3"
                        data-test="gantt-view-chart"
                    >
                        <ChartGantt aria-hidden="true" />
                        {t('gantt.toolbar.chart')}
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="table"
                        className="gap-1.5 px-3"
                        data-test="gantt-view-table"
                    >
                        <Table2 aria-hidden="true" />
                        {t('gantt.toolbar.table')}
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            <div className="grid gap-1">
                <span id={scaleId} className="text-xs text-muted-foreground">
                    {t('gantt.toolbar.scale')}
                </span>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={scale}
                    onValueChange={(next) => {
                        const found = SCALES.find((item) => item === next);

                        if (found) {
                            onScaleChange(found);
                        }
                    }}
                    aria-labelledby={scaleId}
                >
                    {SCALES.map((item) => (
                        <ToggleGroupItem
                            key={item}
                            value={item}
                            className="px-3"
                            data-test={`gantt-scale-${item}`}
                        >
                            {t(`gantt.scale.${item}`)}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </div>

            <div className="grid gap-1">
                <span id={colorId} className="text-xs text-muted-foreground">
                    {t('gantt.toolbar.color')}
                </span>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={color}
                    onValueChange={(next) => {
                        const found = COLORS.find((item) => item === next);

                        if (found) {
                            onColorChange(found);
                        }
                    }}
                    aria-labelledby={colorId}
                >
                    {COLORS.map((item) => (
                        <ToggleGroupItem
                            key={item}
                            value={item}
                            className="px-3"
                            data-test={`gantt-color-${item}`}
                        >
                            {t(`gantt.color.${item}`)}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </div>

            {view === 'chart' ? (
                <Button type="button" variant="outline" onClick={onToday}>
                    <CalendarCheck aria-hidden="true" />
                    {t('gantt.toolbar.today')}
                </Button>
            ) : null}
        </div>
    );
}
