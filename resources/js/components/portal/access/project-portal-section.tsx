import { useForm } from '@inertiajs/react';
import { Info, TriangleAlert } from 'lucide-react';
import { useId } from 'react';
import { SwitchField } from '@/components/portal/access/switch-field';
import type { ProjectPortalSettings } from '@/components/portal/access/types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { update } from '@/routes/projects/portal';

type PortalForm = {
    portal_project_visible: boolean;
    portal_show_task_hours: boolean;
    portal_gantt_visible: boolean;
};

/**
 * Sección «Portal del cliente» de los ajustes del proyecto (SPEC §11, D-064): abrir la vista del
 * proyecto (tareas y estados, sin comentarios, adjuntos ni personas), enseñar las horas totales por
 * tarea (solo con la vista abierta) y abrir el Gantt de solo lectura. Un proyecto sin cliente no se
 * puede abrir.
 */
export function ProjectPortalSection({
    projectId,
    portal,
}: {
    projectId: number;
    portal: ProjectPortalSettings | null | undefined;
}) {
    const id = useId();
    const form = useForm<PortalForm>({
        portal_project_visible: portal?.project_visible ?? false,
        portal_show_task_hours:
            (portal?.project_visible ?? false) &&
            (portal?.show_task_hours ?? false),
        portal_gantt_visible: portal?.gantt_visible ?? false,
    });

    if (!portal) {
        return null;
    }

    if (portal.client === null) {
        return (
            <p
                className="flex items-start gap-2 text-sm text-muted-foreground"
                data-test="project-portal-no-client"
            >
                <Info aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                {t('portal_access.project.no_client')}
            </p>
        );
    }

    const { data } = form;
    const dirty =
        data.portal_project_visible !== portal.project_visible ||
        data.portal_show_task_hours !==
            (portal.project_visible && portal.show_task_hours) ||
        data.portal_gantt_visible !== portal.gantt_visible;

    return (
        <form
            noValidate
            className="grid gap-5"
            data-test="project-portal"
            onSubmit={(event) => {
                event.preventDefault();
                form.submit(update(projectId), { preserveScroll: true });
            }}
        >
            {portal.client.is_active ? null : (
                <Alert role="status">
                    <TriangleAlert aria-hidden="true" />
                    <AlertDescription>
                        {t('portal_access.project.client_inactive', {
                            client: portal.client.name,
                        })}
                    </AlertDescription>
                </Alert>
            )}

            <p className="text-sm text-muted-foreground">
                {portal.active_users === 0
                    ? t('portal_access.project.no_users', {
                          client: portal.client.name,
                      })
                    : portal.active_users === 1
                      ? t('portal_access.project.users_one', {
                            client: portal.client.name,
                        })
                      : t('portal_access.project.users_many', {
                            client: portal.client.name,
                            count: portal.active_users,
                        })}
            </p>

            <SwitchField
                id={`${id}-view`}
                label={t('portal_access.project.view')}
                help={t('portal_access.project.view_help')}
                checked={data.portal_project_visible}
                onChange={(checked) =>
                    form.setData((current) => ({
                        ...current,
                        portal_project_visible: checked,
                        portal_show_task_hours: checked
                            ? current.portal_show_task_hours
                            : false,
                    }))
                }
                error={form.errors.portal_project_visible}
            />
            <SwitchField
                id={`${id}-hours`}
                label={t('portal_access.project.hours')}
                help={t('portal_access.project.hours_help')}
                checked={data.portal_show_task_hours}
                disabled={!data.portal_project_visible}
                onChange={(checked) =>
                    form.setData('portal_show_task_hours', checked)
                }
                error={form.errors.portal_show_task_hours}
                className="sm:pl-11"
            />
            <SwitchField
                id={`${id}-gantt`}
                label={t('portal_access.project.gantt')}
                help={t('portal_access.project.gantt_help')}
                checked={data.portal_gantt_visible}
                onChange={(checked) =>
                    form.setData('portal_gantt_visible', checked)
                }
                error={form.errors.portal_gantt_visible}
            />

            <div>
                <Button
                    type="submit"
                    disabled={form.processing || !dirty}
                    data-test="project-portal-save"
                >
                    {form.processing ? <Spinner /> : null}
                    {t('common.save')}
                </Button>
            </div>
        </form>
    );
}
