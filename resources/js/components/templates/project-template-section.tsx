import { Link, useForm } from '@inertiajs/react';
import { Archive, LayoutTemplate, Save, Wand2 } from 'lucide-react';
import { useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/domain/date-picker';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAbilities } from '@/hooks/use-auth';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { apply, capture, index as templatesIndex } from '@/routes/templates';
import type { ProjectTemplatingSettings } from '@/types/templates';
import { DeferredSection } from './deferred-section';
import { countText, templateStatsText } from './template-badges';

/** Props que se recargan tras aplicar o guardar una plantilla. */
const TEMPLATING_RELOAD = ['templating'];

/**
 * Sección «Plantilla» de los Ajustes del proyecto (D-058): «Aplicar plantilla» (añade sus tareas
 * desde una fecha, sin tocar las que ya hay, con confirmación del número de tareas) y «Guardar
 * como plantilla» (títulos, tipos, estimaciones, fechas relativas y dependencias; nunca personas,
 * horas ni estados). Sus datos llegan como prop diferida (`templating`).
 */
export function ProjectTemplateSection({
    projectId,
    projectName,
}: {
    projectId: number;
    projectName: string;
}) {
    return (
        <DeferredSection<ProjectTemplatingSettings>
            prop="templating"
            loadingLabel={t('templates.settings.loading')}
        >
            {(settings) => (
                <ProjectTemplating
                    projectId={projectId}
                    projectName={projectName}
                    settings={settings}
                />
            )}
        </DeferredSection>
    );
}

export function ProjectTemplating({
    projectId,
    projectName,
    settings,
}: {
    projectId: number;
    projectName: string;
    settings: ProjectTemplatingSettings;
}) {
    return (
        <div className="grid gap-8">
            {settings.archived ? (
                <Alert role="status">
                    <Archive aria-hidden="true" />
                    <AlertDescription>
                        {t('templates.settings.archived')}
                    </AlertDescription>
                </Alert>
            ) : (
                <ApplyTemplateForm projectId={projectId} settings={settings} />
            )}
            <CaptureTemplateForm
                projectId={projectId}
                projectName={projectName}
                settings={settings}
            />
        </div>
    );
}

type ApplyForm = {
    template_id: number | null;
    start_date: string;
    hour_bank_id: number | null;
};

function ApplyTemplateForm({
    projectId,
    settings,
}: {
    projectId: number;
    settings: ProjectTemplatingSettings;
}) {
    const id = useId();
    const can = useAbilities();
    const [confirming, setConfirming] = useState(false);
    const form = useForm<ApplyForm>({
        template_id: settings.templates[0]?.id ?? null,
        start_date: settings.default_start,
        hour_bank_id: settings.uses_hour_banks
            ? (settings.banks[0]?.id ?? null)
            : null,
    });
    const selected = settings.templates.find(
        (template) => template.id === form.data.template_id,
    );
    const noBanks = settings.uses_hour_banks && settings.banks.length === 0;

    if (settings.templates.length === 0) {
        return (
            <EmptyState
                icon={LayoutTemplate}
                title={t('templates.settings.no_templates')}
                description={t('templates.settings.no_templates_description')}
            >
                {can.viewAdmin ? (
                    <Link
                        href={templatesIndex()}
                        className={cn(
                            'rounded-sm text-sm text-primary-text hover:underline',
                            FOCUS_RING,
                        )}
                    >
                        {t('templates.settings.manage_templates')}
                    </Link>
                ) : null}
            </EmptyState>
        );
    }

    return (
        <section aria-labelledby={`${id}-apply`} className="grid gap-4">
            <div className="grid gap-1">
                <h3 id={`${id}-apply`} className="text-base font-medium">
                    {t('templates.settings.apply_title')}
                </h3>
                <p className="text-sm text-muted-foreground">
                    {t('templates.settings.apply_description')}
                </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    id={`${id}-template`}
                    label={t('templates.fields.template')}
                    help={
                        selected ? templateStatsText(selected.stats) : undefined
                    }
                    error={form.errors.template_id}
                    className="sm:col-span-2"
                >
                    <NativeSelect
                        id={`${id}-template`}
                        value={
                            form.data.template_id === null
                                ? ''
                                : String(form.data.template_id)
                        }
                        aria-invalid={
                            form.errors.template_id ? true : undefined
                        }
                        aria-describedby={describedBy(`${id}-template`, {
                            help: selected !== undefined,
                            error: form.errors.template_id,
                        })}
                        onChange={(event) =>
                            form.setData(
                                'template_id',
                                event.target.value === ''
                                    ? null
                                    : Number(event.target.value),
                            )
                        }
                    >
                        {settings.templates.map((template) => (
                            <option
                                key={template.id}
                                value={String(template.id)}
                            >
                                {template.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                {selected?.description ? (
                    <p className="text-sm text-muted-foreground sm:col-span-2">
                        {selected.description}
                    </p>
                ) : null}
                <Field
                    id={`${id}-start`}
                    label={t('templates.fields.start_date')}
                    help={t('templates.fields.start_date_help')}
                    error={form.errors.start_date}
                >
                    <DatePicker
                        id={`${id}-start`}
                        value={form.data.start_date}
                        clearable={false}
                        invalid={form.errors.start_date ? true : undefined}
                        onChange={(value) =>
                            form.setData(
                                'start_date',
                                value ?? settings.default_start,
                            )
                        }
                    />
                </Field>
                {settings.uses_hour_banks ? (
                    <Field
                        id={`${id}-bank`}
                        label={t('templates.fields.bank')}
                        help={
                            noBanks
                                ? t('templates.settings.no_banks')
                                : t('templates.fields.bank_help')
                        }
                        error={form.errors.hour_bank_id}
                    >
                        <NativeSelect
                            id={`${id}-bank`}
                            value={
                                form.data.hour_bank_id === null
                                    ? ''
                                    : String(form.data.hour_bank_id)
                            }
                            disabled={noBanks}
                            aria-invalid={
                                form.errors.hour_bank_id ? true : undefined
                            }
                            aria-describedby={describedBy(`${id}-bank`, {
                                help: true,
                                error: form.errors.hour_bank_id,
                            })}
                            onChange={(event) =>
                                form.setData(
                                    'hour_bank_id',
                                    event.target.value === ''
                                        ? null
                                        : Number(event.target.value),
                                )
                            }
                        >
                            {noBanks ? (
                                <option value="">
                                    {t('templates.fields.no_bank')}
                                </option>
                            ) : null}
                            {settings.banks.map((bank) => (
                                <option key={bank.id} value={String(bank.id)}>
                                    {bank.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                ) : null}
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                destructive={false}
                trigger={
                    <Button
                        type="button"
                        variant="outline"
                        className="w-fit"
                        disabled={selected === undefined || noBanks}
                    >
                        <Wand2 aria-hidden="true" />
                        {t('templates.settings.apply_button')}
                    </Button>
                }
                title={t('templates.settings.apply_confirm_title', {
                    name: selected?.name ?? '',
                })}
                description={
                    selected
                        ? t('templates.settings.apply_confirm', {
                              tasks: countText(
                                  selected.stats.tasks,
                                  'templates.stats.tasks_one',
                                  'templates.stats.tasks_other',
                              ),
                              milestones: countText(
                                  selected.stats.milestones,
                                  'templates.stats.milestones_one',
                                  'templates.stats.milestones_other',
                              ),
                              dependencies: countText(
                                  selected.stats.dependencies,
                                  'templates.stats.dependencies_one',
                                  'templates.stats.dependencies_other',
                              ),
                              date: formatDate(form.data.start_date),
                          })
                        : ''
                }
                confirmLabel={t('templates.settings.apply_confirm_button', {
                    count: selected?.stats.tasks ?? 0,
                })}
                processing={form.processing}
                onConfirm={() =>
                    form.post(apply.url(projectId), {
                        preserveScroll: true,
                        only: TEMPLATING_RELOAD,
                        onFinish: () => setConfirming(false),
                    })
                }
            />
        </section>
    );
}

function CaptureTemplateForm({
    projectId,
    projectName,
    settings,
}: {
    projectId: number;
    projectName: string;
    settings: ProjectTemplatingSettings;
}) {
    const id = useId();
    const form = useForm<{ name: string; description: string }>({
        name: projectName,
        description: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const empty = settings.task_count === 0;
    const tooMany = settings.task_count > settings.max_tasks;

    return (
        <section aria-labelledby={`${id}-capture`} className="grid gap-4">
            <div className="grid gap-1">
                <h3 id={`${id}-capture`} className="text-base font-medium">
                    {t('templates.settings.capture_title')}
                </h3>
                <p className="text-sm text-muted-foreground">
                    {t('templates.settings.capture_description')}
                </p>
            </div>

            {empty || tooMany ? (
                <p className="text-sm text-muted-foreground">
                    {empty
                        ? t('templates.settings.capture_empty')
                        : t('templates.settings.capture_too_many', {
                              max: settings.max_tasks,
                          })}
                </p>
            ) : (
                <form
                    noValidate
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(capture.url(projectId), {
                            preserveScroll: true,
                            only: TEMPLATING_RELOAD,
                            onSuccess: () => form.reset('description'),
                        });
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${id}-name`}
                            label={t('templates.fields.name')}
                            error={errors.name}
                        >
                            <Input
                                id={`${id}-name`}
                                value={form.data.name}
                                maxLength={255}
                                required
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby={describedBy(`${id}-name`, {
                                    error: errors.name,
                                })}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id={`${id}-description`}
                            label={t('templates.fields.description')}
                            optional={t('templates.fields.optional')}
                            error={errors.description}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id={`${id}-description`}
                                rows={2}
                                maxLength={2000}
                                value={form.data.description}
                                aria-invalid={
                                    errors.description ? true : undefined
                                }
                                aria-describedby={describedBy(
                                    `${id}-description`,
                                    { error: errors.description },
                                )}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                    <InputError message={errors.capture} />
                    <p className="text-sm text-muted-foreground">
                        {countText(
                            settings.task_count,
                            'templates.settings.capture_count_one',
                            'templates.settings.capture_count_other',
                        )}
                    </p>
                    <Button
                        type="submit"
                        variant="outline"
                        className="w-fit"
                        disabled={form.processing}
                    >
                        {form.processing ? (
                            <Spinner />
                        ) : (
                            <Save aria-hidden="true" />
                        )}
                        {t('templates.settings.capture_button')}
                    </Button>
                </form>
            )}
        </section>
    );
}
