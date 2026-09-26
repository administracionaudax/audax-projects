import { Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { Plus, TriangleAlert, Wallet } from 'lucide-react';
import { useId } from 'react';
import { EmptyState } from '@/components/empty-state';
import { HourBankActions } from '@/components/hour-banks/hour-bank-actions';
import { HourBankCard } from '@/components/hour-banks/hour-bank-card';
import { HourBankFormDialog } from '@/components/hour-banks/hour-bank-form-dialog';
import { HourBankHistory } from '@/components/hour-banks/hour-bank-history';
import { ProjectShell } from '@/components/projects/project-shell';
import { PageSection } from '@/components/projects-list/page-section';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import { index as projectsIndex, show } from '@/routes/projects';
import { index } from '@/routes/projects/hour-banks';
import type { ProjectHourBanksProps } from '@/types';

/**
 * Pestaña «Bolsas» del proyecto (SPEC §8): una tarjeta por bolsa (abiertas por defecto; con el
 * filtro, también cerradas y renovadas), alta, renovación, cierre e histórico de renovaciones.
 */
export default function ProjectHourBanks({
    project,
    canManage,
    banks,
    hiddenCount,
    history,
    filters,
    departments,
    overageDefault,
    can,
}: ProjectHourBanksProps) {
    const id = useId();
    const errors = usePage().props.errors as Record<string, string> | undefined;

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.projects'), href: projectsIndex() },
            { title: project.name, href: show(project.id) },
            { title: t('project_tabs.hour_banks'), href: index(project.id) },
        ],
    });

    const toggleAll = (all: boolean) =>
        router.get(
            index.url(project.id, { query: all ? { todas: 1 } : {} }),
            undefined,
            { preserveScroll: true, preserveState: true, replace: true },
        );

    return (
        <>
            <Head
                title={t('hour_banks.tab.title', { project: project.name })}
            />

            <ProjectShell
                project={project}
                tab="bolsas"
                canManage={canManage}
                actions={
                    can.create ? (
                        <HourBankFormDialog
                            projectId={project.id}
                            departments={departments}
                            overageDefault={overageDefault}
                            trigger={
                                <Button>
                                    <Plus aria-hidden="true" />
                                    {t('hour_banks.tab.create')}
                                </Button>
                            }
                        />
                    ) : null
                }
            >
                <div className="grid gap-8">
                    {errors?.hour_bank ? (
                        <Alert variant="destructive" role="alert">
                            <TriangleAlert aria-hidden="true" />
                            <AlertDescription>
                                {errors.hour_bank}
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    <PageSection
                        title={t('hour_banks.tab.heading')}
                        action={
                            <div className="flex items-center gap-2">
                                <Switch
                                    id={`${id}-all`}
                                    checked={filters.todas}
                                    onCheckedChange={toggleAll}
                                />
                                <Label
                                    htmlFor={`${id}-all`}
                                    className="font-normal"
                                >
                                    {filters.todas
                                        ? t('hour_banks.tab.show_all')
                                        : t('hour_banks.tab.show_all_count', {
                                              count: hiddenCount,
                                          })}
                                </Label>
                            </div>
                        }
                    >
                        {banks.length === 0 ? (
                            <EmptyState
                                icon={Wallet}
                                title={t(
                                    filters.todas
                                        ? 'hour_banks.tab.empty_all'
                                        : 'hour_banks.tab.empty',
                                )}
                                description={t(
                                    can.create
                                        ? 'hour_banks.tab.empty_description_can_create'
                                        : 'hour_banks.tab.empty_description',
                                )}
                            />
                        ) : (
                            <div className="grid gap-4 xl:grid-cols-2">
                                {banks.map((bank) => (
                                    <HourBankCard
                                        key={bank.id}
                                        projectId={project.id}
                                        bank={bank}
                                        headingLevel="h3"
                                        actions={
                                            <HourBankActions
                                                projectId={project.id}
                                                bank={bank}
                                                departments={departments}
                                                overageDefault={overageDefault}
                                            />
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </PageSection>

                    <PageSection title={t('hour_banks.history.heading')}>
                        <HourBankHistory chains={history} />
                    </PageSection>
                </div>
            </ProjectShell>
        </>
    );
}

ProjectHourBanks.layout = {
    breadcrumbs: [{ title: t('nav.projects'), href: projectsIndex() }],
};
