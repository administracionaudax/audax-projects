import { Head, useForm } from '@inertiajs/react';
import { FilePenLine, Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import InputError from '@/components/input-error';
import { KeywordText } from '@/components/keyword-text';
import {
    RetentionFields,
    retentionPayload,
    retentionValues,
} from '@/components/privacy/retention-fields';
import type { RetentionValue } from '@/components/privacy/retention-fields';
import { SafeMarkdown } from '@/components/privacy/safe-markdown';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { index as adminIndex } from '@/routes/admin';
import { edit, update } from '@/routes/admin/privacy';
import type { AdminPrivacyProps } from '@/types/privacy';

type PrivacyForm = {
    notice: string;
    retention: Record<string, RetentionValue>;
    personal_data_export_days: string;
    disk_warning_percent: string;
    attachments_warning_gb: string;
};

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2 className="text-base font-medium">{title}</h2>
                </CardTitle>
                {description ? (
                    <CardDescription>{description}</CardDescription>
                ) : null}
            </CardHeader>
            <CardContent className="grid gap-5">{children}</CardContent>
        </Card>
    );
}

/** Número entero con su unidad (días, %, GB). */
function NumberField({
    id,
    label,
    help,
    unit,
    value,
    min,
    max,
    error,
    optional,
    onChange,
}: {
    id: string;
    label: string;
    help: string;
    unit: string;
    value: string;
    min: number;
    max: number;
    error?: string;
    optional?: string;
    onChange: (value: string) => void;
}) {
    return (
        <Field
            id={id}
            label={label}
            help={help}
            error={error}
            optional={optional}
        >
            <div className="flex items-center gap-2">
                <Input
                    id={id}
                    type="number"
                    inputMode="numeric"
                    min={min}
                    max={max}
                    step={1}
                    className="tabular w-28"
                    value={value}
                    required={optional === undefined}
                    onChange={(event) => onChange(event.target.value)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy(id, { help: true, error })}
                />
                <span
                    aria-hidden="true"
                    className="text-sm text-muted-foreground"
                >
                    {unit}
                </span>
            </div>
        </Field>
    );
}

/**
 * /admin/privacidad (D-075 y D-076): el texto informativo en markdown con su vista previa
 * saneada y su versión (cambiarlo pide una nueva lectura a toda la plantilla), los plazos de
 * conservación, los días para descargar los datos personales y los avisos de almacenamiento.
 */
export default function AdminPrivacy({
    notice,
    readers,
    settings,
    retention,
    limits,
}: AdminPrivacyProps) {
    const id = useId();
    const form = useForm<PrivacyForm>({
        notice: notice.markdown,
        retention: retentionValues(retention, settings),
        personal_data_export_days: String(settings.personal_data_export_days),
        disk_warning_percent: String(settings.disk_warning_percent),
        attachments_warning_gb:
            settings.attachments_warning_gb === null
                ? ''
                : String(settings.attachments_warning_gb),
    });
    const errors = form.errors as Partial<Record<string, string>>;
    const changedText = form.data.notice.trim() !== notice.markdown.trim();
    const length = form.data.notice.length;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            notice: data.notice,
            ...retentionPayload(data.retention),
            personal_data_export_days: Number(data.personal_data_export_days),
            disk_warning_percent: Number(data.disk_warning_percent),
            attachments_warning_gb:
                data.attachments_warning_gb.trim() === ''
                    ? null
                    : Number(data.attachments_warning_gb),
        }));
        form.put(update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('privacy.admin.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight">
                        <KeywordText text={t('privacy.admin.heading')} />
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('privacy.admin.description')}
                    </p>
                </header>

                <form onSubmit={submit} className="grid gap-6" noValidate>
                    <Section
                        title={t('privacy.admin.notice_title')}
                        description={t('privacy.admin.notice_description')}
                    >
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <StatusBadge tone="neutral" icon={Info}>
                                {t('privacy.admin.version', {
                                    version: notice.version,
                                })}
                            </StatusBadge>
                            {notice.is_draft ? (
                                <StatusBadge tone="warning" icon={FilePenLine}>
                                    {t('privacy.page.draft')}
                                </StatusBadge>
                            ) : null}
                            <span className="text-muted-foreground">
                                {t('privacy.admin.readers', {
                                    read: readers.read,
                                    total: readers.total,
                                })}
                            </span>
                        </div>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`${id}-notice`}>
                                    {t('privacy.admin.notice_label')}
                                </Label>
                                <Textarea
                                    id={`${id}-notice`}
                                    value={form.data.notice}
                                    onChange={(event) =>
                                        form.setData(
                                            'notice',
                                            event.target.value,
                                        )
                                    }
                                    rows={18}
                                    maxLength={notice.max_length}
                                    spellCheck
                                    className="min-h-80 font-mono text-sm md:text-sm"
                                    aria-invalid={
                                        errors.notice ? true : undefined
                                    }
                                    aria-describedby={`${id}-notice-help ${id}-notice-count${errors.notice ? ` ${id}-notice-error` : ''}`}
                                />
                                <p
                                    id={`${id}-notice-help`}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('privacy.admin.notice_help')}
                                </p>
                                <p
                                    id={`${id}-notice-count`}
                                    className="tabular text-xs text-muted-foreground"
                                >
                                    {t('privacy.admin.notice_count', {
                                        count: formatNumber(length, 0),
                                        max: formatNumber(notice.max_length, 0),
                                    })}
                                </p>
                                <InputError
                                    id={`${id}-notice-error`}
                                    message={errors.notice}
                                />
                            </div>

                            <div className="grid content-start gap-2">
                                <h3
                                    id={`${id}-preview`}
                                    className="text-sm font-medium"
                                >
                                    {t('privacy.admin.preview')}
                                </h3>
                                <div
                                    className="max-h-[36rem] overflow-y-auto rounded-md border bg-background p-4"
                                    data-test="privacy-preview"
                                    tabIndex={0}
                                    aria-labelledby={`${id}-preview`}
                                    role="region"
                                >
                                    <SafeMarkdown
                                        source={form.data.notice}
                                        headingLevel={3}
                                    />
                                </div>
                            </div>
                        </div>

                        {changedText ? (
                            <p
                                role="status"
                                className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2 text-sm text-foreground"
                            >
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-warning"
                                />
                                {t('privacy.admin.new_version_warning', {
                                    version: notice.version + 1,
                                })}
                            </p>
                        ) : null}
                    </Section>

                    <Section
                        title={t('privacy.admin.retention_title')}
                        description={t('privacy.admin.retention_description')}
                    >
                        <RetentionFields
                            fields={retention}
                            values={form.data.retention}
                            errors={errors}
                            onChange={(key, value) =>
                                form.setData('retention', {
                                    ...form.data.retention,
                                    [key]: value,
                                })
                            }
                        />
                    </Section>

                    <Section
                        title={t('privacy.admin.exports_title')}
                        description={t('privacy.admin.exports_description')}
                    >
                        <NumberField
                            id={`${id}-export-days`}
                            label={t('privacy.admin.export_days')}
                            help={t('privacy.admin.export_days_help', {
                                min: limits.export_days.min,
                                max: limits.export_days.max,
                            })}
                            unit={t('privacy.admin.days_suffix')}
                            value={form.data.personal_data_export_days}
                            min={limits.export_days.min}
                            max={limits.export_days.max}
                            error={errors.personal_data_export_days}
                            onChange={(value) =>
                                form.setData('personal_data_export_days', value)
                            }
                        />
                    </Section>

                    <Section
                        title={t('privacy.admin.storage_title')}
                        description={t('privacy.admin.storage_description')}
                    >
                        <div className="grid gap-5 sm:grid-cols-2">
                            <NumberField
                                id={`${id}-disk`}
                                label={t('privacy.admin.disk_percent')}
                                help={t('privacy.admin.disk_percent_help', {
                                    min: limits.disk_percent.min,
                                    max: limits.disk_percent.max,
                                })}
                                unit="%"
                                value={form.data.disk_warning_percent}
                                min={limits.disk_percent.min}
                                max={limits.disk_percent.max}
                                error={errors.disk_warning_percent}
                                onChange={(value) =>
                                    form.setData('disk_warning_percent', value)
                                }
                            />
                            <NumberField
                                id={`${id}-attachments`}
                                label={t('privacy.admin.attachments_gb')}
                                help={t('privacy.admin.attachments_gb_help')}
                                unit="GB"
                                optional={t('admin.form.optional')}
                                value={form.data.attachments_warning_gb}
                                min={limits.attachments_gb.min}
                                max={limits.attachments_gb.max}
                                error={errors.attachments_warning_gb}
                                onChange={(value) =>
                                    form.setData(
                                        'attachments_warning_gb',
                                        value,
                                    )
                                }
                            />
                        </div>
                    </Section>

                    {/* La confirmación llega como aviso (toast) desde el servidor. */}
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {t('privacy.admin.save')}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

AdminPrivacy.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('privacy.admin.title'), href: edit() },
    ],
};
