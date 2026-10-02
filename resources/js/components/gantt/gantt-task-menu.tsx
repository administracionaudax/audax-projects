import {
    CalendarDays,
    CalendarX2,
    Ellipsis,
    ExternalLink,
    Link2,
    Unlink,
} from 'lucide-react';
import type { GanttTask } from '@/components/gantt/types';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskDependencyItem } from '@/types/schedule';

export type GanttTaskAction = 'dates' | 'clear' | 'dependency';

export type MenuDependency = {
    dependency: TaskDependencyItem;
    label: string;
};

const BUTTON_CLASS = cn(
    'flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground',
    FOCUS_RING,
);

/**
 * Botón «Más» de una fila (fuera del menú abierto): un botón ligero, sin Radix, para que el Gantt
 * con cientos de filas no monte cientos de menús. Al pulsarlo, la fila pasa a ser la activa y
 * monta el menú de verdad (GanttTaskMenu), ya abierto.
 */
export function GanttMenuButton({
    task,
    onOpenMenu,
}: {
    task: GanttTask;
    onOpenMenu: () => void;
}) {
    return (
        <button
            type="button"
            tabIndex={-1}
            aria-haspopup="menu"
            aria-label={t('gantt.menu.more', { task: task.title })}
            className={BUTTON_CLASS}
            onClick={onOpenMenu}
        >
            <Ellipsis aria-hidden="true" className="size-4" />
        </button>
    );
}

/**
 * Menú de la tarea activa (botón «Más», clic derecho en la barra o Mayús + F10): abrir, cambiar o
 * quitar las fechas, añadir una dependencia y quitar las que tiene.
 */
export function GanttTaskMenu({
    task,
    open,
    onOpenChange,
    editable,
    hasDates,
    dependencies,
    onOpen,
    onAction,
    onUnlink,
    onCloseAutoFocus,
}: {
    task: GanttTask;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editable: boolean;
    hasDates: boolean;
    dependencies: ReadonlyArray<MenuDependency>;
    onOpen: (task: GanttTask) => void;
    onAction?: (action: GanttTaskAction, task: GanttTask) => void;
    onUnlink?: (dependency: TaskDependencyItem) => void;
    onCloseAutoFocus?: (event: Event) => void;
}) {
    return (
        <DropdownMenu open={open} onOpenChange={onOpenChange}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={t('gantt.menu.more', { task: task.title })}
                    className={BUTTON_CLASS}
                    data-test="gantt-task-menu"
                >
                    <Ellipsis aria-hidden="true" className="size-4" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-w-80"
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <DropdownMenuItem onSelect={() => onOpen(task)}>
                    <ExternalLink aria-hidden="true" />
                    {t('gantt.menu.open')}
                </DropdownMenuItem>
                {editable && onAction ? (
                    <>
                        <DropdownMenuItem
                            onSelect={() => onAction('dates', task)}
                        >
                            <CalendarDays aria-hidden="true" />
                            {hasDates
                                ? t('gantt.menu.change_dates')
                                : t('gantt.menu.assign_dates')}
                        </DropdownMenuItem>
                        {hasDates ? (
                            <DropdownMenuItem
                                onSelect={() => onAction('clear', task)}
                            >
                                <CalendarX2 aria-hidden="true" />
                                {t('gantt.menu.clear_dates')}
                            </DropdownMenuItem>
                        ) : null}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => onAction('dependency', task)}
                        >
                            <Link2 aria-hidden="true" />
                            {t('gantt.menu.add_dependency')}
                        </DropdownMenuItem>
                        {dependencies.length > 0 && onUnlink ? (
                            <>
                                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                                    {t('gantt.menu.remove_dependency')}
                                </DropdownMenuLabel>
                                {dependencies.map((item) => (
                                    <DropdownMenuItem
                                        key={item.dependency.id}
                                        onSelect={() =>
                                            onUnlink(item.dependency)
                                        }
                                    >
                                        <Unlink aria-hidden="true" />
                                        <span className="truncate">
                                            {item.label}
                                        </span>
                                    </DropdownMenuItem>
                                ))}
                            </>
                        ) : null}
                    </>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
