import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { weekliesTabs } from '@/components/weeklies/insights/weeklies-tabs';
import { ReminderLog } from '@/components/weeklies/reminders/reminder-log';
import { ReminderRulesEditor } from '@/components/weeklies/reminders/reminder-rules-editor';
import { SendRemindersDialog } from '@/components/weeklies/reminders/send-reminders-dialog';
import {
    EDITABLE_TEMPLATES,
    TemplateEditor,
} from '@/components/weeklies/reminders/template-editor';
import { WeeklyTabs } from '@/components/weeklies/weekly-tabs';
import { useRequiredUser } from '@/hooks/use-auth';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit as notificationSettings } from '@/routes/notification-settings';
import { index as weekliesIndex } from '@/routes/weeklies';
import { edit, update } from '@/routes/weeklies/reminders';
import type {
    WeeklyEditableTemplate,
    WeeklyEmailTemplate,
    WeeklyReminderRuleInput,
    WeeklyRemindersPageProps,
} from '@/types/weeklies';

type RemindersForm = {
    rules: WeeklyReminderRuleInput[];
    templates: Record<WeeklyEditableTemplate, WeeklyEmailTemplate>;
    friday_reminder: boolean;
};

/**
 * /weeklies/avisos (F-037 y F-101 a F-110, D-199 a D-201): el NotificationSettingsView de WeeklySync
 * como cuarta pestaña de /weeklies, para quien gestiona la Weekly:
 * - los recordatorios programados (día, hora de Madrid y canal) y la weekly en el recordatorio de
 *   los viernes,
 * - los textos de los avisos con su vista previa y «Restaurar por defecto»,
 * - enviar un recordatorio ahora a las personas pendientes y el registro de todo lo enviado.
 */
export default function WeekliesReminders(props: WeeklyRemindersPageProps) {
    const {
        cycle,
        rules,
        templates,
        defaults,
        variables,
        friday,
        pending,
        logs,
        filters,
        push_available: pushAvailable,
        can,
    } = props;
    const id = useId();
    const user = useRequiredUser();
    const { config, module_preview: preview } = usePage().props;
    const modules = config?.modules;
    const editable = (source: {
        rules: typeof rules;
        templates: typeof templates;
        friday: typeof friday;
    }): RemindersForm => ({
        rules: source.rules.map((rule) => ({
            id: rule.id,
            channel: rule.channel,
            day_of_week: rule.day_of_week,
            time: rule.time,
            enabled: rule.enabled,
        })),
        templates: {
            automatic: pick(source.templates.automatic),
            manual: pick(source.templates.manual),
            weekly_closed: pick(source.templates.weekly_closed),
        },
        friday_reminder: source.friday.weekly,
    });
    const form = useForm<RemindersForm>(editable({ rules, templates, friday }));
    const errors = form.errors as Record<string, string | undefined>;
    const origin = typeof window === 'undefined' ? '' : window.location.origin;
    const previewValues = (template: WeeklyEditableTemplate) => ({
        nombre: user.name,
        semana: cycle?.number ?? 'W41-26',
        week_label: cycle?.label ?? 'Semana 41 (Lun 05/10 - Vie 09/10)',
        weekly_url:
            template === 'weekly_closed'
                ? `${origin}/weeklies/${cycle?.id ?? 1}`
                : `${origin}/mi-espacio?semana=${cycle?.id ?? 1}`,
    });

    return (
        <>
            <Head title={t('weekly_reminders.title')} />
            <div className="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('weeklies.heading')}
                    description={t('weeklies.description')}
                />
                <WeeklyTabs
                    label={t('weeklies.tabs.label')}
                    tabs={weekliesTabs(modules?.project_status !== false, true)}
                    current="avisos"
                />

                <div className="grid gap-1">
                    <h2 className="text-lg">{t('weekly_reminders.heading')}</h2>
                    <p className="text-sm text-muted-foreground">
                        {t('weekly_reminders.description')}
                    </p>
                    <p className="text-sm" data-test="reminders-cycle">
                        {cycle === null
                            ? t('weekly_reminders.cycle_none')
                            : t('weekly_reminders.cycle_active', {
                                  label: cycle.label,
                                  deadline: formatDate(cycle.deadline_date),
                              })}
                    </p>
                </div>

                <form
                    className="grid gap-6"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(update.url(), {
                            preserveScroll: true,
                            // Las reglas nuevas ya tienen id: sin esto, cada «Guardar» las borraba y
                            // las volvía a crear (y llenaba la auditoría). D-310.
                            onSuccess: (page) => {
                                const next = editable(
                                    page.props as unknown as {
                                        rules: typeof rules;
                                        templates: typeof templates;
                                        friday: typeof friday;
                                    },
                                );
                                form.setDefaults(next);
                                form.setData(next);
                            },
                        });
                    }}
                >
                    <Panel
                        title={t('weekly_reminders.rules.title')}
                        description={t('weekly_reminders.rules.description')}
                    >
                        <ReminderRulesEditor
                            rules={form.data.rules}
                            errors={errors}
                            pushAvailable={pushAvailable}
                            onChange={(next) => form.setData('rules', next)}
                        />
                    </Panel>

                    <Panel title={t('weekly_reminders.friday.title')}>
                        <div className="flex items-start gap-3">
                            <Switch
                                id={`${id}-friday`}
                                checked={form.data.friday_reminder}
                                onCheckedChange={(checked) =>
                                    form.setData('friday_reminder', checked)
                                }
                                aria-describedby={`${id}-friday-help`}
                                data-test="reminders-friday"
                            />
                            <div className="grid gap-1">
                                <Label htmlFor={`${id}-friday`}>
                                    {t('weekly_reminders.friday.label')}
                                </Label>
                                <p
                                    id={`${id}-friday-help`}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('weekly_reminders.friday.help')}
                                </p>
                                {!friday.hours ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('weekly_reminders.friday.hours_off')}
                                    </p>
                                ) : null}
                                <InputError message={errors.friday_reminder} />
                            </div>
                        </div>
                    </Panel>

                    <Panel
                        title={t('weekly_reminders.templates.title')}
                        description={t(
                            'weekly_reminders.templates.description',
                        )}
                    >
                        <div className="grid gap-4">
                            {EDITABLE_TEMPLATES.map((name) => (
                                <TemplateEditor
                                    key={name}
                                    name={name}
                                    value={form.data.templates[name]}
                                    fallback={defaults[name]}
                                    variables={variables}
                                    previewValues={previewValues(name)}
                                    previewName={user.name}
                                    errors={errors}
                                    onChange={(value) =>
                                        form.setData('templates', {
                                            ...form.data.templates,
                                            [name]: value,
                                        })
                                    }
                                />
                            ))}
                        </div>
                    </Panel>

                    {/* La confirmación llega como aviso (toast) desde el servidor. */}
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="reminders-save"
                        >
                            {form.processing && <Spinner />}
                            {t('weekly_reminders.save')}
                        </Button>
                    </div>
                </form>

                <Panel
                    title={t('weekly_reminders.send.title')}
                    description={t('weekly_reminders.send.description')}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        {cycle !== null && can.send ? (
                            <SendRemindersDialog
                                cycleLabel={cycle.label}
                                pending={pending}
                            />
                        ) : null}
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="reminders-pending"
                        >
                            {cycle === null
                                ? t('weekly_reminders.cycle_none')
                                : pending.length === 0
                                  ? t('weekly_reminders.send.nobody')
                                  : pending.length === 1
                                    ? t('weekly_reminders.send.pending_one')
                                    : t('weekly_reminders.send.pending_many', {
                                          count: pending.length,
                                      })}
                        </p>
                    </div>
                    {/* Modo de prueba (D-239): ni el envío ni «Recordar» avisan a nadie. */}
                    {preview === true ? (
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="reminders-preview"
                        >
                            {t('weeklies.preview.no_notices')}
                        </p>
                    ) : null}
                </Panel>

                <Panel
                    title={t('weekly_reminders.log.title')}
                    description={t('weekly_reminders.log.description')}
                >
                    <ReminderLog logs={logs} filters={filters} />
                </Panel>

                <aside
                    aria-labelledby={`${id}-info`}
                    className="grid gap-2 border bg-card p-4 text-sm"
                >
                    <h2
                        id={`${id}-info`}
                        className="flex items-center gap-2 font-medium"
                    >
                        <Info
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                        {t('weekly_reminders.info.title')}
                    </h2>
                    <ul className="grid list-disc gap-1 pl-5 text-muted-foreground">
                        <li>{t('weekly_reminders.info.only_pending')}</li>
                        <li>{t('weekly_reminders.info.server')}</li>
                        <li>{t('weekly_reminders.info.once')}</li>
                        <li>{t('weekly_reminders.info.preferences')}</li>
                        <li>{t('weekly_reminders.info.push')}</li>
                        <li>{t('weekly_reminders.info.digest')}</li>
                    </ul>
                    <Link
                        href={notificationSettings.url()}
                        className={cn('w-fit underline', FOCUS_RING)}
                    >
                        {t('weekly_reminders.info.preferences_link')}
                    </Link>
                </aside>
            </div>
        </>
    );
}

function pick(template: WeeklyEmailTemplate): WeeklyEmailTemplate {
    return { subject: template.subject, body: template.body };
}

function Panel({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    const id = useId();

    return (
        <section aria-labelledby={id} className="grid min-w-0 gap-4">
            <div className="grid gap-1">
                <h2 id={id} className="text-base font-medium">
                    {title}
                </h2>
                {description ? (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
            {children}
        </section>
    );
}

WeekliesReminders.layout = {
    breadcrumbs: [
        { title: t('weeklies.title'), href: weekliesIndex() },
        { title: t('weekly_reminders.title'), href: edit() },
    ],
};
