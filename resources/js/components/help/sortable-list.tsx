import {
    closestCenter,
    DndContext,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { Announcements, DragEndEvent } from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ArrowDown, ArrowUp, GripVertical } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { moveItem } from '@/lib/help-center';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Item = { id: number };

function SortableRow<T extends Item>({
    item,
    index,
    count,
    title,
    disabled,
    onMove,
    children,
}: {
    item: T;
    index: number;
    count: number;
    title: string;
    disabled: boolean;
    onMove: (from: number, to: number) => void;
    children: ReactNode;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id: item.id,
        disabled,
        attributes: { roleDescription: t('help.sortable.role') },
    });

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition,
            }}
            className={cn(
                'flex items-center gap-2 border bg-card px-2 py-2',
                isDragging && 'opacity-40',
            )}
            data-test="sortable-item"
        >
            <button
                type="button"
                ref={setActivatorNodeRef}
                {...attributes}
                {...listeners}
                disabled={disabled}
                aria-label={t('help.sortable.handle', { item: title })}
                className={cn(
                    'cursor-grab touch-none p-1 text-muted-foreground hover:text-foreground disabled:cursor-not-allowed',
                    FOCUS_RING,
                )}
            >
                <GripVertical aria-hidden="true" className="size-4" />
            </button>
            <div className="min-w-0 flex-1">{children}</div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8"
                disabled={disabled || index === 0}
                onClick={() => onMove(index, index - 1)}
                aria-label={t('help.sortable.up', { item: title })}
            >
                <ArrowUp aria-hidden="true" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8"
                disabled={disabled || index >= count - 1}
                onClick={() => onMove(index, index + 1)}
                aria-label={t('help.sortable.down', { item: title })}
            >
                <ArrowDown aria-hidden="true" />
            </Button>
        </li>
    );
}

/**
 * Lista que se reordena arrastrando el asa (ratón, dedo o teclado: espacio para coger, flechas para
 * mover, espacio para soltar y Escape para cancelar) o con los botones «Subir» y «Bajar». Avisa en
 * español a los lectores de pantalla. `onReorder` recibe la lista en su nuevo orden.
 */
export function SortableList<T extends Item>({
    items,
    label,
    titleOf,
    onReorder,
    disabled = false,
    children,
}: {
    items: T[];
    label: string;
    titleOf: (item: T) => string;
    onReorder: (items: T[]) => void;
    disabled?: boolean;
    children: (item: T) => ReactNode;
}) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );
    const byId = new Map(items.map((item) => [item.id, item]));
    const position = (id: unknown) =>
        items.findIndex((item) => item.id === id) + 1;
    const nameOf = (id: unknown) => {
        const item = byId.get(Number(id));

        return item ? titleOf(item) : '';
    };

    const announcements: Announcements = {
        onDragStart: ({ active }) =>
            t('help.sortable.announce.start', {
                item: nameOf(active.id),
                position: position(active.id),
                total: items.length,
            }),
        onDragOver: ({ active, over }) =>
            over
                ? t('help.sortable.announce.over', {
                      item: nameOf(active.id),
                      position: position(over.id),
                      total: items.length,
                  })
                : undefined,
        onDragEnd: ({ active, over }) =>
            over
                ? t('help.sortable.announce.end', {
                      item: nameOf(active.id),
                      position: position(over.id),
                      total: items.length,
                  })
                : t('help.sortable.announce.cancel', {
                      item: nameOf(active.id),
                  }),
        onDragCancel: ({ active }) =>
            t('help.sortable.announce.cancel', { item: nameOf(active.id) }),
    };

    const move = (from: number, to: number) => {
        const next = moveItem(items, from, to);

        if (next !== items) {
            onReorder(next);
        }
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) {
            return;
        }

        move(position(active.id) - 1, position(over.id) - 1);
    };

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={onDragEnd}
            accessibility={{
                announcements,
                screenReaderInstructions: {
                    draggable: t('help.sortable.instructions'),
                },
            }}
        >
            <SortableContext
                items={items.map((item) => item.id)}
                strategy={verticalListSortingStrategy}
            >
                <ul className="grid gap-2" aria-label={label}>
                    {items.map((item, index) => (
                        <SortableRow
                            key={item.id}
                            item={item}
                            index={index}
                            count={items.length}
                            title={titleOf(item)}
                            disabled={disabled}
                            onMove={move}
                        >
                            {children(item)}
                        </SortableRow>
                    ))}
                </ul>
            </SortableContext>
        </DndContext>
    );
}
