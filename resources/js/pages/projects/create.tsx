import { Head, Link, useForm } from '@inertiajs/react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/projects-list/page-header';
import { PeopleChecklist } from '@/components/projects-list/people-checklist';
import { PersonSelect } from '@/components/projects-list/person-select';
import { ProjectFields } from '@/components/projects-list/project-fields';
import type { ProjectFormData } from '@/components/projects-list/project-fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAbilities } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { create, index, store } from '@/routes/projects';
import type { ProjectCreateProps } from '@/types';

type CreateForm = ProjectFormData & {
    owner_user_id: number | null;
    member_ids: number[];
};

/**
 * Alta de proyecto (SPEC §6, D-022, D-032): datos básicos, planificación, economía (con
 * view-financials) y equipo. El gestor principal es por defecto quien lo crea y siempre queda
 * como miembro gestor.
 */
export default function ProjectCreate({
    clients,
    people,
    defaults,
}: ProjectCreateProps) {
    const id = useId();
    const can = useAbilities();
    const [codeTouched, setCodeTouched] = useState(false);

    const form = useForm<CreateForm>({
        name: '',
        client_id: null,
        billing_type: defaults.billing_type,
        code: '',
        color: defaults.color,
        description: '',
        status: defaults.status,
        start_date: null,
        due_date: null,
        budget_minutes: null,
        fixed_price_amount: '',
        hourly_rate: '',
        owner_user_id: defaults.owner_user_id,
        member_ids: [],
    });

    const set = <K extends keyof CreateForm>(key: K, value: CreateForm[K]) =>
        form.setData((data) => ({ ...data, [key]: value }));

    const errors = form.errors as Partial<Record<string, string>>;

    return (
        <>
            <Head title={t('projects.create.title')} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('projects.create.heading')}
                    description={t('projects.create.description')}
                />

                <form
                    noValidate
                    className="grid max-w-4xl gap-8"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(store(), { preserveScroll: true });
                    }}
                >
                    <ProjectFields
                        data={form.data}
                        set={(key, value) =>
                            form.setData((data) => ({ ...data, [key]: value }))
                        }
                        errors={form.errors}
                        clients={clients}
                        canViewFinancials={can.viewFinancials}
                        codeTouched={codeTouched}
                        onCodeTouched={() => setCodeTouched(true)}
                    />

                    <fieldset className="grid gap-5">
                        <legend className="mb-3 text-base font-medium">
                            {t('projects.form.team')}
                        </legend>
                        <PersonSelect
                            id={`${id}-owner`}
                            label={t('projects.form.owner')}
                            help={t('projects.form.owner_help')}
                            people={people}
                            value={form.data.owner_user_id}
                            onChange={(value) => set('owner_user_id', value)}
                            error={errors.owner_user_id}
                            className="sm:max-w-md"
                        />
                        <PeopleChecklist
                            label={t('projects.form.members')}
                            people={people}
                            selected={form.data.member_ids}
                            locked={
                                form.data.owner_user_id === null
                                    ? []
                                    : [form.data.owner_user_id]
                            }
                            onChange={(ids) => set('member_ids', ids)}
                        />
                        <InputError
                            message={
                                errors.member_ids ??
                                Object.entries(errors).find(([key]) =>
                                    key.startsWith('member_ids.'),
                                )?.[1]
                            }
                        />
                    </fieldset>

                    <div className="flex flex-wrap gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Spinner /> : null}
                            {t('projects.create.submit')}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={index()}>{t('common.cancel')}</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

ProjectCreate.layout = {
    breadcrumbs: [
        { title: t('nav.projects'), href: index() },
        { title: t('projects.create.title'), href: create() },
    ],
};
