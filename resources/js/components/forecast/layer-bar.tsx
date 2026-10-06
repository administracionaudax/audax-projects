import { LAYER_FILL } from '@/components/forecast/layer-swatch';
import { LAYERS } from '@/lib/forecast';
import type { LayerToggles } from '@/lib/forecast';
import { cn } from '@/lib/utils';
import type { LoadLayers } from '@/types/forecast';

/** La pista de la barra va del 0 al 150 % de la capacidad (D-291 §3.3). */
export const BAR_TRACK = 1.5;

/**
 * Barra fina de capas de una celda (6 px): real, seguro y posible uno detrás de otro, con 2 px de
 * separación, sobre una pista del 0 al 150 % de la capacidad y con la marca del 100 %. Si pasa del
 * 150 %, un triángulo al final (la cifra exacta va en el texto y en el tooltip).
 */
export function LayerBar({
    minutes,
    capacity,
    layers,
    className,
}: {
    minutes: LoadLayers;
    capacity: number;
    layers: LayerToggles;
    className?: string;
}) {
    if (capacity <= 0) {
        return null;
    }

    const total = LAYERS.reduce(
        (sum, layer) => sum + (layers[layer] ? minutes[layer] : 0),
        0,
    );

    return (
        <span
            aria-hidden="true"
            data-test="layer-bar"
            className={cn('relative block h-1.5', className)}
        >
            <span className="flex h-full gap-0.5 overflow-hidden bg-card/75">
                {LAYERS.filter(
                    (layer) => layers[layer] && minutes[layer] > 0,
                ).map((layer) => (
                    <span
                        key={layer}
                        data-layer={layer}
                        className={cn('block h-full shrink-0', LAYER_FILL[layer])}
                        style={{
                            width: `${Math.min((minutes[layer] / capacity / BAR_TRACK) * 100, 100)}%`,
                        }}
                    />
                ))}
            </span>
            <span
                className="absolute -top-[3px] h-3 w-0.5 bg-foreground"
                style={{ left: `${100 / BAR_TRACK}%` }}
            />
            {total > capacity * BAR_TRACK ? (
                <span
                    data-test="layer-bar-more"
                    className="absolute -top-px -right-px size-0 border-y-4 border-l-[5px] border-y-transparent border-l-foreground"
                />
            ) : null}
        </span>
    );
}
