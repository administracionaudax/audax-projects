import { Head, Link, setLayoutProps, useForm } from '@inertiajs/react';
import { Download, Save } from 'lucide-react';
import { useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { PageSection } from '@/components/projects-list/page-section';
import { TemplateEditor } from '@/components/templates/template-editor';
import { rowLabels } from '@/components/templates/template-editor';
import type { EditorRow } from '@/components/templates/template-editor-state';
import {
    mapErrors,
    rowsFromStructure,
    structureFromRows,
} from '@/components/templates/template-editor-state';
import { TemplateTimeline } from '@/components/templates/template-timeline';
import { TemplatesAdminFrame } from '@/components/templates/templates-admin-frame';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { index as adminIndex } from '@/routes/admin';
import {
    create,
    edit,
    exportMethod,
    index as templatesIndex,
    store,
    update,
} from '@/routes/templates';
import type { TemplateEditProps } from '@/types/templates';

type TemplateForm = {
    name: string;
    description: string;
    is_active: boolean;
};

/**
 * Crear, editar o duplicar una plantilla (D-058): nombre, descripción, si está activa y el editor
 * de su estructura con la vista previa del cronograma. La estructura se valida en el servidor
 * (TemplateStructure y ProjectTemplateService::normalize) y los errores vuelven junto a cada campo.
 */
export default function TemplateEdit({
    template,
    types,
    priorities,
    limits,
}: TemplateEditProps) {
    const id = useId();
    const saved = template !== null && template.id !== null;
    const [rows, setRows] = useState<EditorRow[]>(() =>
        template ? rowsFromStructure(template.structure) : [],
    );
    // Filas tal y como se enviaron: los errores del servidor vienen por su posición.
    const [submitted, setSubmitted] = useState<EditorRow[]>([]);
    const form = useForm<TemplateForm>({
        name: template?.name ?? '',
        description: template?.description ?? '',
        is_active: template?.is_active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const editorErrors = mapErrors(errors, submitted);
    const heading = saved
        ? t('templates.edit.heading', { name: template.name })
        : template !== null
          ? t('templates.edit.duplicate_heading')
          : t('templates.edit.new_heading');

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.admin'), href: adminIndex() },
            { title: t('templates.index.title'), href: templatesIndex() },
            {
                title: saved ? template.name : t('templates.edit.new_title'),
                href:
                    saved && template.id !== null
                        ? edit(template.id)
                        : create(),
            },
        ],
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setSubmitted(rows);
        form.transform((data) => ({
            ...data,
            structure: structureFromRows(rows),
        }));

        const options = { preserveScroll: true };

        if (saved && template.id !== null) {
            form.put(update.url(template.id), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <>
            <Head
                title={saved ? template.name : t('templates.edit.new_title')}
            />

            <TemplatesAdminFrame
                section="templates"
                title={heading}
                description={t('templates.edit.description')}
                actions={
                    saved && template.id !== null ? (
                        <Button variant="outline" asChild>
                            <a href={exportMethod.url(template.id)} download>
                                <Download aria-hidden="true" />
                                {t('templates.actions.export')}
                            </a>
                        </Button>
                    ) : null
                }
            >
                <form noValidate onSubmit={submit} className="grid gap-10">
                    <PageSection title={t('templates.edit.data')}>
                        <div className="grid max-w-3xl gap-5">
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
                                    autoComplete="off"
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                    aria-describedby={describedBy(
                                        `${id}-name`,
                                        {
                                            error: errors.name,
                                        },
                                    )}
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
                            >
                                <Textarea
                                    id={`${id}-description`}
                                    value={form.data.description}
                                    maxLength={2000}
                                    rows={3}
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
                            <div className="flex items-start gap-3">
                                <Switch
                                    id={`${id}-active`}
                                    checked={form.data.is_active}
                                    aria-describedby={`${id}-active-help`}
                                    onCheckedChange={(checked) =>
                                        form.setData('is_active', checked)
                                    }
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor={`${id}-active`}>
                                        {t('templates.fields.is_active')}
                                    </Label>
                                    <p
                                        id={`${id}-active-help`}
                                        className="text-sm text-muted-foreground"
                                    >
                                        {t('templates.fields.is_active_help')}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </PageSection>

                    <PageSection
                        title={t('templates.edit.structure')}
                        description={t('templates.edit.structure_description')}
                    >
                        <TemplateEditor
                            rows={rows}
                            onChange={setRows}
                            errors={editorErrors}
                            types={types}
                            priorities={priorities}
                            maxTasks={limits.max_tasks}
                            maxDays={limits.max_days}
                        />
                    </PageSection>

                    <PageSection title={t('templates.edit.preview')}>
                        <TemplateTimeline
                            rows={rows}
                            labels={rowLabels(rows)}
                        />
                    </PageSection>

                    <div className="flex flex-wrap gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <Save aria-hidden="true" />
                            )}
                            {saved
                                ? t('templates.edit.save')
                                : t('templates.edit.create')}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={templatesIndex()}>
                                {t('common.cancel')}
                            </Link>
                        </Button>
                    </div>
                </form>
            </TemplatesAdminFrame>
        </>
    );
}

TemplateEdit.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('templates.index.title'), href: templatesIndex() },
    ],
};
