import {
    closestCenter,
    DndContext,
    KeyboardSensor,
    PointerSensor,
    TouchSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    Announcements,
    DragEndEvent,
    UniqueIdentifier,
} from '@dnd-kit/core';
import {
    arrayMove,
    rectSortingStrategy,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import type { LucideIcon } from 'lucide-react';
import { GripVertical } from 'lucide-react';
import { Children, Fragment, isValidElement, useRef } from 'react';
import type {
    PointerEvent as ReactPointerEvent,
    ReactElement,
    ReactNode,
} from 'react';
import type { HomeCardId } from '@/components/home/home-layout';
import { useHomeLayout } from '@/components/home/use-home-layout';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { usePrefersReducedMotion } from '@/hooks/use-reduced-motion';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Ratón y lápiz: el arrastre empieza al mover el asa unos píxeles. Los toques los lleva
 * TouchSensor, con retardo: si no, el primer sensor se queda el toque y deslizar el dedo sobre el
 * asa arrastraría la tarjeta en vez de desplazar la página.
 */
class MouseAndPenSensor extends PointerSensor {
    static activators = [
        {
            eventName: 'onPointerDown' as const,
            handler: ({ nativeEvent: event }: ReactPointerEvent) =>
                event.isPrimary &&
                event.button === 0 &&
                event.pointerType !== 'touch',
        },
    ];
}

type PanelCardProps = {
    id: HomeCardId;
    icon: LucideIcon;
    title: string;
    description?: string;
    wide?: boolean;
    children: ReactNode;
};

/** Controles del orden para la cabecera de Inicio («Restablecer orden»). */
export type HomeLayoutControls = {
    /** ¿Hay un orden guardado? Si no, las tarjetas van en su orden por defecto. */
    hasSaved: boolean;
    reset: () => void;
};

/**
 * Rejilla de tarjetas de Inicio que se reordena arrastrando su asa (D-138), con el ratón, con el
 * dedo (mantén pulsado) o con el teclado (espacio para cogerla, flechas para moverla, espacio para
 * soltarla y Escape para cancelar), con anuncios en español para lectores de pantalla.
 *
 * - `children`: las `PanelCard` que ve quien mira, en su orden por defecto (las que dependen del
 *   rol, como en D-134, simplemente no se pasan).
 * - `saved`: el orden guardado (prop `home_layout`). Se guarda al soltar, sin recargar la página.
 * - `header`: la cabecera de la página, con los controles del orden.
 */
export function HomePanels({
    saved,
    label,
    header,
    children,
}: {
    saved: string[] | null | undefined;
    label: string;
    header: (controls: HomeLayoutControls) => ReactNode;
    children: ReactNode;
}) {
    const cards = Children.toArray(children).filter(
        (child): child is ReactElement<PanelCardProps> =>
            isValidElement(child) && child.type === PanelCard,
    );
    const byId = new Map(cards.map((card) => [card.props.id, card]));
    const { order, hasSaved, move, reset } = useHomeLayout(
        cards.map((card) => card.props.id),
        saved,
    );

    const sensors = useSensors(
        useSensor(MouseAndPenSensor, {
            activationConstraint: { distance: 6 },
        }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 250, tolerance: 8 },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const titleOf = (id: UniqueIdentifier) =>
        byId.get(id as HomeCardId)?.props.title ?? '';
    const positionOf = (id: UniqueIdentifier | undefined) =>
        id === undefined ? 0 : order.indexOf(id as HomeCardId) + 1;

    // Al coger una tarjeta, dnd-kit avisa también de que está sobre sí misma: ese aviso taparía el
    // de «Has cogido…» en la región viva, así que se calla hasta que la tarjeta se mueve.
    const justPicked = useRef(false);

    const announcements: Announcements = {
        onDragStart: ({ active }) => {
            justPicked.current = true;

            return t('home_layout.announce.start', {
                card: titleOf(active.id),
                position: positionOf(active.id),
                total: order.length,
            });
        },
        onDragOver: ({ active, over }) => {
            const silent = justPicked.current && over?.id === active.id;
            justPicked.current = false;

            if (silent) {
                return undefined;
            }

            return over
                ? t('home_layout.announce.over', {
                      card: titleOf(active.id),
                      position: positionOf(over.id),
                      total: order.length,
                  })
                : t('home_layout.announce.outside', {
                      card: titleOf(active.id),
                  });
        },
        onDragEnd: ({ active, over }) =>
            over
                ? t('home_layout.announce.end', {
                      card: titleOf(active.id),
                      position: positionOf(over.id),
                      total: order.length,
                  })
                : t('home_layout.announce.cancel', {
                      card: titleOf(active.id),
                  }),
        onDragCancel: ({ active }) =>
            t('home_layout.announce.cancel', { card: titleOf(active.id) }),
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) {
            return;
        }

        const from = order.indexOf(active.id as HomeCardId);
        const to = order.indexOf(over.id as HomeCardId);

        if (from === -1 || to === -1) {
            return;
        }

        move(arrayMove(order, from, to));
    };

    return (
        <>
            {header({ hasSaved, reset })}
            <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={onDragEnd}
                accessibility={{
                    announcements,
                    screenReaderInstructions: {
                        draggable: t('home_layout.instructions'),
                    },
                }}
            >
                <SortableContext items={order} strategy={rectSortingStrategy}>
                    <section
                        aria-label={label}
                        className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4"
                        data-test="home-panels"
                    >
                        {order.map((id) => (
                            <Fragment key={id}>{byId.get(id)}</Fragment>
                        ))}
                    </section>
                </SortableContext>
            </DndContext>
        </>
    );
}

/**
 * Tarjeta de Inicio que se puede mover (dentro de `HomePanels`). El asa está en la cabecera: se ve
 * al pasar el ratón por la tarjeta o con el foco dentro, y siempre en pantallas táctiles. Mientras
 * se arrastra, solo cambia el borde (azul de marca): estilo plano de Audax (D-137).
 */
export function PanelCard({
    id,
    icon: Icon,
    title,
    description,
    wide = false,
    children,
}: PanelCardProps) {
    const reducedMotion = usePrefersReducedMotion();
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id,
        // Con «reducir movimiento», las tarjetas cambian de sitio sin animación.
        transition: reducedMotion ? null : undefined,
        attributes: { roleDescription: t('home_layout.role_description') },
    });

    return (
        <Card
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition: reducedMotion ? undefined : transition,
            }}
            className={cn(
                'group/panel gap-4',
                wide && 'md:col-span-2',
                isDragging && 'relative z-10 border-ring ring-1 ring-ring',
            )}
            data-test={`home-card-${id}`}
            data-home-card={id}
        >
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Icon
                        aria-hidden="true"
                        className="size-4 shrink-0 text-muted-foreground"
                        strokeWidth={1.5}
                    />
                    <h2 className="min-w-0 flex-1">{title}</h2>
                    <button
                        type="button"
                        ref={setActivatorNodeRef}
                        {...attributes}
                        {...listeners}
                        aria-label={t('home_layout.handle', { card: title })}
                        className={cn(
                            '-my-1 -mr-1 shrink-0 cursor-grab touch-manipulation p-1 text-muted-foreground opacity-0 group-focus-within/panel:opacity-100 group-hover/panel:opacity-100 hover:text-foreground focus-visible:opacity-100 active:cursor-grabbing pointer-coarse:opacity-100',
                            isDragging && 'cursor-grabbing opacity-100',
                            FOCUS_RING,
                        )}
                        data-test="home-card-handle"
                    >
                        <GripVertical aria-hidden="true" className="size-4" />
                    </button>
                </CardTitle>
                {description ? (
                    <CardDescription>{description}</CardDescription>
                ) : null}
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3">
                {children}
            </CardContent>
        </Card>
    );
}
