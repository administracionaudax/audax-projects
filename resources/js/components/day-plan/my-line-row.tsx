import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Ban,
    CalendarArrowUp,
    GripVertical,
    MoreHorizontal,
    Pencil,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { destroy, status } from '@/routes/day-plan/items';
import { LineContent, LineFigures } from './line-content';
import {
    CarryDialog,
    CompleteTaskDialog,
    EditLineDialog,
    NotDoneDialog,
} from './line-dialogs';
import type { DayPlanLine, DayPlanTargets } from '@/types/day-plan';

type Dialog = 'edit' | 'carry' | 'not_done' | 'complete' | 'delete' | null;

const VISIT = {
    preserveScroll: true,
    preserveState: true,
    errorBag: 'dayPlan',
} as const;

/**
 * Una línea de mi plan (docs/PLAN-CARGAS.md §4.3): check (hecha), asa para reordenar, texto con sus
 * datos, horas, el temporizador (`timer`) y el menú ⋯ (editar, pasar a otro día, no hecha,
 * pendiente otra vez, subir y bajar sin arrastrar, imputar, vincular horas y borrar).
 */
export function MyLineRow({
    line,
    targets,
    today,
    horizonEnd,
    canWrite,
    canClose,
    handle,
    timer,
    extraActions,
    onMove,
    isFirst,
    isLast,
}: {
    line: DayPlanLine;
    targets: DayPlanTargets | undefined;
    today: string;
    horizonEnd: string;
    canWrite: boolean;
    canClose: boolean;
    /** Asa de arrastre (dnd-kit), solo si se puede reordenar. */
    handle?: ReactNode;
    /** Botón del temporizador de la línea (C3). */
    timer?: ReactNode;
    /** Acciones de horas del menú ⋯ (imputar, vincular, imputar lo previsto). */
    extraActions?: ReactNode;
    onMove?: (direction: -1 | 1) => void;
    isFirst?: boolean;
    isLast?: boolean;
}) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const [processing, setProcessing] = useState(false);
    const carried = line.status === 'carried';
    const checkable = canClose && !carried;

    const setStatus = (next: 'pending' | 'done') =>
        router.post(
            status.url(line.id),
            { status: next },
            {
                ...VISIT,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    const toggle = (checked: boolean) => {
        if (!checked) {
            setStatus('pending');

            return;
        }

        if (line.task && !line.task.is_completed) {
            setDialog('complete');

            return;
        }

        setStatus('done');
    };

    const close = (open: boolean) => {
        if (!open) {
            setDialog(null);
        }
    };

    return (
        <div
            className={cn(
                'flex items-start gap-2 py-2',
                line.running && 'bg-info-soft',
            )}
            data-test="day-plan-line"
            data-line-id={line.id}
            data-status={line.status}
        >
            {handle ?? <span aria-hidden="true" className="w-4 shrink-0" />}
            <Checkbox
                checked={line.status === 'done'}
                disabled={!checkable || processing}
                onCheckedChange={(checked) => toggle(checked === true)}
                aria-label={t(
                    line.status === 'done'
                        ? 'day_plan.line.uncheck'
                        : 'day_plan.line.check',
                    { text: line.text },
                )}
                className="mt-0.5"
                data-test="day-plan-line-check"
            />
            <LineContent line={line} />
            <LineFigures line={line} />
            {timer}
            {canWrite || canClose ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className={cn('size-8 shrink-0', FOCUS_RING)}
                            aria-label={t('day_plan.line.actions', {
                                text: line.text,
                            })}
                            data-test="day-plan-line-menu"
                        >
                            <MoreHorizontal aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-56">
                        {canWrite && !carried ? (
                            <DropdownMenuItem
                                onSelect={() => setDialog('edit')}
                            >
                                <Pencil aria-hidden="true" />
                                {t('day_plan.line.edit')}
                            </DropdownMenuItem>
                        ) : null}
                        {canClose && !carried && line.status !== 'done' ? (
                            <DropdownMenuItem
                                onSelect={() => setDialog('carry')}
                                data-test="day-plan-line-carry"
                            >
                                <CalendarArrowUp aria-hidden="true" />
                                {t('day_plan.line.carry')}
                            </DropdownMenuItem>
                        ) : null}
                        {canClose && !carried && line.status !== 'not_done' ? (
                            <DropdownMenuItem
                                onSelect={() => setDialog('not_done')}
                                data-test="day-plan-line-not-done"
                            >
                                <Ban aria-hidden="true" />
                                {t('day_plan.line.mark_not_done')}
                            </DropdownMenuItem>
                        ) : null}
                        {canClose &&
                        (line.status === 'not_done' ||
                            line.status === 'done') ? (
                            <DropdownMenuItem
                                onSelect={() => setStatus('pending')}
                            >
                                <RotateCcw aria-hidden="true" />
                                {t('day_plan.line.reopen')}
                            </DropdownMenuItem>
                        ) : null}
                        {extraActions}
                        {canWrite && onMove ? (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    disabled={isFirst}
                                    onSelect={() => onMove(-1)}
                                >
                                    <ArrowUp aria-hidden="true" />
                                    {t('day_plan.line.move_up')}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    disabled={isLast}
                                    onSelect={() => onMove(1)}
                                >
                                    <ArrowDown aria-hidden="true" />
                                    {t('day_plan.line.move_down')}
                                </DropdownMenuItem>
                            </>
                        ) : null}
                        {canWrite ? (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => setDialog('delete')}
                                    data-test="day-plan-line-delete"
                                >
                                    <Trash2 aria-hidden="true" />
                                    {t('day_plan.line.delete')}
                                </DropdownMenuItem>
                            </>
                        ) : null}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}

            {dialog === 'edit' ? (
                <EditLineDialog
                    line={line}
                    targets={targets}
                    open
                    onOpenChange={close}
                />
            ) : null}
            {dialog === 'carry' ? (
                <CarryDialog
                    line={line}
                    today={today}
                    horizonEnd={horizonEnd}
                    open
                    onOpenChange={close}
                />
            ) : null}
            {dialog === 'not_done' ? (
                <NotDoneDialog line={line} open onOpenChange={close} />
            ) : null}
            {dialog === 'complete' ? (
                <CompleteTaskDialog line={line} open onOpenChange={close} />
            ) : null}
            {dialog === 'delete' ? (
                <ConfirmDialog
                    trigger={<span hidden />}
                    open
                    onOpenChange={close}
                    title={t('day_plan.delete.title')}
                    description={t(
                        line.carried_from_date
                            ? 'day_plan.delete.description_carried'
                            : 'day_plan.delete.description',
                        { text: line.text },
                    )}
                    confirmLabel={t('day_plan.delete.confirm')}
                    processing={processing}
                    onConfirm={() =>
                        router.delete(destroy.url(line.id), {
                            ...VISIT,
                            onStart: () => setProcessing(true),
                            onFinish: () => setProcessing(false),
                            onSuccess: () => setDialog(null),
                        })
                    }
                />
            ) : null}
        </div>
    );
}

/** Asa de arrastre accesible (espacio para coger, flechas para mover). */
export function DragHandle({
    label,
    attributes,
    listeners,
}: {
    label: string;
    attributes: Record<string, unknown>;
    listeners: Record<string, unknown> | undefined;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            className={cn(
                'mt-0.5 shrink-0 cursor-grab text-muted-foreground hover:text-foreground',
                FOCUS_RING,
            )}
            {...attributes}
            {...listeners}
            data-test="day-plan-line-handle"
        >
            <GripVertical aria-hidden="true" className="size-4" />
        </button>
    );
}
