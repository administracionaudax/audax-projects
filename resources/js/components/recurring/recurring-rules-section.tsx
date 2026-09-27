import { Link, router } from '@inertiajs/react';
import {
    Archive,
    CirclePause,
    CirclePlay,
    Pencil,
    Plus,
    Repeat,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { TaskStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { DeferredSection } from '@/components/templates/deferred-section';
import { ActiveBadge, countText } from '@/components/templates/template-badges';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { destroy, status } from '@/routes/recurring';
import type {
    ProjectRecurringSettings,
    RecurringRuleItem,
} from '@/types/templates';
import { RECURRING_RELOAD, RecurringRuleDialog } from './recurring-rule-dialog';

/**
 * Sección «Tareas recurrentes» de los Ajustes del proyecto (D-059): reglas con su frase, próxima
 * fecha, responsable y avisos; crear, editar, activar o desactivar y borrar; y las últimas tareas
 * que han creado. Sus datos llegan como prop diferida (`recurring`).
 */
export function RecurringRulesSection({ projectId }: { projectId: number }) {
    return (
        <DeferredSection<ProjectRecurringSettings>
            prop="recurring"
            loadingLabel={t('recurring.section.loading')}
        >
            {(settings) => (
                <RecurringRules projectId={projectId} settings={settings} />
            )}
        </DeferredSection>
    );
}

export function RecurringRules({
    projectId,
    settings,
}: {
    projectId: number;
    settings: ProjectRecurringSettings;
}) {
    const { rules, recent, options, archived, today } = settings;

    return (
        <div className="grid gap-6">
            {archived ? (
                <Alert role="status">
                    <Archive aria-hidden="true" />
                    <AlertDescription>
                        {t('recurring.section.archived')}
                    </AlertDescription>
                </Alert>
            ) : (
                <div>
                    <RecurringRuleDialog
                        projectId={projectId}
                        options={options}
                        today={today}
                        trigger={
                            <Button variant="outline">
                                <Plus aria-hidden="true" />
                                {t('recurring.actions.new')}
                            </Button>
                        }
                    />
                </div>
            )}

            {rules.length === 0 ? (
                <EmptyState
                    icon={Repeat}
                    title={t('recurring.section.empty')}
                    description={t('recurring.section.empty_description')}
                />
            ) : (
                <ul
                    className="grid divide-y rounded-md border"
                    aria-label={t('recurring.section.rules_label')}
                >
                    {rules.map((rule) => (
                        <RuleItem
                            key={rule.id}
                            rule={rule}
                            projectId={projectId}
                            archived={archived}
                            options={options}
                            today={today}
                        />
                    ))}
                </ul>
            )}

            <section
                aria-labelledby={`recent-${projectId}`}
                className="grid gap-2"
            >
                <h3
                    id={`recent-${projectId}`}
                    className="text-base font-medium"
                >
                    {t('recurring.section.recent')}
                </h3>
                {recent.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('recurring.section.recent_empty')}
                    </p>
                ) : (
                    <ul
                        className="grid gap-1 text-sm"
                        data-test="recurring-recent"
                    >
                        {recent.map((task) => (
                            <li
                                key={task.id}
                                className="flex flex-wrap items-center gap-x-3 gap-y-1"
                            >
                                <span className="text-muted-foreground tabular-nums">
                                    {formatDate(task.occurrence_date)}
                                </span>
                                <Link
                                    href={urls.task(projectId, task.id)}
                                    className={cn(
                                        'rounded-sm text-primary-text hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {task.title}
                                </Link>
                                <TaskStatusBadge
                                    name={task.status.name}
                                    color={task.status.color}
                                    done={task.status.category === 'done'}
                                />
                                {task.assignee ? (
                                    <span className="text-muted-foreground">
                                        {task.assignee.name}
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

function RuleItem({
    rule,
    projectId,
    archived,
    options,
    today,
}: {
    rule: RecurringRuleItem;
    projectId: number;
    archived: boolean;
    options: ProjectRecurringSettings['options'];
    today: string;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const visit = {
        preserveScroll: true,
        only: RECURRING_RELOAD,
        onError: toastVisitErrors,
    };

    return (
        <li className="grid gap-2 p-3" data-test="recurring-rule">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid min-w-0 gap-1">
                    <p className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{rule.title}</span>
                        <ActiveBadge active={rule.is_active} kind="rule" />
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {rule.summary}
                    </p>
                </div>
                <div className="flex gap-1">
                    {!archived ? (
                        <RecurringRuleDialog
                            projectId={projectId}
                            options={options}
                            today={today}
                            rule={rule}
                            trigger={
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    aria-label={t(
                                        'recurring.actions.edit_label',
                                        {
                                            title: rule.title,
                                        },
                                    )}
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                            }
                        />
                    ) : null}
                    {!archived || rule.is_active ? (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={
                                rule.is_active
                                    ? t('recurring.actions.deactivate_label', {
                                          title: rule.title,
                                      })
                                    : t('recurring.actions.activate_label', {
                                          title: rule.title,
                                      })
                            }
                            onClick={() =>
                                router.put(
                                    status.url({
                                        project: projectId,
                                        rule: rule.id,
                                    }),
                                    { is_active: !rule.is_active },
                                    visit,
                                )
                            }
                        >
                            {rule.is_active ? (
                                <CirclePause aria-hidden="true" />
                            ) : (
                                <CirclePlay aria-hidden="true" />
                            )}
                        </Button>
                    ) : null}
                    <ConfirmDialog
                        open={confirming}
                        onOpenChange={setConfirming}
                        trigger={
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                aria-label={t(
                                    'recurring.actions.delete_label',
                                    {
                                        title: rule.title,
                                    },
                                )}
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        }
                        title={t('recurring.actions.delete_title', {
                            title: rule.title,
                        })}
                        description={t('recurring.actions.delete_description')}
                        confirmLabel={t('recurring.actions.delete')}
                        processing={processing}
                        onConfirm={() =>
                            router.delete(
                                destroy.url({
                                    project: projectId,
                                    rule: rule.id,
                                }),
                                {
                                    ...visit,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setConfirming(false);
                                    },
                                },
                            )
                        }
                    />
                </div>
            </div>

            <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt className="text-muted-foreground">
                        {t('recurring.list.next')}
                    </dt>
                    <dd>
                        {rule.next_date === null
                            ? t('recurring.list.no_next')
                            : rule.next_date === today
                              ? t('recurring.list.today', {
                                    date: formatDate(rule.next_date),
                                })
                              : formatDate(rule.next_date)}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">
                        {t('recurring.list.assignee')}
                    </dt>
                    <dd>{rule.assignee?.name ?? t('recurring.list.nobody')}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">
                        {t('recurring.list.due')}
                    </dt>
                    <dd>
                        {rule.due_offset_days === 0
                            ? t('recurring.list.due_same_day')
                            : countText(
                                  rule.due_offset_days,
                                  'recurring.list.due_days_one',
                                  'recurring.list.due_days_other',
                              )}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">
                        {rule.hour_bank
                            ? t('recurring.list.bank')
                            : t('recurring.list.estimate')}
                    </dt>
                    <dd>
                        {rule.hour_bank
                            ? rule.hour_bank.name
                            : rule.estimated_minutes === null
                              ? t('recurring.list.no_estimate')
                              : formatMinutes(rule.estimated_minutes)}
                    </dd>
                </div>
            </dl>

            {rule.warnings.length > 0 ? (
                <ul className="grid gap-1">
                    {rule.warnings.map((warning) => (
                        <li
                            key={warning}
                            className="flex items-start gap-2 text-sm text-foreground"
                        >
                            <TriangleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-warning"
                            />
                            {warning}
                        </li>
                    ))}
                </ul>
            ) : null}
        </li>
    );
}
