import { ArrowRight, TriangleAlert } from 'lucide-react';
import type { PendingReschedule } from '@/components/planning/use-reschedule';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ShiftProposal } from '@/types/schedule';

function datesText(start: string | null, due: string | null): string {
    if (start && due && start !== due) {
        return t('planning.reschedule.range', {
            start: formatDate(start),
            due: formatDate(due),
        });
    }

    return formatDate(due ?? start);
}

/** «al 14/10/2026» o «del 10/10/2026 al 14/10/2026». */
function moveText(start: string | null, due: string | null): string {
    if (due === null) {
        // Una tarea con inicio y sin entrega (calendario del equipo, D-144): se mueve su inicio.
        return t('planning.reschedule.to_day', { date: formatDate(start) });
    }

    return start && start !== due
        ? t('planning.reschedule.to_range', {
              start: formatDate(start),
              due: formatDate(due),
          })
        : t('planning.reschedule.to_day', { date: formatDate(due) });
}

function ProposalItem({ proposal }: { proposal: ShiftProposal }) {
    return (
        <li
            className="grid gap-1 rounded-md border p-2 text-sm"
            data-test="reschedule-proposal"
        >
            <span className="break-words">{proposal.title}</span>
            <span className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                <span className="tabular">
                    {datesText(proposal.start_date, proposal.due_date)}
                </span>
                <ArrowRight aria-hidden="true" className="size-3.5" />
                <span className="sr-only">{t('planning.reschedule.to')}</span>
                <span className="tabular text-foreground">
                    {datesText(proposal.new_start_date, proposal.new_due_date)}
                </span>
                <span>
                    {proposal.shift_days === 1
                        ? t('planning.reschedule.shift_one')
                        : t('planning.reschedule.shift_many', {
                              count: proposal.shift_days,
                          })}
                </span>
            </span>
        </li>
    );
}

/**
 * Diálogo de conflictos al mover una tarea con sucesoras (D-057): enseña qué sucesoras empezarían
 * antes de que acabe su predecesora y a qué fechas se propone llevarlas. «Mover también las
 * sucesoras», «Solo esta tarea» (se guarda la fecha en conflicto) o «Cancelar» (no cambia nada).
 */
export function RescheduleDialog({
    pending,
    saving,
    onConfirm,
    onCancel,
    onCloseAutoFocus,
}: {
    pending: PendingReschedule | null;
    saving: boolean;
    onConfirm: (shiftSuccessors: boolean) => void;
    onCancel: () => void;
    onCloseAutoFocus?: (event: Event) => void;
}) {
    return (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onCancel();
                }
            }}
        >
            <DialogContent
                data-test="reschedule-dialog"
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <DialogTitle className="flex items-center gap-2">
                    <TriangleAlert
                        aria-hidden="true"
                        className="size-5 shrink-0 text-warning"
                    />
                    {t('planning.reschedule.title')}
                </DialogTitle>
                <DialogDescription>
                    {pending
                        ? t('planning.reschedule.description', {
                              task: pending.task.title,
                              when: moveText(
                                  pending.dates.start_date,
                                  pending.dates.due_date,
                              ),
                          })
                        : ''}
                </DialogDescription>
                {pending ? (
                    <ul
                        // Con muchas sucesoras la lista se desplaza: se puede enfocar para hacerlo con el teclado.
                        className={cn(
                            'grid max-h-64 gap-2 overflow-y-auto rounded-md',
                            FOCUS_RING,
                        )}
                        aria-label={t('planning.reschedule.list')}
                        tabIndex={0}
                    >
                        {pending.proposals.map((proposal) => (
                            <ProposalItem
                                key={proposal.task_id}
                                proposal={proposal}
                            />
                        ))}
                    </ul>
                ) : null}
                <p className="text-xs text-muted-foreground">
                    {t('planning.reschedule.help')}
                </p>
                <DialogFooter className="gap-2 sm:flex-wrap">
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={saving}
                        onClick={onCancel}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={saving}
                        onClick={() => onConfirm(false)}
                        data-test="reschedule-only-this"
                    >
                        {t('planning.reschedule.only_this')}
                    </Button>
                    <Button
                        type="button"
                        disabled={saving}
                        onClick={() => onConfirm(true)}
                        data-test="reschedule-shift"
                    >
                        {saving ? <Spinner /> : null}
                        {t('planning.reschedule.shift')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
