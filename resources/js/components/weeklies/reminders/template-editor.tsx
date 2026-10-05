import { RotateCcw } from 'lucide-react';
import { useId } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import {
    normalizeWeeklyTemplate,
    renderWeeklyTemplate,
} from '@/lib/weekly-templates';
import type {
    WeeklyEditableTemplate,
    WeeklyEmailTemplate,
} from '@/types/weeklies';

/** Las plantillas editables, en el orden de la pantalla. */
export const EDITABLE_TEMPLATES: WeeklyEditableTemplate[] = [
    'automatic',
    'manual',
    'weekly_closed',
];

/** Límites de WeeklyTemplates (SUBJECT_MAX y BODY_MAX). */
const SUBJECT_MAX = 200;
const BODY_MAX = 5000;

export function isDefaultTemplate(
    template: WeeklyEmailTemplate,
    fallback: WeeklyEmailTemplate,
): boolean {
    return (
        normalizeWeeklyTemplate(template.subject) ===
            normalizeWeeklyTemplate(fallback.subject) &&
        normalizeWeeklyTemplate(template.body) ===
            normalizeWeeklyTemplate(fallback.body)
    );
}

/**
 * Una plantilla editable (F-104 y F-105): asunto y cuerpo con sus variables, la vista previa con
 * los datos de quien la edita y de la semana activa, y «Restaurar por defecto».
 */
export function TemplateEditor({
    name,
    value,
    fallback,
    variables,
    previewValues,
    previewName,
    errors,
    onChange,
}: {
    name: WeeklyEditableTemplate;
    value: WeeklyEmailTemplate;
    fallback: WeeklyEmailTemplate;
    variables: string[];
    previewValues: Record<string, string>;
    previewName: string;
    errors: Record<string, string | undefined>;
    onChange: (value: WeeklyEmailTemplate) => void;
}) {
    const id = useId();
    const isDefault = isDefaultTemplate(value, fallback);
    const subjectError = errors[`templates.${name}.subject`];
    const bodyError = errors[`templates.${name}.body`];

    return (
        <section
            aria-labelledby={`${id}-title`}
            className="grid gap-4 border bg-card p-4"
            data-test={`template-${name}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="grid gap-1">
                    <h3
                        id={`${id}-title`}
                        className="flex items-center gap-2 text-sm font-medium"
                    >
                        {t(`weekly_reminders.templates_names.${name}`)}
                        <Badge variant={isDefault ? 'secondary' : 'outline'}>
                            {isDefault
                                ? t('weekly_reminders.templates.default')
                                : t('weekly_reminders.templates.custom')}
                        </Badge>
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {t(`weekly_reminders.templates.help.${name}`)}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={isDefault}
                    onClick={() => onChange({ ...fallback })}
                    data-test={`template-${name}-restore`}
                >
                    <RotateCcw aria-hidden="true" />
                    {t('weekly_reminders.templates.restore')}
                </Button>
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <div className="grid content-start gap-4">
                    <Field
                        id={`${id}-subject`}
                        label={t('weekly_reminders.templates.subject')}
                        error={subjectError}
                    >
                        <Input
                            id={`${id}-subject`}
                            value={value.subject}
                            maxLength={SUBJECT_MAX}
                            required
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    subject: event.target.value,
                                })
                            }
                            aria-invalid={subjectError ? true : undefined}
                            aria-describedby={describedBy(`${id}-subject`, {
                                error: subjectError,
                            })}
                        />
                    </Field>
                    <Field
                        id={`${id}-body`}
                        label={t('weekly_reminders.templates.body')}
                        help={t('weekly_reminders.templates.variables', {
                            list: variables.map((key) => `{${key}}`).join(', '),
                        })}
                        error={bodyError}
                    >
                        <Textarea
                            id={`${id}-body`}
                            value={value.body}
                            rows={8}
                            maxLength={BODY_MAX}
                            required
                            onChange={(event) =>
                                onChange({ ...value, body: event.target.value })
                            }
                            aria-invalid={bodyError ? true : undefined}
                            aria-describedby={describedBy(`${id}-body`, {
                                help: true,
                                error: bodyError,
                            })}
                        />
                    </Field>
                </div>
                <figure className="grid content-start gap-2">
                    <figcaption className="text-sm font-medium">
                        {t('weekly_reminders.templates.preview')}
                        <span className="ml-1 font-normal text-muted-foreground">
                            {t('weekly_reminders.templates.preview_for', {
                                name: previewName,
                            })}
                        </span>
                    </figcaption>
                    <div
                        className="grid gap-3 border bg-muted/40 p-4 text-sm"
                        data-test={`template-${name}-preview`}
                    >
                        <p className="font-medium break-words">
                            {renderWeeklyTemplate(value.subject, previewValues)}
                        </p>
                        <p className="break-words whitespace-pre-line">
                            {renderWeeklyTemplate(value.body, previewValues)}
                        </p>
                    </div>
                </figure>
            </div>
        </section>
    );
}
