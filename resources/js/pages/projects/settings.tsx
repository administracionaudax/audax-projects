import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePage,
} from '@inertiajs/react';
import { Archive, ArchiveRestore, TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { ProjectShell } from '@/components/projects/project-shell';
import { PageSection } from '@/components/projects-list/page-section';
import { PersonSelect } from '@/components/projects-list/person-select';
import { ProjectAlerts } from '@/components/projects-list/project-alerts';
import { ProjectFields } from '@/components/projects-list/project-fields';
import type { ProjectFormData } from '@/components/projects-list/project-fields';
import { ProjectMembers } from '@/components/projects-list/project-members';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAbilities } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import {
    archive,
    index,
    settings,
    show,
    unarchive,
    update,
} from '@/routes/projects';
import { update as updateOwner } from '@/routes/projects/owner';
import type { ProjectSettingsProps } from '@/types';

/**
 * Ajustes del proyecto (SPEC §6, D-005, D-023, D-032, D-037): datos, miembros y gestores,
 * gestor principal, alertas de cada gestor y archivo. Solo para quien lo gestiona.
 */
export default function ProjectSettings({
    project,
    canManage,
    members,
    clients,
    people,
    hasHourBanks,
    can,
}: ProjectSettingsProps) {
    const abilities = useAbilities();
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const archived = project.status === 'archived';

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.projects'), href: index() },
            { title: project.name, href: show(project.id) },
            { title: t('project_tabs.settings'), href: settings(project.id) },
        ],
    });

    return (
        <>
            <Head
                title={t('projects.settings.title', { project: project.name })}
            />

            <ProjectShell project={project} tab="ajustes" canManage={canManage}>
                <div className="grid max-w-4xl gap-10">
                    {archived ? (
                        <Alert role="status">
                            <Archive aria-hidden="true" />
                            <AlertTitle>
                                {t('projects.settings.archived_title')}
                            </AlertTitle>
                            <AlertDescription>
                                {t('projects.settings.archived_description')}
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    <PageSection
                        title={t('projects.settings.data')}
                        description={t('projects.settings.data_description')}
                    >
                        <ProjectDataForm
                            project={project}
                            clients={clients}
                            hasHourBanks={hasHourBanks}
                            canViewFinancials={abilities.viewFinancials}
                        />
                    </PageSection>

                    <PageSection
                        title={t('projects.settings.team')}
                        description={t('projects.settings.team_description')}
                    >
                        {errors?.is_manager || errors?.user_id ? (
                            <Alert variant="destructive" role="alert">
                                <TriangleAlert aria-hidden="true" />
                                <AlertDescription>
                                    {errors.is_manager ?? errors.user_id}
                                </AlertDescription>
                            </Alert>
                        ) : null}
                        <ProjectMembers
                            projectId={project.id}
                            members={members}
                            people={people}
                            canManage={can.manageMembers}
                        />
                    </PageSection>

                    {can.manageMembers ? (
                        <PageSection
                            title={t('projects.settings.owner')}
                            description={t(
                                'projects.settings.owner_description',
                            )}
                        >
                            <OwnerForm
                                projectId={project.id}
                                ownerId={project.owner_user_id}
                                people={people}
                            />
                        </PageSection>
                    ) : null}

                    <PageSection
                        title={t('projects.settings.alerts')}
                        description={t('projects.settings.alerts_description')}
                    >
                        <ProjectAlerts
                            projectId={project.id}
                            managers={members.filter(
                                (member) => member.is_manager,
                            )}
                            editable={can.editAlertsOf}
                        />
                    </PageSection>

                    {can.archive ? (
                        <PageSection
                            title={t('projects.settings.archive')}
                            description={t(
                                archived
                                    ? 'projects.settings.unarchive_description'
                                    : 'projects.settings.archive_description',
                            )}
                        >
                            <ArchiveButton
                                projectId={project.id}
                                archived={archived}
                            />
                        </PageSection>
                    ) : null}
                </div>
            </ProjectShell>
        </>
    );
}

ProjectSettings.layout = {
    breadcrumbs: [{ title: t('nav.projects'), href: index() }],
};

function ProjectDataForm({
    project,
    clients,
    hasHourBanks,
    canViewFinancials,
}: Pick<ProjectSettingsProps, 'project' | 'clients' | 'hasHourBanks'> & {
    canViewFinancials: boolean;
}) {
    const archived = project.status === 'archived';
    const form = useForm<ProjectFormData>({
        name: project.name,
        client_id: project.client_id,
        billing_type: project.billing_type,
        code: project.code,
        color: project.color,
        description: project.description ?? '',
        status: project.status,
        start_date: project.start_date,
        due_date: project.due_date,
        budget_minutes: project.budget_minutes,
        fixed_price_amount: project.fixed_price_amount ?? '',
        hourly_rate: project.hourly_rate ?? '',
    });

    return (
        <form
            noValidate
            className="grid gap-6"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => {
                    const payload: Partial<ProjectFormData> = { ...data };

                    // Archivado: el estado se recupera con su botón, no desde aquí.
                    if (archived) {
                        delete payload.status;
                    }

                    if (!canViewFinancials) {
                        delete payload.fixed_price_amount;
                        delete payload.hourly_rate;
                    }

                    return payload;
                });
                form.submit(update(project.id), { preserveScroll: true });
            }}
        >
            <ProjectFields
                data={form.data}
                set={(key, value) =>
                    form.setData((data) => ({ ...data, [key]: value }))
                }
                errors={form.errors}
                clients={clients}
                canViewFinancials={canViewFinancials}
                statusEditable={!archived}
                creating={false}
                codeTouched
            />
            {hasHourBanks && form.data.billing_type !== 'hour_bank' ? (
                <p className="flex items-start gap-2 text-sm text-foreground">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    {t('projects.settings.has_hour_banks')}
                </p>
            ) : null}
            <div>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? <Spinner /> : null}
                    {t('common.save')}
                </Button>
            </div>
        </form>
    );
}

function OwnerForm({
    projectId,
    ownerId,
    people,
}: {
    projectId: number;
    ownerId: number;
    people: ProjectSettingsProps['people'];
}) {
    const id = useId();
    const form = useForm<{ owner_user_id: number | null }>({
        owner_user_id: ownerId,
    });

    return (
        <form
            noValidate
            className="flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.submit(updateOwner(projectId), { preserveScroll: true });
            }}
        >
            <PersonSelect
                id={`${id}-owner`}
                label={t('projects.form.owner')}
                people={people}
                value={form.data.owner_user_id}
                onChange={(value) => form.setData('owner_user_id', value)}
                className="min-w-0 flex-1 basis-64 sm:max-w-md"
            />
            <Button
                type="submit"
                variant="outline"
                disabled={
                    form.processing || form.data.owner_user_id === ownerId
                }
            >
                {form.processing ? <Spinner /> : null}
                {t('projects.settings.change_owner')}
            </Button>
            <InputError
                message={form.errors.owner_user_id}
                className="basis-full"
            />
        </form>
    );
}

function ArchiveButton({
    projectId,
    archived,
}: {
    projectId: number;
    archived: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const run = () => {
        router.post(
            archived ? unarchive.url(projectId) : archive.url(projectId),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setOpen(false);
                },
            },
        );
    };

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={setOpen}
            destructive={!archived}
            trigger={
                <Button variant="outline" className="w-fit">
                    {archived ? (
                        <ArchiveRestore aria-hidden="true" />
                    ) : (
                        <Archive aria-hidden="true" />
                    )}
                    {t(
                        archived
                            ? 'projects.settings.unarchive_button'
                            : 'projects.settings.archive_button',
                    )}
                </Button>
            }
            title={t(
                archived
                    ? 'projects.settings.unarchive_title'
                    : 'projects.settings.archive_title',
            )}
            description={t(
                archived
                    ? 'projects.settings.unarchive_confirm'
                    : 'projects.settings.archive_confirm',
            )}
            confirmLabel={t(
                archived
                    ? 'projects.settings.unarchive_button'
                    : 'projects.settings.archive_button',
            )}
            processing={processing}
            onConfirm={run}
        />
    );
}
