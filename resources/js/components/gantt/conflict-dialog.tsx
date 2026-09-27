import { ArrowRight, TriangleAlert } from 'lucide-react';
import type {
    ConflictChoice,
    RescheduleConflict,
} from '@/components/gantt/use-gantt-editing';
import { describeDates } from '@/components/gantt/labels';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';

/** "08/10/2026 – 09/10/2026", "09/10/2026" o «sin fechas». */
export function formatRange(start: string | null, due: string | null): string {
    if (start && due) {
        return start === due
            ? formatDate(due)
            : `${formatDate(start)} – ${formatDate(due)}`;
    }

    const day = due ?? start;

    return day ? formatDate(day) : t('gantt.no_dates');
}

/**
 * Aviso de conflicto al mover una tarea con sucesoras (SPEC §6.1, D-057): lista cada sucesora con
 * sus fechas actuales y las propuestas, y ofrece «Mover también las sucesoras» (la propuesta se
 * recalcula en el servidor), «Solo esta tarea» (el conflicto queda marcado) o «Cancelar» (la tarea
 * vuelve a su sitio). Cerrar el diálogo es cancelar.
 */
export function ConflictDialog({
    conflict,
    resolving,
    onChoose,
    onCloseAutoFocus,
}: {
    conflict: RescheduleConflict | null;
    resolving: ConflictChoice | null;
    onChoose: (choice: ConflictChoice) => void;
    onCloseAutoFocus?: (event: Event) => void;
}) {
    const busy = resolving !== null;

    return (
        <Dialog
            open={conflict !== null}
            onOpenChange={(open) => {
                if (!open && !busy) {
                    onChoose('cancel');
                }
            }}
        >
            <DialogContent
                className="sm:max-w-2xl"
                onCloseAutoFocus={onCloseAutoFocus}
                data-test="gantt-conflict-dialog"
            >
                {conflict ? (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="size-5 shrink-0 text-warning"
                                />
                                {t('gantt.conflict.title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t(
                                    conflict.proposals.length === 1
                                        ? 'gantt.conflict.description_one'
                                        : 'gantt.conflict.description',
                                    {
                                        task: conflict.task.title,
                                        dates: describeDates(
                                            conflict.dates,
                                            conflict.task.is_milestone,
                                        ),
                                        count: conflict.proposals.length,
                                    },
                                )}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="max-h-72 overflow-auto rounded-md border">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    {t('gantt.conflict.caption')}
                                </caption>
                                <thead className="bg-muted text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('gantt.column.task')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('gantt.conflict.current')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('gantt.conflict.proposed')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {conflict.proposals.map((proposal) => (
                                        <tr
                                            key={proposal.task_id}
                                            className="border-t"
                                        >
                                            <th
                                                scope="row"
                                                className="px-3 py-2 text-left font-normal"
                                            >
                                                {proposal.title}
                                            </th>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {formatRange(
                                                    proposal.start_date,
                                                    proposal.due_date,
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <span className="flex flex-wrap items-center gap-1.5 whitespace-nowrap">
                                                    <ArrowRight
                                                        aria-hidden="true"
                                                        className="size-3.5 text-muted-foreground"
                                                    />
                                                    {formatRange(
                                                        proposal.new_start_date,
                                                        proposal.new_due_date,
                                                    )}
                                                    <span className="text-xs text-muted-foreground">
                                                        {proposal.shift_days ===
                                                        1
                                                            ? t(
                                                                  'gantt.conflict.shift_day',
                                                              )
                                                            : t(
                                                                  'gantt.conflict.shift_days',
                                                                  {
                                                                      count: proposal.shift_days,
                                                                  },
                                                              )}
                                                    </span>
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <p className="text-sm text-muted-foreground">
                            {t('gantt.conflict.hint')}
                        </p>

                        <DialogFooter className="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={busy}
                                onClick={() => onChoose('cancel')}
                            >
                                {t('gantt.conflict.cancel')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => onChoose('only')}
                            >
                                {resolving === 'only' ? <Spinner /> : null}
                                {t('gantt.conflict.only_this')}
                            </Button>
                            <Button
                                type="button"
                                disabled={busy}
                                onClick={() => onChoose('shift')}
                            >
                                {resolving === 'shift' ? <Spinner /> : null}
                                {t('gantt.conflict.shift')}
                            </Button>
                        </DialogFooter>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
