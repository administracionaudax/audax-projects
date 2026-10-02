import { TriangleAlert } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useId } from 'react';
import {
    dependencyMidpoint,
    dependencyPath,
} from '@/components/gantt/geometry';
import type { Point } from '@/components/gantt/geometry';
import { t } from '@/lib/i18n';
import type { TaskDependencyItem } from '@/types/schedule';

export type ArrowItem = {
    dependency: TaskDependencyItem;
    from: Point;
    to: Point;
    conflict: boolean;
    predecessorTitle: string;
    successorTitle: string;
    removable: boolean;
};

/**
 * Flechas de las dependencias fin-inicio (SVG, tramos ortogonales). Las que están en conflicto
 * (D-057) van en el color de error, discontinuas y con un icono de aviso con su explicación: nunca
 * solo el color. Al pasar por encima aparece un botón para quitar la dependencia (con el teclado,
 * desde el menú «Más» de la tarea).
 */
export function GanttArrows({
    items,
    width,
    height,
    linking,
    onUnlink,
}: {
    items: ReadonlyArray<ArrowItem>;
    width: number;
    height: number;
    /** Línea provisional mientras se arrastra desde un conector. */
    linking: { from: Point; to: Point } | null;
    onUnlink?: (dependency: TaskDependencyItem) => void;
}) {
    const id = useId();
    const normal = `${id}-arrow`;
    const conflict = `${id}-conflict`;

    const activate = (
        event: KeyboardEvent<SVGGElement>,
        dependency: TaskDependencyItem,
    ) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onUnlink?.(dependency);
        }
    };

    return (
        <svg
            role="group"
            aria-label={t('gantt.dependencies.label')}
            className="pointer-events-none absolute inset-0 overflow-visible"
            width={width}
            height={height}
        >
            <defs>
                <marker
                    id={normal}
                    viewBox="0 0 8 8"
                    refX="7"
                    refY="4"
                    markerWidth="7"
                    markerHeight="7"
                    orient="auto"
                >
                    <path d="M0,0 L8,4 L0,8 z" fill="var(--muted-foreground)" />
                </marker>
                <marker
                    id={conflict}
                    viewBox="0 0 8 8"
                    refX="7"
                    refY="4"
                    markerWidth="7"
                    markerHeight="7"
                    orient="auto"
                >
                    <path d="M0,0 L8,4 L0,8 z" fill="var(--destructive)" />
                </marker>
            </defs>

            {items.map((item) => {
                const path = dependencyPath(item.from, item.to);
                const middle = dependencyMidpoint(item.from, item.to);
                const names = {
                    predecessor: item.predecessorTitle,
                    successor: item.successorTitle,
                };
                const conflictLabel = t('gantt.dependencies.conflict', names);
                const removeLabel = t('gantt.dependencies.remove', names);

                return (
                    <g
                        key={item.dependency.id}
                        className="group/dep"
                        data-dependency-id={item.dependency.id}
                        data-conflict={item.conflict ? 'true' : undefined}
                    >
                        <path
                            d={path}
                            fill="none"
                            stroke="transparent"
                            strokeWidth={12}
                            pointerEvents="stroke"
                        />
                        <path
                            d={path}
                            fill="none"
                            stroke={
                                item.conflict
                                    ? 'var(--destructive)'
                                    : 'var(--muted-foreground)'
                            }
                            strokeWidth={1.5}
                            strokeDasharray={item.conflict ? '4 3' : undefined}
                            markerEnd={`url(#${item.conflict ? conflict : normal})`}
                        />
                        {item.conflict ? (
                            <g role="img" aria-label={conflictLabel}>
                                <title>{conflictLabel}</title>
                                <circle
                                    cx={item.to.x - 16}
                                    cy={item.to.y - 11}
                                    r={8}
                                    fill="var(--card)"
                                />
                                <TriangleAlert
                                    x={item.to.x - 23}
                                    y={item.to.y - 18}
                                    width={14}
                                    height={14}
                                    color="var(--destructive)"
                                    aria-hidden="true"
                                />
                            </g>
                        ) : null}
                        {item.removable && onUnlink ? (
                            <g
                                role="button"
                                tabIndex={-1}
                                aria-label={removeLabel}
                                data-test="gantt-unlink"
                                className="cursor-pointer opacity-0 group-hover/dep:opacity-100 focus:opacity-100"
                                pointerEvents="all"
                                transform={`translate(${middle.x} ${middle.y})`}
                                onClick={() => onUnlink(item.dependency)}
                                onKeyDown={(event) =>
                                    activate(event, item.dependency)
                                }
                            >
                                <title>{removeLabel}</title>
                                <circle
                                    r={8}
                                    fill="var(--card)"
                                    stroke="var(--border)"
                                />
                                <path
                                    d="M -3 -3 L 3 3 M 3 -3 L -3 3"
                                    stroke="var(--foreground)"
                                    strokeWidth={1.5}
                                />
                            </g>
                        ) : null}
                    </g>
                );
            })}

            {linking ? (
                <line
                    x1={linking.from.x}
                    y1={linking.from.y}
                    x2={linking.to.x}
                    y2={linking.to.y}
                    stroke="var(--primary)"
                    strokeWidth={1.5}
                    strokeDasharray="4 3"
                    markerEnd={`url(#${normal})`}
                />
            ) : null}
        </svg>
    );
}
