import { Check } from 'lucide-react';
import { LayerSwatch } from '@/components/forecast/layer-swatch';
import { FOCUS_RING } from '@/lib/focus-ring';
import { LAYERS } from '@/lib/forecast';
import type { LayerToggles as Toggles } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LoadLayer } from '@/types/forecast';

/**
 * Las capas que cuentan (D-294): tres casillas con su muestra, que son a la vez leyenda y filtro.
 * Al desmarcar una, las cifras y los % se recalculan sin ella en la interfaz.
 */
export function LayerToggles({
    value,
    onChange,
    className,
}: {
    value: Toggles;
    onChange: (value: Toggles) => void;
    className?: string;
}) {
    const toggle = (layer: LoadLayer) =>
        onChange({ ...value, [layer]: !value[layer] });

    return (
        <div
            role="group"
            aria-label={t('forecast.layers.group')}
            className={cn('flex flex-wrap items-center gap-2', className)}
        >
            {LAYERS.map((layer) => (
                <button
                    key={layer}
                    type="button"
                    aria-pressed={value[layer]}
                    data-test={`layer-${layer}`}
                    onClick={() => toggle(layer)}
                    className={cn(
                        'inline-flex h-9 items-center gap-2 border border-input px-3 text-sm text-foreground hover:bg-muted',
                        FOCUS_RING,
                    )}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'flex size-4 shrink-0 items-center justify-center border',
                            value[layer]
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-input',
                        )}
                    >
                        {value[layer] ? <Check className="size-3" /> : null}
                    </span>
                    <LayerSwatch layer={layer} />
                    {t(`forecast.layers.${layer}`)}
                </button>
            ))}
        </div>
    );
}
