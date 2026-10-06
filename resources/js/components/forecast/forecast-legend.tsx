import { CalendarMinus } from 'lucide-react';
import { LOAD_LEVELS } from '@/components/charts/thresholds';
import type { LoadLevel } from '@/components/charts/thresholds';
import { LayerSwatch } from '@/components/forecast/layer-swatch';
import { anyLayer, LAYERS } from '@/lib/forecast';
import type { LayerToggles } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const LEVELS: LoadLevel[] = ['under', 'balanced', 'high', 'over', 'none'];

/**
 * Leyenda de la matriz (D-291 y D-292): las capas (con la trama de lo posible), la capacidad, el
 * hueco y el semáforo con sus tramos (en móvil, sin los tramos), los festivos de la cabecera y la
 * muesca de la ausencia parcial. Sin capas encendidas, lo dice.
 */
export function ForecastLegend({
    layers,
    className,
}: {
    layers: LayerToggles;
    className?: string;
}) {
    return (
        <section
            aria-label={t('forecast.legend.label')}
            className={cn('grid gap-2 text-xs text-muted-foreground', className)}
        >
            <ul className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                <li className="font-medium text-foreground">{t('forecast.legend.layers')}</li>
                {LAYERS.map((layer) => (
                    <li key={layer} className={cn('inline-flex items-center gap-1.5', !layers[layer] && 'line-through opacity-60')}>
                        <LayerSwatch layer={layer} />
                        {t(`forecast.legend.layer.${layer}`)}
                    </li>
                ))}
                <li className="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" className="h-0.5 w-4 bg-foreground" />
                    {t('forecast.legend.capacity')}
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" className="size-2.5 border border-dashed border-muted-foreground" />
                    {t('forecast.legend.gap')}
                </li>
                {anyLayer(layers) ? null : (
                    <li className="font-medium text-foreground" role="status">
                        {t('forecast.legend.no_layers')}
                    </li>
                )}
            </ul>
            <ul className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                <li className="font-medium text-foreground">{t('forecast.legend.occupancy')}</li>
                {LEVELS.map((level) => {
                    const meta = LOAD_LEVELS[level];
                    const Icon = meta.icon;

                    return (
                        <li key={level} className="inline-flex items-center gap-1.5">
                            <span className={cn('inline-flex size-5 items-center justify-center', meta.surface)}>
                                <Icon aria-hidden="true" className={cn('size-3', meta.tone)} />
                            </span>
                            <span>
                                <span className="text-foreground">{meta.label}</span>
                                <span className="hidden sm:inline">: {t(`forecast.legend.range.${level}`)}</span>
                            </span>
                        </li>
                    );
                })}
                <li className="inline-flex items-center gap-1.5">
                    <CalendarMinus aria-hidden="true" className="size-3" />
                    {t('forecast.legend.holiday')}
                </li>
                <li className="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" className="size-0 border-t-[9px] border-l-[9px] border-t-muted-foreground border-l-transparent" />
                    {t('forecast.legend.partial_absence')}
                </li>
            </ul>
        </section>
    );
}
