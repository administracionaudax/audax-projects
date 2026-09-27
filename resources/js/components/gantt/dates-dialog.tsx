import { CircleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import type { GanttDates, GanttTask } from '@/components/gantt/types';
import { DatePicker } from '@/components/domain/date-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

/**
 * «Asignar fechas» / «Cambiar fechas» (lista «Sin fechas» y menú de la barra): inicio y entrega;
 * un hito solo tiene entrega. Guardar pasa por reprogramar (D-057): si hay sucesoras en conflicto,
 * después se abre el aviso.
 */
export function DatesDialog({
    task,
    onSave,
    onOpenChange,
    onCloseAutoFocus,
}: {
    task: GanttTask | null;
    onSave: (task: GanttTask, dates: GanttDates) => void;
    onOpenChange: (open: boolean) => void;
    onCloseAutoFocus?: (event: Event) => void;
}) {
    return (
        <Dialog open={task !== null} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-md"
                onCloseAutoFocus={onCloseAutoFocus}
                data-test="gantt-dates-dialog"
            >
                {task ? (
                    <DatesForm
                        key={task.id}
                        task={task}
                        onSave={(dates) => {
                            onOpenChange(false);
                            onSave(task, dates);
                        }}
                        onCancel={() => onOpenChange(false)}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function DatesForm({
    task,
    onSave,
    onCancel,
}: {
    task: GanttTask;
    onSave: (dates: GanttDates) => void;
    onCancel: () => void;
}) {
    const startId = useId();
    const dueId = useId();
    const errorId = useId();
    const [start, setStart] = useState<string | null>(
        task.is_milestone ? null : task.start_date,
    );
    const [due, setDue] = useState<string | null>(
        task.due_date ?? (task.is_milestone ? task.start_date : null),
    );
    const [error, setError] = useState<string | null>(null);
    const hasDates = task.start_date !== null || task.due_date !== null;

    const submit = () => {
        if (start && due && due < start) {
            setError(t('gantt.dates.due_before_start'));

            return;
        }

        onSave({ start_date: task.is_milestone ? null : start, due_date: due });
    };

    return (
        <form
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <DialogHeader>
                <DialogTitle>
                    {hasDates
                        ? t('gantt.dates.change_title')
                        : t('gantt.dates.assign_title')}
                </DialogTitle>
                <DialogDescription>
                    {task.is_milestone
                        ? t('gantt.dates.milestone_description', {
                              task: task.title,
                          })
                        : t('gantt.dates.description', { task: task.title })}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-3 sm:grid-cols-2">
                {task.is_milestone ? null : (
                    <div className="grid gap-1.5">
                        <Label htmlFor={startId}>
                            {t('gantt.column.start')}
                        </Label>
                        <DatePicker
                            id={startId}
                            value={start}
                            onChange={(value) => {
                                setStart(value);
                                setError(null);
                            }}
                        />
                    </div>
                )}
                <div className="grid gap-1.5">
                    <Label htmlFor={dueId}>{t('gantt.column.due')}</Label>
                    <DatePicker
                        id={dueId}
                        value={due}
                        invalid={error !== null}
                        onChange={(value) => {
                            setDue(value);
                            setError(null);
                        }}
                    />
                </div>
            </div>

            {error ? (
                <p
                    id={errorId}
                    role="alert"
                    className="flex items-center gap-1.5 text-sm text-destructive-foreground"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="size-4 shrink-0"
                    />
                    {error}
                </p>
            ) : null}

            <DialogFooter className="gap-2">
                <Button type="button" variant="secondary" onClick={onCancel}>
                    {t('gantt.dates.cancel')}
                </Button>
                <Button type="submit" data-test="gantt-dates-save">
                    {t('gantt.dates.save')}
                </Button>
            </DialogFooter>
        </form>
    );
}
