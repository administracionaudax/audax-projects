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
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { t } from '@/lib/i18n';
import { reorder } from '@/routes/day-plan';
import { DragHandle, MyLineRow } from './my-line-row';
import type { DayPlanLine, DayPlanTargets } from '@/types/day-plan';

type Props = {
    date: string;
    lines: DayPlanLine[];
    targets: DayPlanTargets | undefined;
    today: string;
    horizonEnd: string;
    canWrite: boolean;
    canClose: boolean;
    /** Temporizador y acciones de horas de cada línea (C3). */
    renderTimer?: (line: DayPlanLine) => ReactNode;
    renderActions?: (line: DayPlanLine) => ReactNode;
};

/**
 * Mis líneas de un día, en su orden. Se reordenan arrastrando el asa (ratón, dedo o teclado: espacio
 * para coger, flechas para mover, espacio para soltar) o con «Subir» y «Bajar» del menú ⋯.
 */
export function MyDayList({
    date,
    lines,
    targets,
    today,
    horizonEnd,
    canWrite,
    canClose,
    renderTimer,
    renderActions,
}: Props) {
    // Orden optimista mientras responde el servidor.
    const [order, setOrder] = useState<number[] | null>(null);
    const ids = lines.map((line) => line.id);
    const current =
        order &&
        order.length === ids.length &&
        order.every((id) => ids.includes(id))
            ? order
            : ids;
    const byId = new Map(lines.map((line) => [line.id, line]));
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const save = (next: number[]) => {
        setOrder(next);
        router.put(
            reorder.url(),
            { date, ids: next },
            {
                preserveScroll: true,
                preserveState: true,
                errorBag: 'dayPlan',
                onFinish: () => setOrder(null),
            },
        );
    };

    const move = (id: number, direction: -1 | 1) => {
        const from = current.indexOf(id);
        const to = from + direction;

        if (from === -1 || to < 0 || to >= current.length) {
            return;
        }

        save(arrayMove(current, from, to));
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) {
            return;
        }

        const from = current.indexOf(Number(active.id));
        const to = current.indexOf(Number(over.id));

        if (from !== -1 && to !== -1) {
            save(arrayMove(current, from, to));
        }
    };

    const textOf = (id: unknown) => byId.get(Number(id))?.text ?? '';
    const positionOf = (id: unknown) => current.indexOf(Number(id)) + 1;
    const announcements: Announcements = {
        onDragStart: ({ active }) =>
            t('day_plan.drag.start', {
                text: textOf(active.id),
                position: positionOf(active.id),
                total: current.length,
            }),
        onDragOver: ({ active, over }) =>
            over
                ? t('day_plan.drag.over', {
                      text: textOf(active.id),
                      position: positionOf(over.id),
                      total: current.length,
                  })
                : undefined,
        onDragEnd: ({ active, over }) =>
            over
                ? t('day_plan.drag.end', {
                      text: textOf(active.id),
                      position: positionOf(over.id),
                      total: current.length,
                  })
                : t('day_plan.drag.cancel', { text: textOf(active.id) }),
        onDragCancel: ({ active }) =>
            t('day_plan.drag.cancel', { text: textOf(active.id) }),
    };

    const row = (line: DayPlanLine, index: number, handle?: ReactNode) => (
        <MyLineRow
            line={line}
            targets={targets}
            today={today}
            horizonEnd={horizonEnd}
            canWrite={canWrite}
            canClose={canClose}
            handle={handle}
            timer={renderTimer?.(line)}
            extraActions={renderActions?.(line)}
            onMove={
                canWrite ? (direction) => move(line.id, direction) : undefined
            }
            isFirst={index === 0}
            isLast={index === current.length - 1}
        />
    );

    if (!canWrite) {
        return (
            <ol className="divide-y" data-test="day-plan-lines">
                {current.map((id, index) => {
                    const line = byId.get(id);

                    return line ? <li key={id}>{row(line, index)}</li> : null;
                })}
            </ol>
        );
    }

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={onDragEnd}
            accessibility={{
                announcements,
                screenReaderInstructions: {
                    draggable: t('day_plan.drag.instructions'),
                },
            }}
        >
            <SortableContext
                items={current}
                strategy={verticalListSortingStrategy}
            >
                <ol className="divide-y" data-test="day-plan-lines">
                    {current.map((id, index) => {
                        const line = byId.get(id);

                        return line ? (
                            <SortableLine
                                key={id}
                                id={id}
                                label={t('day_plan.drag.handle', {
                                    text: line.text,
                                })}
                            >
                                {(handle) => row(line, index, handle)}
                            </SortableLine>
                        ) : null;
                    })}
                </ol>
            </SortableContext>
        </DndContext>
    );
}

function SortableLine({
    id,
    label,
    children,
}: {
    id: number;
    label: string;
    children: (handle: ReactNode) => ReactNode;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={isDragging ? 'relative z-10 bg-background' : undefined}
        >
            {children(
                <DragHandle
                    label={label}
                    attributes={
                        attributes as unknown as Record<string, unknown>
                    }
                    listeners={listeners as Record<string, unknown> | undefined}
                />,
            )}
        </li>
    );
}
