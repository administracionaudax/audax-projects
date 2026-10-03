import { router } from '@inertiajs/react';
import { CalendarDays, X } from 'lucide-react';
import { useId, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/domain/date-picker';
import {
    AssigneePicker,
    BankSelect,
    StatusSelect,
} from '@/components/tasks/task-fields';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import { taskVisitOptions } from '@/components/tasks/task-requests';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { t } from '@/lib/i18n';
import { bulk } from '@/routes/tasks';

type BulkChanges = Partial<{
    status_id: number;
    assignee_user_id: number | null;
    start_date: string | null;
    due_date: string | null;
    hour_bank_id: number | null;
}>;

/**
 * Acciones masivas (SPEC §6) sobre las tareas seleccionadas de la lista: estado, responsable,
 * fechas o bolsa. Cambiar la bolsa avisa de que las horas ya imputadas no se mueven.
 */
export function TaskBulkBar({
    selection,
    onClear,
}: {
    selection: Set<number>;
    onClear: () => void;
}) {
    const lookups = useTaskLookups();
    const ids = [...selection];
    const labelId = useId();
    const startId = useId();
    const dueId = useId();
    const [datesOpen, setDatesOpen] = useState(false);
    const [start, setStart] = useState<string | null>(null);
    const [due, setDue] = useState<string | null>(null);
    const [pendingBank, setPendingBank] = useState<number | null | undefined>(
        undefined,
    );
    const [processing, setProcessing] = useState(false);

    const apply = (changes: BulkChanges) => {
        router.patch(
            bulk.url(lookups.project.id),
            { ids, ...changes },
            taskVisitOptions({
                onStart: () => setProcessing(true),
                onSuccess: onClear,
                onFinish: () => setProcessing(false),
            }),
        );
    };

    return (
        <div
            role="region"
            aria-labelledby={labelId}
            className="sticky bottom-4 z-20 flex flex-wrap items-center gap-3 rounded-md border bg-card p-3 shadow-md"
            data-test="bulk-bar"
        >
            <p id={labelId} className="text-sm font-medium" aria-live="polite">
                {t('task_bulk.selected', { count: ids.length })}
            </p>
            <div className="w-40">
                <StatusSelect
                    value={null}
                    placeholder={t('task_bulk.status')}
                    onChange={(statusId) => apply({ status_id: statusId })}
                    aria-label={t('task_bulk.status')}
                    disabled={processing}
                />
            </div>
            <div className="w-48">
                <AssigneePicker
                    value={undefined}
                    placeholder={t('task_bulk.assignee')}
                    onChange={(userId) => apply({ assignee_user_id: userId })}
                    aria-label={t('task_bulk.assignee')}
                    disabled={processing}
                />
            </div>
            <Popover open={datesOpen} onOpenChange={setDatesOpen}>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={processing}
                    >
                        <CalendarDays aria-hidden="true" />
                        {t('task_bulk.dates')}
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" className="grid w-72 gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor={startId}>
                            {t('task_panel.start_date')}
                        </Label>
                        <DatePicker
                            id={startId}
                            value={start}
                            onChange={setStart}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={dueId}>
                            {t('task_panel.due_date')}
                        </Label>
                        <DatePicker id={dueId} value={due} onChange={setDue} />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('task_bulk.dates_help')}
                    </p>
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setDatesOpen(false);
                                apply({ start_date: null, due_date: null });
                            }}
                        >
                            {t('task_bulk.clear_dates')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            disabled={start === null && due === null}
                            onClick={() => {
                                setDatesOpen(false);
                                // Solo cambian las fechas elegidas; las demás se quedan como están.
                                apply({
                                    ...(start !== null
                                        ? { start_date: start }
                                        : {}),
                                    ...(due !== null ? { due_date: due } : {}),
                                });
                            }}
                        >
                            {t('task_bulk.apply_dates')}
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
            {lookups.usesBanks ? (
                <div className="w-52">
                    <BankSelect
                        value={null}
                        onChange={(bankId) => setPendingBank(bankId)}
                        aria-label={t('task_bulk.bank')}
                        disabled={processing}
                    />
                </div>
            ) : null}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onClear}
                className="ml-auto"
            >
                <X aria-hidden="true" />
                {t('task_bulk.clear')}
            </Button>
            <ConfirmDialog
                open={pendingBank !== undefined}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingBank(undefined);
                    }
                }}
                trigger={<span hidden />}
                title={t('task_bulk.bank_title', { count: ids.length })}
                description={t('task_bulk.bank_description')}
                confirmLabel={t('task_bulk.bank_confirm')}
                destructive={false}
                onConfirm={() => {
                    if (pendingBank !== undefined) {
                        apply({ hour_bank_id: pendingBank });
                    }

                    setPendingBank(undefined);
                }}
            />
        </div>
    );
}
