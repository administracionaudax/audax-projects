import type { LucideIcon } from 'lucide-react';
import { CircleAlert, CircleCheck, Clock } from 'lucide-react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';

export type CollectionKey = 'vencido' | 'por-vencer' | 'cobrado';

export type CollectionBarData = Record<
    CollectionKey,
    { amount: string; count: number }
>;

/**
 * Tramos de la barra: el color es el de estado (vencido en rojo, cobrado en verde) y lo que está por
 * vencer, en el azul de los datos; siempre con su icono y su texto, nunca solo color.
 */
const SEGMENTS: {
    key: CollectionKey;
    icon: LucideIcon;
    fill: string;
    iconClass: string;
}[] = [
    {
        key: 'vencido',
        icon: CircleAlert,
        fill: 'var(--danger)',
        iconClass: 'text-danger',
    },
    {
        key: 'por-vencer',
        icon: Clock,
        fill: 'var(--chart-1)',
        iconClass: 'text-muted-foreground',
    },
    {
        key: 'cobrado',
        icon: CircleCheck,
        fill: 'var(--success)',
        iconClass: 'text-success',
    },
];

/**
 * Barra de importes del listado de facturas (D-406, como la de QuickBooks): lo vencido, lo que está
 * por vencer y lo cobrado de la vista, con IVA y rotulado una vez. Arriba, la raya con la parte de
 * cada tramo (hueco de 2 px entre tramos); debajo, un botón por tramo con su importe y sus facturas,
 * que filtra el listado (y lo quita si ya está elegido).
 */
export function CollectionBar({
    data,
    selected,
    onSelect,
}: {
    data: CollectionBarData;
    selected: CollectionKey | null;
    onSelect: (key: CollectionKey | null) => void;
}) {
    const amounts = SEGMENTS.map((segment) =>
        Math.max(0, Number(data[segment.key].amount)),
    );
    const sum = amounts.reduce((total, value) => total + value, 0);

    return (
        <section
            aria-labelledby="collection-bar-title"
            className="grid gap-2"
            data-test="collection-bar"
        >
            <h2
                id="collection-bar-title"
                className="text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase"
            >
                {t('billing.bar.title')}
            </h2>
            <div aria-hidden="true" className="flex h-2 gap-[2px] bg-card">
                {sum === 0 ? (
                    <span className="flex-1 bg-muted" />
                ) : (
                    SEGMENTS.map((segment, index) =>
                        amounts[index] > 0 ? (
                            <span
                                key={segment.key}
                                style={{
                                    flexGrow: amounts[index] / sum,
                                    flexBasis: 0,
                                    minWidth: 4,
                                    backgroundColor: segment.fill,
                                }}
                            />
                        ) : null,
                    )
                )}
            </div>
            <ul className="grid gap-1 sm:grid-cols-3 sm:gap-2">
                {SEGMENTS.map((segment) => {
                    const part = data[segment.key];
                    const active = selected === segment.key;
                    const empty = part.count === 0;

                    return (
                        <li key={segment.key} className="min-w-0">
                            <button
                                type="button"
                                disabled={empty && !active}
                                aria-pressed={active}
                                onClick={() =>
                                    onSelect(active ? null : segment.key)
                                }
                                className={cn(
                                    'flex w-full items-center justify-between gap-x-3 gap-y-0.5 border bg-card px-3 py-1.5 text-left hover:bg-accent/60 disabled:cursor-default disabled:hover:bg-card sm:grid sm:py-2',
                                    active ? 'border-primary' : 'border-border',
                                    FOCUS_RING,
                                )}
                                data-test={`collection-${segment.key}`}
                            >
                                <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                    <span
                                        aria-hidden="true"
                                        className="size-2.5 shrink-0"
                                        style={{
                                            backgroundColor: empty
                                                ? 'var(--muted)'
                                                : segment.fill,
                                        }}
                                    />
                                    {t(`billing.bar.${segment.key}`)}
                                    <segment.icon
                                        aria-hidden="true"
                                        className={cn(
                                            'size-4 sm:ml-auto',
                                            empty
                                                ? 'text-muted-foreground'
                                                : segment.iconClass,
                                        )}
                                    />
                                </span>
                                <span className="flex flex-wrap items-baseline justify-end gap-x-2 sm:justify-between">
                                    <span className="tabular text-base text-foreground sm:text-lg">
                                        {formatCurrency(part.amount)}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {tCount(
                                            'billing.bar.invoices',
                                            part.count,
                                        )}
                                    </span>
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
