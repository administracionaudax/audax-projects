import { router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { BankSelect } from '@/components/tasks/task-fields';
import { defaultBankId, useTaskLookups } from '@/components/tasks/task-lookups';
import { toastErrors } from '@/components/tasks/task-requests';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { move as moveRoute } from '@/routes/tasks';
import type { ProjectTasksPageProps, TaskPanelData } from '@/types';

/**
 * Mover una tarea (con sus subtareas) a otro proyecto (SPEC §6). Solo a proyectos donde se pueden
 * crear tareas; en uno de bolsas se elige la bolsa de nuevo. Se avisa de que las horas ya
 * imputadas se quedan en el proyecto y la bolsa actuales.
 */
export function MoveTaskDialog({
    panel,
    open,
    onOpenChange,
}: {
    panel: TaskPanelData;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const lookups = useTaskLookups();
    const targets = usePage<ProjectTasksPageProps>().props.moveTargets;
    const projectId = useId();
    const bankId = useId();
    const [target, setTarget] = useState<number | null>(null);
    const [bank, setBank] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const selected = targets?.find((project) => project.id === target) ?? null;

    const load = () => {
        if (targets === undefined) {
            router.reload({ only: ['moveTargets'] });
        }
    };

    const submit = () => {
        if (!selected) {
            setError(t('move_task.choose_project'));

            return;
        }

        if (selected.uses_hour_banks && bank === null) {
            setError(t('move_task.choose_bank'));

            return;
        }

        router.post(
            moveRoute.url(panel.task.id),
            {
                project_id: selected.id,
                hour_bank_id: selected.uses_hour_banks ? bank : null,
            },
            {
                onStart: () => setProcessing(true),
                onSuccess: () => onOpenChange(false),
                onError: (errors) => {
                    setError(Object.values(errors)[0] ?? null);
                    toastErrors(errors);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                onOpenAutoFocus={() => {
                    load();
                    setError(null);
                }}
            >
                <DialogHeader>
                    <DialogTitle>{t('move_task.title')}</DialogTitle>
                    <DialogDescription>
                        {t('move_task.description', { task: panel.task.title })}
                    </DialogDescription>
                </DialogHeader>

                {targets === undefined ? (
                    <div
                        role="status"
                        aria-label={t('common.loading')}
                        className="grid gap-2"
                    >
                        <Skeleton className="h-9 w-full rounded-md" />
                        <Skeleton className="h-9 w-full rounded-md" />
                    </div>
                ) : targets.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('move_task.no_targets')}
                    </p>
                ) : (
                    <div className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor={projectId}>
                                {t('move_task.project')}
                            </Label>
                            <Select
                                value={
                                    target === null ? undefined : String(target)
                                }
                                onValueChange={(value) => {
                                    const next =
                                        targets.find(
                                            (project) =>
                                                project.id === Number(value),
                                        ) ?? null;
                                    setTarget(next?.id ?? null);
                                    setBank(
                                        next
                                            ? defaultBankId(
                                                  next.banks,
                                                  lookups.currentUser
                                                      .department_id,
                                              )
                                            : null,
                                    );
                                    setError(null);
                                }}
                            >
                                <SelectTrigger
                                    id={projectId}
                                    className="w-full"
                                >
                                    <SelectValue
                                        placeholder={t(
                                            'move_task.choose_project',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {targets.map((project) => (
                                        <SelectItem
                                            key={project.id}
                                            value={String(project.id)}
                                        >
                                            {project.code} · {project.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {selected?.uses_hour_banks ? (
                            <div className="grid gap-2">
                                <Label htmlFor={bankId}>
                                    {t('move_task.bank')}
                                </Label>
                                {selected.banks.length === 0 ? (
                                    <p className="text-sm text-destructive-foreground">
                                        {t('move_task.no_banks')}
                                    </p>
                                ) : (
                                    <BankSelect
                                        id={bankId}
                                        value={bank}
                                        onChange={setBank}
                                        banks={selected.banks}
                                    />
                                )}
                            </div>
                        ) : null}
                        <p className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground">
                            <TriangleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-warning"
                            />
                            {t('move_task.warning')}
                        </p>
                        {error ? (
                            <p
                                role="alert"
                                className="text-sm text-destructive-foreground"
                            >
                                {error}
                            </p>
                        ) : null}
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={
                            processing || !targets || targets.length === 0
                        }
                    >
                        {processing ? <Spinner /> : null}
                        {t('move_task.submit')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
