import { LayerSwatch } from '@/components/forecast/layer-swatch';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LoadLayer } from '@/types/forecast';

/** Etiqueta corta de la capa con su muestra: «■ Real», «■ Seguro», «▨ Posible» (D-291). */
export function LayerBadge({
    layer,
    className,
}: {
    layer: LoadLayer;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 text-xs whitespace-nowrap',
                className,
            )}
        >
            <LayerSwatch layer={layer} />
            {t(`forecast.badge.${layer}`)}
        </span>
    );
}
