import { useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { update as updateReport } from '@/routes/weeklies/report';
import type {
    WeeklyClientStatus,
    WeeklyCycleDetail,
    WeeklyReport,
} from '@/types/weeklies';

const STATUSES: WeeklyClientStatus[] = ['on_track', 'risk', 'blocked'];

type EditableClient = {
    client_id: number | null;
    client_name: string;
    status: WeeklyClientStatus;
    executive_summary: string;
    next_steps: string[];
    milestones: { date: string; label: string }[];
};

type EditForm = {
    global_summary: string;
    team_risks: string[];
    client_updates: EditableClient[];
};

/** El formulario parte del informe guardado (copias, para no tocar las props). */
export function editableReport(report: WeeklyReport): EditForm {
    return {
        global_summary: report.global_summary,
        team_risks: [...report.team_risks],
        client_updates: report.client_updates.map((update) => ({
            client_id: update.client_id,
            client_name: update.client_name,
            status: update.status,
            executive_summary: update.executive_summary,
            next_steps: [...update.next_steps],
            milestones: update.milestones.map((milestone) => ({
                date: milestone.date ?? '',
                label: milestone.label,
            })),
        })),
    };
}

/**
 * «Editar informe» (F-077, D-190): el resumen global, los riesgos y, por cliente, el estado, el
 * resumen ejecutivo, los siguientes pasos y los hitos con su fecha. Lo que no se edita (proyectos,
 * satisfacción y etiquetas) se conserva en el servidor. Al guardar, el texto para copiar se vuelve a
 * escribir con el informe.
 */
export function ReportEditDialog({
    cycle,
    report,
    trigger,
}: {
    cycle: WeeklyCycleDetail;
    report: WeeklyReport;
    trigger: ReactNode;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<EditForm>(editableReport(report));

    const setClient = (index: number, patch: Partial<EditableClient>) =>
        form.setData(
            'client_updates',
            form.data.client_updates.map((client, i) =>
                i === index ? { ...client, ...patch } : client,
            ),
        );

    const error = (key: string): string | undefined =>
        (form.errors as Record<string, string | undefined>)[key];

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.setData(editableReport(report));
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-3xl">
                <form
                    className="grid gap-6"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(updateReport.url(cycle.id), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                    data-test="weekly-edit-form"
                >
                    <DialogHeader>
                        <DialogTitle>{t('weeklies.edit.title')}</DialogTitle>
                        <DialogDescription>{cycle.label}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-global`}>
                            {t('weeklies.report.global_summary')}
                        </Label>
                        <Textarea
                            id={`${id}-global`}
                            rows={4}
                            value={form.data.global_summary}
                            onChange={(event) =>
                                form.setData(
                                    'global_summary',
                                    event.target.value,
                                )
                            }
                        />
                        <InputError message={error('global_summary')} />
                    </div>

                    <StringList
                        legend={t('weeklies.report.team_risks')}
                        values={form.data.team_risks}
                        addLabel={t('weeklies.edit.add_risk')}
                        removeLabel={(n) =>
                            t('weeklies.edit.remove_risk', { n })
                        }
                        onChange={(values) =>
                            form.setData('team_risks', values)
                        }
                        errorFor={(i) => error(`team_risks.${i}`)}
                    />

                    {form.data.client_updates.map((client, index) => (
                        <fieldset
                            key={`${client.client_id ?? 'general'}-${index}`}
                            className="grid gap-4 border p-4"
                            data-test="weekly-edit-client"
                        >
                            <legend className="px-1 text-base">
                                {client.client_name}
                            </legend>

                            <div className="grid gap-2 sm:max-w-xs">
                                <Label htmlFor={`${id}-${index}-status`}>
                                    {t('weeklies.edit.status')}
                                </Label>
                                <Select
                                    value={client.status}
                                    onValueChange={(value) =>
                                        setClient(index, {
                                            status: value as WeeklyClientStatus,
                                        })
                                    }
                                >
                                    <SelectTrigger id={`${id}-${index}-status`}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {STATUSES.map((status) => (
                                            <SelectItem
                                                key={status}
                                                value={status}
                                            >
                                                {t(
                                                    `weeklies.client_status.${status}`,
                                                )}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor={`${id}-${index}-summary`}>
                                    {t('weeklies.report.executive_summary')}
                                </Label>
                                <Textarea
                                    id={`${id}-${index}-summary`}
                                    rows={4}
                                    value={client.executive_summary}
                                    onChange={(event) =>
                                        setClient(index, {
                                            executive_summary:
                                                event.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={error(
                                        `client_updates.${index}.executive_summary`,
                                    )}
                                />
                            </div>

                            <StringList
                                legend={t('weeklies.report.next_steps')}
                                values={client.next_steps}
                                addLabel={t('weeklies.edit.add_step')}
                                removeLabel={(n) =>
                                    t('weeklies.edit.remove_step', { n })
                                }
                                onChange={(values) =>
                                    setClient(index, { next_steps: values })
                                }
                                errorFor={(i) =>
                                    error(
                                        `client_updates.${index}.next_steps.${i}`,
                                    )
                                }
                            />

                            <fieldset className="grid gap-2">
                                <legend className="mb-1 text-sm">
                                    {t('weeklies.report.milestones')}
                                </legend>
                                {client.milestones.map((milestone, m) => (
                                    <div key={m} className="grid gap-1">
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Input
                                                className="sm:w-32"
                                                maxLength={40}
                                                aria-label={t(
                                                    'weeklies.edit.milestone_date',
                                                    { n: m + 1 },
                                                )}
                                                placeholder={t(
                                                    'weeklies.edit.date_placeholder',
                                                )}
                                                value={milestone.date}
                                                onChange={(event) =>
                                                    setClient(index, {
                                                        milestones:
                                                            client.milestones.map(
                                                                (item, i) =>
                                                                    i === m
                                                                        ? {
                                                                              ...item,
                                                                              date: event
                                                                                  .target
                                                                                  .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                    })
                                                }
                                            />
                                            <Input
                                                className="flex-1"
                                                maxLength={2000}
                                                aria-label={t(
                                                    'weeklies.edit.milestone_label',
                                                    { n: m + 1 },
                                                )}
                                                value={milestone.label}
                                                onChange={(event) =>
                                                    setClient(index, {
                                                        milestones:
                                                            client.milestones.map(
                                                                (item, i) =>
                                                                    i === m
                                                                        ? {
                                                                              ...item,
                                                                              label: event
                                                                                  .target
                                                                                  .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                    })
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="self-end sm:self-auto"
                                                aria-label={t(
                                                    'weeklies.edit.remove_milestone',
                                                    { n: m + 1 },
                                                )}
                                                onClick={() =>
                                                    setClient(index, {
                                                        milestones:
                                                            client.milestones.filter(
                                                                (_, i) =>
                                                                    i !== m,
                                                            ),
                                                    })
                                                }
                                            >
                                                <X aria-hidden="true" />
                                            </Button>
                                        </div>
                                        <InputError
                                            message={
                                                error(
                                                    `client_updates.${index}.milestones.${m}.date`,
                                                ) ??
                                                error(
                                                    `client_updates.${index}.milestones.${m}.label`,
                                                )
                                            }
                                        />
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="justify-self-start"
                                    onClick={() =>
                                        setClient(index, {
                                            milestones: [
                                                ...client.milestones,
                                                { date: '', label: '' },
                                            ],
                                        })
                                    }
                                >
                                    <Plus aria-hidden="true" />
                                    {t('weeklies.edit.add_milestone')}
                                </Button>
                            </fieldset>
                        </fieldset>
                    ))}

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="weekly-edit-save"
                        >
                            {form.processing && <Spinner />}
                            {t('weeklies.edit.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Lista de textos con «Añadir» y «Quitar» (pasos y riesgos). */
function StringList({
    legend,
    values,
    addLabel,
    removeLabel,
    onChange,
    errorFor,
}: {
    legend: string;
    values: string[];
    addLabel: string;
    removeLabel: (n: number) => string;
    onChange: (values: string[]) => void;
    /** Error del servidor de cada fila (p. ej. `team_risks.2`), junto a su caja. */
    errorFor?: (index: number) => string | undefined;
}) {
    return (
        <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm">{legend}</legend>
            {values.map((value, index) => (
                <div key={index} className="grid gap-1">
                    <div className="flex gap-2">
                        <Input
                            className="flex-1"
                            aria-label={`${legend} ${index + 1}`}
                            aria-invalid={errorFor?.(index) ? true : undefined}
                            maxLength={2000}
                            value={value}
                            onChange={(event) =>
                                onChange(
                                    values.map((item, i) =>
                                        i === index ? event.target.value : item,
                                    ),
                                )
                            }
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={removeLabel(index + 1)}
                            onClick={() =>
                                onChange(values.filter((_, i) => i !== index))
                            }
                        >
                            <X aria-hidden="true" />
                        </Button>
                    </div>
                    <InputError message={errorFor?.(index)} />
                </div>
            ))}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="justify-self-start"
                onClick={() => onChange([...values, ''])}
            >
                <Plus aria-hidden="true" />
                {addLabel}
            </Button>
        </fieldset>
    );
}
