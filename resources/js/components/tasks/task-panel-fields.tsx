import { TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/domain/date-picker';
import { DurationInput } from '@/components/domain/duration-input';
import {
    AssigneePicker,
    BankSelect,
    PrioritySelect,
    StatusSelect,
    TypeSelect,
} from '@/components/tasks/task-fields';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import { updateTask } from '@/components/tasks/task-requests';
import type { TaskChanges } from '@/components/tasks/task-requests';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TaskPanelData } from '@/types';

/** Estimación máxima de una tarea: 999 h (TaskFieldRules::MAX_ESTIMATE_MINUTES). */
export const MAX_ESTIMATE_MINUTES = 999 * 60;

function EstimateField({
    panel,
    disabled,
}: {
    panel: TaskPanelData;
    disabled: boolean;
}) {
    const id = useId();
    const helpId = useId();
    const task = panel.task;
    const [value, setValue] = useState<number | null>(task.estimated_minutes);
    const [source, setSource] = useState(task.estimated_minutes);

    if (source !== task.estimated_minutes) {
        setSource(task.estimated_minutes);
        setValue(task.estimated_minutes);
    }

    if (task.is_milestone) {
        return (
            <div className="grid gap-2">
                <Label htmlFor={id}>{t('task_panel.estimate')}</Label>
                <p id={id} className="text-sm text-muted-foreground">
                    {t('task_panel.milestone_no_estimate')}
                </p>
            </div>
        );
    }

    if (panel.estimate_from_subtasks) {
        return (
            <div className="grid gap-2">
                <span className="text-sm font-medium" id={id}>
                    {t('task_panel.estimate')}
                </span>
                <p aria-labelledby={id} className="tabular text-sm">
                    {formatMinutes(panel.effective_estimated_minutes ?? 0)}
                </p>
                <p className="text-xs text-muted-foreground">
                    {t('task_panel.estimate_from_subtasks')}
                </p>
            </div>
        );
    }

    return (
        <div
            className="grid gap-2"
            onBlur={(event) => {
                if (
                    event.currentTarget.contains(
                        event.relatedTarget as Node | null,
                    )
                ) {
                    return;
                }

                if (value !== task.estimated_minutes) {
                    updateTask(
                        task.id,
                        { estimated_minutes: value },
                        {
                            onFailure: () => setValue(task.estimated_minutes),
                        },
                    );
                }
            }}
        >
            <Label htmlFor={id}>{t('task_panel.estimate')}</Label>
            <DurationInput
                id={id}
                value={value}
                onChange={setValue}
                max={MAX_ESTIMATE_MINUTES}
                disabled={disabled}
                aria-describedby={helpId}
            />
            <p id={helpId} className="sr-only">
                {t('task_panel.estimate_help')}
            </p>
        </div>
    );
}

/**
 * Campos editables del panel (SPEC §6): estado, prioridad, responsable, bolsa, tipo, fechas,
 * estimación, facturable e hito. Cada cambio se guarda al momento (recarga parcial).
 */
export function TaskPanelFields({ panel }: { panel: TaskPanelData }) {
    const lookups = useTaskLookups();
    const task = panel.task;
    const disabled = !panel.can.update;
    const ids = {
        status: useId(),
        priority: useId(),
        assignee: useId(),
        bank: useId(),
        bankHelp: useId(),
        type: useId(),
        start: useId(),
        due: useId(),
        billable: useId(),
        milestone: useId(),
    };
    const [pendingBank, setPendingBank] = useState<number | null | undefined>(
        undefined,
    );

    const save = (changes: TaskChanges) => updateTask(task.id, changes);

    const changeBank = (bankId: number | null) => {
        if (bankId === task.hour_bank_id) {
            return;
        }

        // Aviso antes de confirmar (SPEC §6): las horas ya imputadas no cambian de bolsa.
        if (panel.has_time) {
            setPendingBank(bankId);

            return;
        }

        save({ hour_bank_id: bankId });
    };

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
                <Label htmlFor={ids.status}>{t('task_panel.status')}</Label>
                <StatusSelect
                    id={ids.status}
                    value={task.status_id}
                    onChange={(statusId) => save({ status_id: statusId })}
                    disabled={disabled}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={ids.priority}>{t('task_panel.priority')}</Label>
                <PrioritySelect
                    id={ids.priority}
                    value={task.priority}
                    onChange={(priority) => save({ priority })}
                    disabled={disabled}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={ids.assignee}>{t('task_panel.assignee')}</Label>
                <AssigneePicker
                    id={ids.assignee}
                    value={task.assignee_user_id}
                    current={task.assignee}
                    onChange={(userId) => save({ assignee_user_id: userId })}
                    disabled={disabled}
                />
            </div>
            {lookups.usesBanks || task.hour_bank_id !== null ? (
                <div className="grid gap-2">
                    <Label htmlFor={ids.bank}>{t('task_panel.bank')}</Label>
                    {panel.parent ? (
                        <p id={ids.bank} className="text-sm">
                            {task.hour_bank_id !== null
                                ? (lookups.bankById.get(task.hour_bank_id)
                                      ?.name ?? '—')
                                : t('task_fields.no_bank')}
                            <span className="block text-xs text-muted-foreground">
                                {t('task_panel.subtask_bank')}
                            </span>
                        </p>
                    ) : (
                        <>
                            <BankSelect
                                id={ids.bank}
                                value={task.hour_bank_id}
                                onChange={changeBank}
                                allowNone={!lookups.usesBanks}
                                disabled={disabled}
                                aria-describedby={ids.bankHelp}
                            />
                            <p
                                id={ids.bankHelp}
                                className="text-xs text-muted-foreground"
                            >
                                {t('task_panel.bank_help')}
                            </p>
                        </>
                    )}
                </div>
            ) : null}
            <div className="grid gap-2">
                <Label htmlFor={ids.type}>{t('task_panel.type')}</Label>
                <TypeSelect
                    id={ids.type}
                    value={task.task_type_id}
                    onChange={(typeId) => save({ task_type_id: typeId })}
                    disabled={disabled}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={ids.start}>{t('task_panel.start_date')}</Label>
                <DatePicker
                    id={ids.start}
                    value={task.start_date}
                    onChange={(date) => save({ start_date: date })}
                    disabled={disabled}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={ids.due}>{t('task_panel.due_date')}</Label>
                <DatePicker
                    id={ids.due}
                    value={task.due_date}
                    onChange={(date) => save({ due_date: date })}
                    disabled={disabled}
                />
            </div>
            <EstimateField key={task.id} panel={panel} disabled={disabled} />
            <div className="flex flex-col justify-end gap-3">
                <div className="flex items-center gap-3">
                    <Switch
                        id={ids.billable}
                        checked={task.is_billable}
                        onCheckedChange={(checked) =>
                            save({ is_billable: checked })
                        }
                        disabled={disabled}
                    />
                    <Label htmlFor={ids.billable} className="font-normal">
                        {t('task_panel.billable')}
                    </Label>
                </div>
                <div className="flex items-center gap-3">
                    <Switch
                        id={ids.milestone}
                        checked={task.is_milestone}
                        onCheckedChange={(checked) =>
                            save({ is_milestone: checked })
                        }
                        disabled={disabled}
                    />
                    <Label htmlFor={ids.milestone} className="font-normal">
                        {t('task_panel.milestone')}
                    </Label>
                </div>
            </div>

            <ConfirmDialog
                open={pendingBank !== undefined}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingBank(undefined);
                    }
                }}
                trigger={<span hidden />}
                title={t('task_panel.bank_change_title')}
                description={t('task_panel.bank_change_description')}
                confirmLabel={t('task_panel.bank_change_confirm')}
                destructive={false}
                onConfirm={() => {
                    if (pendingBank !== undefined) {
                        save({ hour_bank_id: pendingBank });
                    }

                    setPendingBank(undefined);
                }}
            />
            {panel.has_time && !panel.parent && lookups.usesBanks ? (
                <p className="flex items-start gap-1.5 text-xs text-muted-foreground sm:col-span-2">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0 text-warning"
                    />
                    {t('task_panel.bank_has_time')}
                </p>
            ) : null}
        </div>
    );
}
