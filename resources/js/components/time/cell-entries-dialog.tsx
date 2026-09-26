import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { TimeEntryStatusBadge } from '@/components/domain/badges';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { destroy } from '@/routes/time/entries';
import type { TimeEntry } from '@/types';
import { dayMonthLabel, weekdayLongLabel } from './week-days';

/**
 * Lista de las entradas de una celda de la hoja semanal (varias entradas el mismo día en la
 * misma tarea, o una que no se puede editar en línea): editar, borrar o añadir otra.
 */
export function CellEntriesDialog({
    open,
    onOpenChange,
    taskTitle,
    day,
    entries,
    editable,
    canEditEntry,
    onEdit,
    onAdd,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    taskTitle: string;
    day: string;
    entries: TimeEntry[];
    /** La semana admite cambios (abierta o devuelta). */
    editable: boolean;
    /** Si una entrada concreta se puede editar (p. ej. bloqueadas, solo admin). */
    canEditEntry: (entry: TimeEntry) => boolean;
    onEdit: (entry: TimeEntry) => void;
    onAdd: () => void;
}) {
    const [confirming, setConfirming] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);
    const total = entries.reduce((sum, entry) => sum + entry.minutes, 0);

    const remove = (entry: TimeEntry) => {
        router.delete(destroy.url(entry.id), {
            preserveScroll: true,
            preserveState: true,
            errorBag: 'timesheet',
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirming(null);
            },
            onError: (errors) =>
                Object.values(errors).forEach((message) =>
                    toast.error(message),
                ),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{taskTitle}</DialogTitle>
                    <DialogDescription>
                        {t('hours.cell.description', {
                            day: `${weekdayLongLabel(day)} ${dayMonthLabel(day)}`,
                            minutes: formatMinutes(total),
                        })}
                    </DialogDescription>
                </DialogHeader>

                {entries.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('hours.cell.empty')}
                    </p>
                ) : (
                    <ul className="divide-y border-y">
                        {entries.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start"
                            >
                                <div className="min-w-0 flex-1 space-y-1">
                                    <p className="flex flex-wrap items-center gap-2">
                                        <span className="tabular font-medium">
                                            {formatMinutes(entry.minutes)}
                                        </span>
                                        <TimeEntryStatusBadge
                                            status={entry.status}
                                        />
                                        {entry.overage_minutes > 0 ? (
                                            <span className="inline-flex items-center gap-1 text-xs">
                                                <TriangleAlert
                                                    aria-hidden="true"
                                                    className="size-3.5 text-danger"
                                                />
                                                {t('hours.cell.overage', {
                                                    minutes: formatMinutes(
                                                        entry.overage_minutes,
                                                    ),
                                                })}
                                            </span>
                                        ) : null}
                                        {!entry.is_billable ? (
                                            <span className="text-xs text-muted-foreground">
                                                {t('hours.cell.not_billable')}
                                            </span>
                                        ) : null}
                                    </p>
                                    <p className="text-sm break-words text-muted-foreground">
                                        {entry.description ??
                                            t('hours.cell.no_description')}
                                    </p>
                                    {entry.logged_on_behalf ? (
                                        <p className="text-xs text-muted-foreground">
                                            {t('hours.cell.on_behalf')}
                                        </p>
                                    ) : null}
                                </div>
                                {canEditEntry(entry) ? (
                                    confirming === entry.id ? (
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm">
                                                {t(
                                                    'hours.dialog.delete_confirm',
                                                )}
                                            </span>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="destructive"
                                                disabled={processing}
                                                onClick={() => remove(entry)}
                                            >
                                                {t('common.delete')}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setConfirming(null)
                                                }
                                            >
                                                {t('common.cancel')}
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="flex gap-1">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => onEdit(entry)}
                                                aria-label={t(
                                                    'hours.cell.edit_label',
                                                    {
                                                        minutes: formatMinutes(
                                                            entry.minutes,
                                                        ),
                                                    },
                                                )}
                                            >
                                                <Pencil aria-hidden="true" />
                                                {t('common.edit')}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setConfirming(entry.id)
                                                }
                                                aria-label={t(
                                                    'hours.cell.delete_label',
                                                    {
                                                        minutes: formatMinutes(
                                                            entry.minutes,
                                                        ),
                                                    },
                                                )}
                                            >
                                                <Trash2 aria-hidden="true" />
                                                {t('common.delete')}
                                            </Button>
                                        </div>
                                    )
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}

                <DialogFooter className="gap-2">
                    {editable ? (
                        <Button type="button" variant="outline" onClick={onAdd}>
                            <Plus aria-hidden="true" />
                            {t('hours.cell.add')}
                        </Button>
                    ) : null}
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('common.close')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
