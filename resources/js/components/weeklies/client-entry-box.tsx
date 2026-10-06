import { Check, ChevronDown, Trash2 } from 'lucide-react';
import { useId } from 'react';
import { NONE } from '@/components/tasks/task-fields';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { DictationButton } from '@/components/weeklies/dictation-button';
import { ClientIcon } from '@/components/weeklies/weekly-ui';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type EntryProject = { id: number; code: string; name: string };

/** Lo que se escribe de un cliente (o de «General / Interno», id null). */
export type EntryValue = {
    body: string;
    project_id: number | null;
    source: 'text' | 'dictation';
};

/**
 * Caja de un cliente en «Mi weekly» (F-044 y F-049; WeeklyReportingInterface de WeeklySync):
 * la cabecera pliega o despliega (con la marca de «Reportado» y un avance del texto plegado), y
 * dentro van el texto, el proyecto opcional y el dictado. En solo lectura, el texto tal cual.
 */
export function ClientEntryBox({
    cycleId,
    clientId,
    name,
    icon,
    projects,
    value,
    expanded,
    readOnly,
    removable = false,
    onToggle,
    onChange,
    onRemove,
    onBusyChange,
    dictationLocked = false,
}: {
    cycleId: number;
    clientId: number | null;
    name: string;
    icon: string | null;
    projects: EntryProject[];
    value: EntryValue;
    expanded: boolean;
    readOnly: boolean;
    /** Un cliente añadido a mano (no propuesto) se puede quitar mientras está vacío. */
    removable?: boolean;
    onToggle: () => void;
    onChange: (value: EntryValue) => void;
    onRemove?: () => void;
    onBusyChange?: (busy: boolean) => void;
    /** Otro apunte está dictando: un solo dictado a la vez, como WeeklySync (10.9b). */
    dictationLocked?: boolean;
}) {
    const id = useId();
    const bodyId = `${id}-body`;
    const panelId = `${id}-panel`;
    const projectId = `${id}-project`;
    const reported = value.body.trim() !== '';
    const testKey = clientId ?? 'general';

    const append = (text: string) => {
        const current = value.body.trimEnd();
        onChange({
            ...value,
            body: current === '' ? text : `${current}\n${text}`,
            source: 'dictation',
        });
    };

    return (
        <section
            aria-labelledby={`${id}-title`}
            className={cn('border bg-card', expanded && 'border-ring/60')}
            data-test={`weekly-entry-${testKey}`}
        >
            <h3 id={`${id}-title`} className="text-sm">
                <button
                    type="button"
                    aria-expanded={expanded}
                    aria-controls={panelId}
                    onClick={onToggle}
                    className={cn(
                        'flex w-full min-w-0 items-center gap-3 px-3 py-2.5 text-left hover:bg-accent/50',
                        FOCUS_RING,
                    )}
                    data-test="weekly-entry-toggle"
                >
                    {reported ? (
                        <span
                            aria-hidden="true"
                            className="inline-flex size-7 shrink-0 items-center justify-center border border-success bg-success-soft"
                        >
                            <Check className="size-4 text-success" />
                        </span>
                    ) : (
                        <ClientIcon icon={icon} />
                    )}
                    <span className="min-w-0 flex-1">
                        <span className="block truncate font-medium">
                            {name}
                        </span>
                        {!expanded && reported ? (
                            <span className="block truncate text-xs text-muted-foreground">
                                {value.body}
                            </span>
                        ) : null}
                    </span>
                    {reported ? (
                        <span className="shrink-0 bg-success-soft px-1.5 py-0.5 text-xs text-foreground">
                            {t('weeklies.editor.reported')}
                        </span>
                    ) : null}
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-4 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none',
                            expanded && 'rotate-180',
                        )}
                    />
                </button>
            </h3>

            <div
                id={panelId}
                hidden={!expanded}
                className="grid gap-3 border-t px-3 pt-3 pb-3"
            >
                {readOnly ? (
                    <p
                        className="text-sm whitespace-pre-wrap"
                        data-test="weekly-entry-readonly"
                    >
                        {reported ? value.body : t('weeklies.editor.no_text')}
                    </p>
                ) : (
                    <>
                        <div className="grid gap-1.5">
                            <Label htmlFor={bodyId}>
                                {t('weeklies.editor.progress_label')}
                            </Label>
                            <Textarea
                                id={bodyId}
                                value={value.body}
                                onChange={(event) =>
                                    onChange({
                                        ...value,
                                        body: event.target.value,
                                    })
                                }
                                placeholder={t('weeklies.editor.placeholder')}
                                className="min-h-28 resize-y"
                                data-test="weekly-entry-text"
                            />
                        </div>
                        {/* Abajo: «Dictar» a la altura de la caja del proyecto, no de su etiqueta. */}
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <DictationButton
                                cycleId={cycleId}
                                clientId={clientId}
                                clientName={name}
                                onText={append}
                                onBusyChange={onBusyChange}
                                disabled={dictationLocked}
                            />
                            {projects.length > 0 ? (
                                <div className="grid min-w-0 gap-1.5 sm:w-64">
                                    <Label htmlFor={projectId}>
                                        {t('weeklies.editor.project')}
                                    </Label>
                                    <Select
                                        value={
                                            value.project_id === null
                                                ? NONE
                                                : String(value.project_id)
                                        }
                                        onValueChange={(next) =>
                                            onChange({
                                                ...value,
                                                project_id:
                                                    next === NONE
                                                        ? null
                                                        : Number(next),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id={projectId}
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>
                                                {t(
                                                    'weeklies.editor.no_project',
                                                )}
                                            </SelectItem>
                                            {projects.map((project) => (
                                                <SelectItem
                                                    key={project.id}
                                                    value={String(project.id)}
                                                >
                                                    {project.code} ·{' '}
                                                    {project.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ) : null}
                        </div>
                        {removable && !reported && onRemove ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="justify-self-start"
                                onClick={onRemove}
                            >
                                <Trash2 aria-hidden="true" />
                                {t('weeklies.editor.remove_client', {
                                    client: name,
                                })}
                            </Button>
                        ) : null}
                    </>
                )}
            </div>
        </section>
    );
}
